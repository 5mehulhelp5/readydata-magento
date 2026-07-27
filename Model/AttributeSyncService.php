<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use ReadyData\Import\Api\Data\AttributeDefinitionInterface;
use ReadyData\Import\Api\Data\AttributeSyncResponseInterface;
use ReadyData\Import\Api\Data\AttributeSyncResponseInterfaceFactory;
use ReadyData\Import\Api\Data\AttributeSyncResultInterface;
use ReadyData\Import\Api\Data\AttributeSyncResultInterfaceFactory;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Exception\AttributeValidationException;
use ReadyData\Import\Model\Indexer\AttributeInvalidationHandler;
use ReadyData\Import\Model\ResourceModel\AttributeDefinition as AttributeDefinitionResource;
use ReadyData\Import\Model\ResourceModel\AttributeOption;

/**
 * Creates/updates product attribute definitions to match the caller (the
 * system of record). Standalone: no product import required. Writes go through
 * EavSetup so the EAV cache, flat columns, groups and labels stay correct;
 * reads (existing shape, set membership) use a side-effect-free resource model.
 *
 * Behaviour is create-or-update-to-source. Structural columns
 * (backend_type/frontend_input/is_global) are create-only: a difference is
 * reported for a deliberate migration, never applied.
 */
class AttributeSyncService
{
    private const LOCK_NAME = 'readydata_attribute_sync';
    private const LOCK_TIMEOUT_SEC = 10;

    private const OPTION_INPUTS = ['select', 'multiselect'];

    /**
     * scope value => catalog_eav_attribute.is_global.
     */
    private const SCOPE_TO_IS_GLOBAL = [
        AttributeDefinitionInterface::SCOPE_STORE => 0,
        AttributeDefinitionInterface::SCOPE_GLOBAL => 1,
        AttributeDefinitionInterface::SCOPE_WEBSITE => 2,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly LockManagerInterface $lockManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly AttributeValidator $validator,
        private readonly AttributeMetadataCache $metadataCache,
        private readonly AttributeDefinitionResource $resource,
        private readonly AttributeOption $attributeOption,
        private readonly AttributeInvalidationHandler $invalidationHandler,
        private readonly AttributeSyncResponseInterfaceFactory $responseFactory,
        private readonly AttributeSyncResultInterfaceFactory $resultFactory,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param AttributeDefinitionInterface[] $attributes
     * @throws LocalizedException
     */
    public function sync(array $attributes): AttributeSyncResponseInterface
    {
        $startedAt = hrtime(true);
        $received = count($attributes);
        $attributes = $this->dedupe($attributes);

        if (!$attributes) {
            throw new LocalizedException(__('The request contains no attribute definitions.'));
        }

        if (!$this->config->isAutoCreateAttributes()) {
            return $this->buildResponse($received, $this->disabledResults($attributes), $startedAt);
        }

        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SEC)) {
            throw new LocalizedException(__('Another attribute sync is already running. Try again later.'));
        }

        $results = [];
        $changed = false;
        try {
            $entityTypeId = $this->metadataCache->getEntityTypeId();
            $eavSetup = $this->eavSetupFactory->create();
            $existing = $this->resource->getExistingByCodes(
                $entityTypeId,
                array_map(static fn (AttributeDefinitionInterface $d): string => $d->getAttributeCode(), $attributes)
            );

            $connection = $this->resourceConnection->getConnection();
            foreach ($attributes as $definition) {
                $code = $definition->getAttributeCode();
                // One transaction per attribute so a failure leaves no half-written
                // definition (attribute created but unplaced, options half-seeded).
                $connection->beginTransaction();
                try {
                    $result = $this->processOne(
                        $eavSetup,
                        $entityTypeId,
                        $definition,
                        $existing[$code] ?? null
                    );
                    $connection->commit();
                } catch (\Throwable $e) {
                    $connection->rollBack();
                    // Keep the batch alive: one failing attribute is reported as
                    // an error result, not an aborted request. Prior successes are
                    // still returned and their changes still get invalidated below.
                    $this->logger->error(sprintf('Attribute "%s" sync failed: %s', $code, $e->getMessage()));
                    $result = $this->result(
                        $code,
                        AttributeSyncResultInterface::STATUS_ERROR,
                        null,
                        [sprintf('Sync failed: %s', $e->getMessage())]
                    );
                }
                if (in_array(
                    $result->getStatus(),
                    [AttributeSyncResultInterface::STATUS_CREATED, AttributeSyncResultInterface::STATUS_UPDATED],
                    true
                )) {
                    $changed = true;
                }
                $results[] = $result;
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }

        $this->invalidationHandler->execute($changed);

        $response = $this->buildResponse($received, $results, $startedAt);
        $this->logger->info(sprintf(
            'Attribute sync finished: %d received, %d created, %d updated, %d unchanged, %d skipped, %d failed in %d ms',
            $response->getReceived(),
            $response->getCreated(),
            $response->getUpdated(),
            $response->getUnchanged(),
            $response->getSkipped(),
            $response->getFailed(),
            $response->getElapsedMs()
        ));

        return $response;
    }

