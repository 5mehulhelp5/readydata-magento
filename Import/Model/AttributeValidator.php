<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use ReadyData\Import\Api\Data\AttributeDefinitionInterface;
use ReadyData\Import\Api\Data\AttributeSyncResultInterface;
use ReadyData\Import\Model\Exception\AttributeValidationException;

/**
 * Minimal, fail-fast validation of an incoming (already-Magento-shaped)
 * attribute definition. It does NOT re-validate the caller's business mapping;
 * it only guards the module's own contracts so a broken definition is rejected
 * at sync time with a clear reason instead of surfacing later as a runtime
 * fatal or silent value corruption.
 *
 * On success it returns the resolved backend_type; on failure it throws an
 * AttributeValidationException carrying a reason code.
 */
class AttributeValidator
{
    /**
     * Supported frontend inputs mapped to their storage (backend) type. This
     * is also the whitelist of inputs the module can create; anything else is
     * rejected as unsupported.
     */
    private const INPUT_BACKEND_TYPE = [
        'text' => 'varchar',
        'textarea' => 'text',
        'texteditor' => 'text',
        'select' => 'int',
        'boolean' => 'int',
        'multiselect' => 'text',
        'date' => 'datetime',
        'datetime' => 'datetime',
        'price' => 'decimal',
        'weight' => 'decimal',
    ];

    /**
     * Storage types backed by a catalog_product_entity_<type> value table.
     * A definition must resolve to one of these (never "static").
     */
    private const VALUE_TABLE_TYPES = ['varchar', 'int', 'decimal', 'text', 'datetime'];

    private const CODE_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const CODE_MAX_LENGTH = 60;

    /**
     * Validate the definition and return the resolved backend_type.
     *
     * Fields in REQUIRED_ON_CREATE must be supplied to create a new attribute
     * ($existing is null); when updating an existing one ($existing is its
     * current column map) they are optional and an omitted field means "leave
     * the stored value as-is". For frontend_input specifically, an omitted
     * input on update resolves the backend_type from the existing row (or from
     * an explicitly supplied backend_type) instead of deriving it from input.
     *
     * @param array<string, int|string|null>|null $existing current column map, or null when the attribute is new
     * @throws AttributeValidationException
     */
    public function resolveBackendType(AttributeDefinitionInterface $definition, ?array $existing = null): string
    {
        $this->assertValidCode($definition->getAttributeCode());
        $this->assertModelClassesExist($definition);

        if ($existing === null) {
            $this->assertRequiredForCreate($definition);
        }

        $input = $definition->getFrontendInput();

        // Only reachable on update: create already asserted a non-empty input.
        // An omitted input keeps the stored shape, so resolve against $existing.
        if ($input === '') {
            $suppliedBackendType = $definition->getBackendType();
            if ($suppliedBackendType !== null && $suppliedBackendType !== '') {
                return $this->assertValueTableType($suppliedBackendType);
            }

            return (string)$existing['backend_type'];
        }

        if (!isset(self::INPUT_BACKEND_TYPE[$input])) {
            throw new AttributeValidationException(
                AttributeSyncResultInterface::REASON_UNSUPPORTED_TYPE,
                __('Frontend input "%1" is not supported by attribute auto-creation.', $input)
            );
        }

        return $this->resolveType($input, $definition->getBackendType());
    }

    /**
     * Assert every field that is mandatory to create an attribute is present.
     * A field counts as present when its value is neither null nor an empty
     * string (0 is a valid, present flag value). Extend the map returned by
     * requiredOnCreate() as the create contract grows.
     *
     * @throws AttributeValidationException
     */
    private function assertRequiredForCreate(AttributeDefinitionInterface $definition): void
    {
        foreach ($this->requiredOnCreate($definition) as $field => $value) {
            if ($value === null || $value === '') {
                throw new AttributeValidationException(
                    AttributeSyncResultInterface::REASON_INVALID_DEFINITION,
                    __(
                        'Field "%1" is required to create attribute "%2".',
                        $field,
                        $definition->getAttributeCode()
                    )
                );
            }
        }
    }

    /**
     * Field name => supplied value for every field mandatory on create.
     * Optional on update, so this is only consulted when creating.
     *
     * @return array<string, int|string|null>
     */
    private function requiredOnCreate(AttributeDefinitionInterface $definition): array
    {
        return [
            AttributeDefinitionInterface::FRONTEND_INPUT => $definition->getFrontendInput(),
        ];
    }

    private function assertValidCode(string $code): void
    {
        if ($code === '' || strlen($code) > self::CODE_MAX_LENGTH || !preg_match(self::CODE_PATTERN, $code)) {
            throw new AttributeValidationException(
                AttributeSyncResultInterface::REASON_INVALID_DEFINITION,
                __(
                    'Attribute code "%1" is invalid: must match [a-z][a-z0-9_]* and be at most %2 characters.',
                    $code,
                    self::CODE_MAX_LENGTH
                )
            );
        }
    }

    /**
     * Resolve the storage type: multiselect is always "text"; a supplied
     * backend_type must be a real value-table type; otherwise derive from the
     * frontend input.
     */
    private function resolveType(string $input, ?string $suppliedBackendType): string
    {
        // multiselect values are comma-joined strings; varchar truncates them.
        if ($input === 'multiselect') {
            return 'text';
        }

        if ($suppliedBackendType !== null && $suppliedBackendType !== '') {
            return $this->assertValueTableType($suppliedBackendType);
        }

        return self::INPUT_BACKEND_TYPE[$input];
    }

    /**
     * @throws AttributeValidationException when the type has no product value table
     */
    private function assertValueTableType(string $backendType): string
    {
        if (!in_array($backendType, self::VALUE_TABLE_TYPES, true)) {
            throw new AttributeValidationException(
                AttributeSyncResultInterface::REASON_INVALID_DEFINITION,
                __(
                    'Backend type "%1" is not a product value-table type (%2).',
                    $backendType,
                    implode(', ', self::VALUE_TABLE_TYPES)
                )
            );
        }

        return $backendType;
    }

    private function assertModelClassesExist(AttributeDefinitionInterface $definition): void
    {
        foreach (['backend_model' => $definition->getBackendModel(), 'source_model' => $definition->getSourceModel()] as $label => $class) {
            if ($class !== null && $class !== '' && !class_exists($class)) {
                throw new AttributeValidationException(
                    AttributeSyncResultInterface::REASON_INVALID_DEFINITION,
                    __('%1 "%2" does not exist on this instance.', $label, $class)
                );
            }
        }
    }
}