    /**
     * @param array<string, int|string|null>|null $existing current column map, or null when the attribute is new
     */
    private function processOne(
        EavSetup $eavSetup,
        int $entityTypeId,
        AttributeDefinitionInterface $definition,
        ?array $existing
    ): AttributeSyncResultInterface {
        $code = $definition->getAttributeCode();

        try {
            $backendType = $this->validator->resolveBackendType($definition, $existing);
            $isGlobal = $this->resolveScope($definition);
        } catch (AttributeValidationException $e) {
            return $this->result($code, AttributeSyncResultInterface::STATUS_SKIPPED, $e->getReason(), [$e->getMessage()]);
        }

        if ($existing === null) {
            try {
                $eavSetup->addAttribute(Product::ENTITY, $code, $this->buildCreateData($definition, $backendType, $isGlobal));
                $attributeId = (int)$eavSetup->getAttributeId($entityTypeId, $code);
                $messages = [];
                $this->applyExtras($eavSetup, $entityTypeId, $definition, $attributeId, $messages);

                return $this->result($code, AttributeSyncResultInterface::STATUS_CREATED, null, $messages);
            } catch (\Throwable $e) {
                // A concurrent sync may have created it between our read and this write.
                $existing = $this->resource->getExistingByCodes($entityTypeId, [$code])[$code] ?? null;
                if ($existing === null) {
                    $this->logger->error(sprintf('Attribute "%s" creation failed: %s', $code, $e->getMessage()));

                    return $this->result(
                        $code,
                        AttributeSyncResultInterface::STATUS_ERROR,
                        null,
                        [sprintf('Creation failed: %s', $e->getMessage())]
                    );
                }
                // Fall through to the update path against the row that now exists.
            }
        }

        $structuralDiff = $this->structuralDiff($definition, $backendType, $isGlobal, $existing);
        if ($structuralDiff !== []) {
            return $this->result(
                $code,
                AttributeSyncResultInterface::STATUS_SKIPPED,
                AttributeSyncResultInterface::REASON_STRUCTURAL_CHANGE_REQUIRED,
                [$this->describeStructuralDiff($structuralDiff)]
            );
        }

        $attributeId = (int)$existing['attribute_id'];
        $messages = [];
        $safeDiff = $this->safeColumnDiff($definition, $existing);
        $extrasChanged = $this->applyExtras($eavSetup, $entityTypeId, $definition, $attributeId, $messages);

        if ($safeDiff !== []) {
            $eavSetup->updateAttribute($entityTypeId, $attributeId, $safeDiff);
        }

        $status = ($safeDiff !== [] || $extrasChanged)
            ? AttributeSyncResultInterface::STATUS_UPDATED
            : AttributeSyncResultInterface::STATUS_UNCHANGED;

        return $this->result($code, $status, null, $messages);
    }

    /**
     * @throws AttributeValidationException when an unknown scope is supplied
     */
    private function resolveScope(AttributeDefinitionInterface $definition): ?int
    {
        $scope = $definition->getScope();
        if ($scope === null || $scope === '') {
            return null;
        }
        if (!isset(self::SCOPE_TO_IS_GLOBAL[$scope])) {
            throw new AttributeValidationException(
                AttributeSyncResultInterface::REASON_INVALID_DEFINITION,
                __('Scope "%1" is invalid; use store, website or global.', $scope)
            );
        }

        return self::SCOPE_TO_IS_GLOBAL[$scope];
    }

    /**
     * Friendly-key data for EavSetup::addAttribute (only set keys present in the
     * payload, so Magento's own column defaults fill the rest). No "group" key
     * is passed: placement is applied explicitly and additively below.
     *
     * @return array<string, mixed>
     */
    private function buildCreateData(AttributeDefinitionInterface $definition, string $backendType, ?int $isGlobal): array
    {
        $data = [
            'type' => $backendType,
            'input' => $definition->getFrontendInput(),
            'user_defined' => $definition->getIsUserDefined() ?? 1,
        ];

        $map = [
            'label' => $definition->getFrontendLabel(),
            'backend' => $definition->getBackendModel(),
            'source' => $definition->getSourceModel(),
            'frontend_class' => $definition->getFrontendClass(),
            'required' => $definition->getIsRequired(),
            'unique' => $definition->getIsUnique(),
            'default' => $definition->getDefaultValue(),
            'note' => $definition->getNote(),
            'global' => $isGlobal,
            'searchable' => $definition->getIsSearchable(),
            'filterable' => $definition->getIsFilterable(),
            'filterable_in_search' => $definition->getIsFilterableInSearch(),
            'comparable' => $definition->getIsComparable(),
            'visible_on_front' => $definition->getIsVisibleOnFront(),
            'is_html_allowed_on_front' => $definition->getIsHtmlAllowedOnFront(),
            'wysiwyg_enabled' => $definition->getIsWysiwygEnabled(),
            'used_in_product_listing' => $definition->getUsedInProductListing(),
            'used_for_sort_by' => $definition->getUsedForSortBy(),
            'is_visible_in_grid' => $definition->getIsVisibleInGrid(),
            'is_filterable_in_grid' => $definition->getIsFilterableInGrid(),
            'is_used_in_grid' => $definition->getIsUsedInGrid(),
        ];
        foreach ($map as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        $applyTo = $definition->getApplyTo();
        if (!empty($applyTo)) {
            $data['apply_to'] = implode(',', $applyTo);
        }

        return $data;
    }

    /**
     * Structural columns that differ from the existing attribute. These are
     * never applied — a non-empty result means a migration is required.
     *
     * @param array<string, int|string|null> $existing
     * @return array<string, array{have: int|string|null, requested: int|string}>
     */
    private function structuralDiff(
        AttributeDefinitionInterface $definition,
        string $backendType,
        ?int $isGlobal,
        array $existing
    ): array {
        $diff = [];
        if ((string)$existing['backend_type'] !== $backendType) {
            $diff['backend_type'] = ['have' => $existing['backend_type'], 'requested' => $backendType];
        }
        // frontend_input is optional on update: an omitted value is not a
        // request to change the stored input, so it never counts as a diff.
        $requestedInput = $definition->getFrontendInput();
        if ($requestedInput !== '' && (string)$existing['frontend_input'] !== $requestedInput) {
            $diff['frontend_input'] = ['have' => $existing['frontend_input'], 'requested' => $requestedInput];
        }
        if ($isGlobal !== null && (int)$existing['is_global'] !== $isGlobal) {
            $diff['is_global'] = ['have' => (int)$existing['is_global'], 'requested' => $isGlobal];
        }

        return $diff;
    }

    private function describeStructuralDiff(array $diff): string
    {
        $have = [];
        $requested = [];
        foreach ($diff as $column => $values) {
            $have[] = sprintf('%s: %s', $column, (string)$values['have']);
            $requested[] = sprintf('%s: %s', $column, (string)$values['requested']);
        }

        return sprintf(
            'Structural change requires a deliberate migration and was not applied. '
            . 'have {%s} vs requested {%s}.',
            implode(', ', $have),
            implode(', ', $requested)
        );
    }

    /**
     * Safe (non-structural) columns whose desired value differs from current.
     * Keyed by real DB column name for EavSetup::updateAttribute.
     *
     * @param array<string, int|string|null> $existing
     * @return array<string, int|string>
     */
    private function safeColumnDiff(AttributeDefinitionInterface $definition, array $existing): array
    {
        $intColumns = [
            'is_required' => $definition->getIsRequired(),
            'is_unique' => $definition->getIsUnique(),
            'is_searchable' => $definition->getIsSearchable(),
            'is_filterable' => $definition->getIsFilterable(),
            'is_filterable_in_search' => $definition->getIsFilterableInSearch(),
            'is_comparable' => $definition->getIsComparable(),
            'is_visible_on_front' => $definition->getIsVisibleOnFront(),
            'is_html_allowed_on_front' => $definition->getIsHtmlAllowedOnFront(),
            'is_wysiwyg_enabled' => $definition->getIsWysiwygEnabled(),
            'used_in_product_listing' => $definition->getUsedInProductListing(),
            'used_for_sort_by' => $definition->getUsedForSortBy(),
            'is_visible_in_grid' => $definition->getIsVisibleInGrid(),
            'is_filterable_in_grid' => $definition->getIsFilterableInGrid(),
            'is_used_in_grid' => $definition->getIsUsedInGrid(),
        ];
        $stringColumns = [
            'frontend_label' => $definition->getFrontendLabel(),
            'default_value' => $definition->getDefaultValue(),
            'note' => $definition->getNote(),
        ];

        $diff = [];
        foreach ($intColumns as $column => $value) {
            if ($value !== null && (int)($existing[$column] ?? 0) !== $value) {
                $diff[$column] = $value;
            }
        }
        foreach ($stringColumns as $column => $value) {
            if ($value !== null && (string)($existing[$column] ?? '') !== $value) {
                $diff[$column] = $value;
            }
        }

        return $diff;
    }

    /**
     * Apply additive attribute-set/group placement and seed option values.
     * Returns true when anything was actually added/created.
     *
     * @param string[] $messages collected per-attribute warnings, by reference
     */
    private function applyExtras(
        EavSetup $eavSetup,
        int $entityTypeId,
        AttributeDefinitionInterface $definition,
        int $attributeId,
        array &$messages
    ): bool {
        $changed = false;

        foreach ($definition->getPlacements() ?: [null] as $placement) {
            $setName = $placement?->getSet();
            $setId = $this->metadataCache->resolveAttributeSetId($setName);
            if ($setId === null) {
                $messages[] = sprintf('Attribute set "%s" not found; placement skipped.', (string)$setName);
                continue;
            }
            // Leave an existing membership where the merchant put it (no group move).
            if ($this->resource->isAttributeInSet($attributeId, $setId)) {
                continue;
            }

            $groupName = $placement?->getGroup();
            if ($groupName !== null && $groupName !== '') {
                $eavSetup->addAttributeGroup($entityTypeId, $setId, $groupName);
                $groupIdentifier = $groupName;
            } else {
                $groupIdentifier = (int)$eavSetup->getDefaultAttributeGroupId($entityTypeId, $setId);
            }

            $eavSetup->addAttributeToSet(
                $entityTypeId,
                $setId,
                $groupIdentifier,
                $definition->getAttributeCode(),
                $placement?->getSortOrder()
            );
            $changed = true;
        }

        $options = $definition->getOptions();
        if ($options && in_array($definition->getFrontendInput(), self::OPTION_INPUTS, true)) {
            if ($this->attributeOption->createOptions($attributeId, $options) !== []) {
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param AttributeDefinitionInterface[] $attributes
     * @return AttributeDefinitionInterface[]
     */
    private function dedupe(array $attributes): array
    {
        $byCode = [];
        foreach ($attributes as $definition) {
            $code = trim($definition->getAttributeCode());
            if ($code === '') {
                continue;
            }
            $definition->setAttributeCode($code);
            $byCode[$code] = $definition;
        }

        return array_values($byCode);
    }

    /**
     * @param AttributeDefinitionInterface[] $attributes
     * @return AttributeSyncResultInterface[]
     */
    private function disabledResults(array $attributes): array
    {
        return array_map(
            fn (AttributeDefinitionInterface $d): AttributeSyncResultInterface => $this->result(
                $d->getAttributeCode(),
                AttributeSyncResultInterface::STATUS_SKIPPED,
                AttributeSyncResultInterface::REASON_DISABLED,
                ['Attribute auto-creation is disabled in configuration.']
            ),
            $attributes
        );
    }

    /**
     * @param string[] $messages
     */
    private function result(string $code, string $status, ?string $reason, array $messages): AttributeSyncResultInterface
    {
        /** @var AttributeSyncResultInterface $result */
        $result = $this->resultFactory->create();

        return $result->setAttributeCode($code)
            ->setStatus($status)
            ->setReason($reason)
            ->setMessages($messages);
    }

    /**
     * @param AttributeSyncResultInterface[] $results
     */
    private function buildResponse(int $received, array $results, int $startedAt): AttributeSyncResponseInterface
    {
        $created = $updated = $unchanged = $skipped = $failed = 0;
        foreach ($results as $result) {
            match ($result->getStatus()) {
                AttributeSyncResultInterface::STATUS_CREATED => $created++,
                AttributeSyncResultInterface::STATUS_UPDATED => $updated++,
                AttributeSyncResultInterface::STATUS_UNCHANGED => $unchanged++,
                AttributeSyncResultInterface::STATUS_SKIPPED => $skipped++,
                default => $failed++,
            };
        }

        /** @var AttributeSyncResponseInterface $response */
        $response = $this->responseFactory->create();

        return $response->setReceived($received)
            ->setCreated($created)
            ->setUpdated($updated)
            ->setUnchanged($unchanged)
            ->setSkipped($skipped)
            ->setFailed($failed)
            ->setElapsedMs((int)((hrtime(true) - $startedAt) / 1_000_000))
            ->setResults($results);
    }
}
