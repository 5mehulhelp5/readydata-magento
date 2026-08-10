<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use ReadyData\Import\Api\Data\ImportResultInterface;
use ReadyData\Import\Api\Data\ProductInterface;
use ReadyData\Import\Api\Data\StoreResultInterface;

/**
 * Shared, mutable state of a single import batch, passed through the
 * processor pipeline. Instantiate via BatchContextFactory.
 */
class BatchContext
{
    /**
     * @var ProductInterface[] keyed by SKU
     */
    private array $products = [];

    /**
     * @var array<string, int> SKU => entity_id (link field value on EE)
     */
    private array $skuToEntityId = [];

    /**
     * @var array<string, bool> SKUs that existed before this batch
     */
    private array $existingSkus = [];

    /**
     * @var array<string, bool> SKUs excluded from further processing
     */
    private array $failedSkus = [];

    /**
     * Messages (errors and warnings) per SKU, each tagged with the store scope
     * it belongs to — null for the product as a whole, a store ID for one of
     * its scoped value sets.
     *
     * Kept structured rather than as flat strings because a scoped message
     * belongs to that scope's outcome, not to the product's: the response
     * splits them by scope ({@see getScopeMessages()}), and re-deriving the
     * scope from a prefixed string would be parsing our own output back.
     *
     * @var array<string, array<int, array{store_id: int|null, text: string}>>
     */
    private array $messages = [];

    /**
     * Store scopes a product carries BEYOND the request's own, in the order the
     * payload named them. The request's own scope is not in here — it is what
     * the product's own status describes.
     *
     * A list rather than a store-ID map, because a block whose store view could
     * not be resolved still gets a row: it has no store ID to be keyed by, and
     * dropping it would break the one-row-per-block correspondence a caller
     * matches its payload against.
     *
     * Registered when the block is read rather than when it is written, so a
     * scope whose every value was refused still reports itself (as skipped,
     * carrying the refusals) instead of vanishing from the response.
     *
     * @var array<string, array<int, array{store_id: int|null, applied: bool,
     *     reason: string|null, messages: string[]}>>
     */
    private array $scopes = [];

    /**
     * @var array<string, array<int, int>> SKU => store ID => index in $scopes
     */
    private array $scopeIndex = [];

    /**
     * @var array<string, mixed> free-form state shared between processors
     */
    private array $data = [];

    /**
     * @param ProductInterface[] $products
     * @param int $storeId target store scope for store-scoped values (0 = global)
     * @param int|null $rootCategoryId root every category path is pinned to,
     *        null to let the first segment's name pick one
     */
    public function __construct(
        array $products = [],
        private readonly int $storeId = 0,
        private readonly ?int $rootCategoryId = null
    ) {
        foreach ($products as $product) {
            $this->products[$product->getSku()] = $product;
        }
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * The root category every `categories` path in this batch resolves under,
     * or null when the first segment's name picks one on its own.
     */
    public function getRootCategoryId(): ?int
    {
        return $this->rootCategoryId;
    }

    /**
     * All products in the batch, including failed ones.
     *
     * @return ProductInterface[] keyed by SKU
     */
    public function getAllProducts(): array
    {
        return $this->products;
    }

    /**
     * Products still eligible for processing (not failed).
     *
     * @return ProductInterface[] keyed by SKU
     */
    public function getValidProducts(): array
    {
        return array_diff_key($this->products, $this->failedSkus);
    }

    public function getProduct(string|int $sku): ?ProductInterface
    {
        return $this->products[$sku] ?? null;
    }

    /**
     * @return string[]
     */
    public function getSkus(): array
    {
        return array_keys($this->products);
    }

    public function setEntityId(string|int $sku, int $entityId): void
    {
        $this->skuToEntityId[$sku] = $entityId;
    }

    public function getEntityId(string|int $sku): ?int
    {
        return $this->skuToEntityId[$sku] ?? null;
    }

    /**
     * @return array<string, int> SKU => entity_id for all resolved products
     */
    public function getSkuToEntityIdMap(): array
    {
        return $this->skuToEntityId;
    }

    /**
     * @return int[] entity IDs of all valid, resolved products
     */
    public function getValidEntityIds(): array
    {
        return array_values(array_intersect_key(
            $this->skuToEntityId,
            $this->getValidProducts()
        ));
    }

    public function markExisting(string|int $sku): void
    {
        $this->existingSkus[$sku] = true;
    }

    public function isExisting(string|int $sku): bool
    {
        return isset($this->existingSkus[$sku]);
    }

    /**
     * Exclude a product from further processing and record the reason.
     */
    public function fail(string|int $sku, string $message): void
    {
        $this->failedSkus[$sku] = true;
        $this->addMessage($sku, $message);
    }

    /**
     * Fail every product in the batch (e.g. transaction rollback).
     */
    public function failAll(string $message): void
    {
        foreach (array_keys($this->products) as $sku) {
            $this->fail($sku, $message);
        }
    }

    public function isFailed(string|int $sku): bool
    {
        return isset($this->failedSkus[$sku]);
    }

    /**
     * Record a non-fatal message (warning) for a product, optionally against
     * one of its store scopes.
     */
    public function addMessage(string|int $sku, string $message, ?int $storeId = null): void
    {
        $this->messages[$sku][] = ['store_id' => $storeId, 'text' => $message];
    }

    /**
     * Every message for a product across all its scopes, scoped ones prefixed
     * with the store they belong to. The combined view — the response reports
     * per scope instead ({@see getScopeMessages()}), so this is for callers
     * that want the lot in one list, and for reading a context back in tests.
     *
     * @return string[]
     */
    public function getMessages(string|int $sku): array
    {
        return array_map(
            static fn (array $message): string => self::render($message),
            $this->messages[$sku] ?? []
        );
    }

    /**
     * Messages recorded against one store scope, untagged. `null` selects the
     * product's own messages.
     *
     * The product's list is the exact COMPLEMENT of the per-scope lists, not
     * just the untagged messages: a message tagged to a scope that has no
     * result row to carry it — the request's own scope, which a `store_values`
     * block may name and be merged into — is reported here, prefixed, rather
     * than nowhere. The response reads these two views and nothing else, so a
     * recorded message has to survive in one of them whatever tagged it.
     *
     * @return string[]
     */
    public function getScopeMessages(string|int $sku, ?int $storeId): array
    {
        $messages = [];
        foreach ($this->messages[$sku] ?? [] as $message) {
            if ($storeId !== null) {
                if ($message['store_id'] === $storeId) {
                    $messages[] = $message['text'];
                }
                continue;
            }
            if ($message['store_id'] === null || !$this->hasScope($sku, $message['store_id'])) {
                $messages[] = self::render($message);
            }
        }

        return $messages;
    }

    /**
     * Note that the product carries values for this store scope, before
     * anything has been written in it.
     */
    public function registerScope(string|int $sku, int $storeId): void
    {
        if (isset($this->scopeIndex[$sku][$storeId])) {
            return;
        }

        $this->scopes[$sku][] = [
            'store_id' => $storeId,
            'applied' => false,
            'reason' => null,
            'messages' => [],
        ];
        $this->scopeIndex[$sku][$storeId] = array_key_last($this->scopes[$sku]);
    }

    /**
     * Note a block whose store view could not be resolved. It reports as a
     * skipped scope with no store ID: the payload named a scope, so it gets a
     * row, but there is no store view to attribute it to — and 0 would name the
     * default scope, the one scope this list never covers.
     *
     * The message travels on the row because it cannot be tagged: tags are store
     * IDs, and this block has none.
     */
    public function registerUnresolvedScope(string|int $sku, string $reason, string $message): void
    {
        $this->scopes[$sku][] = [
            'store_id' => null,
            'applied' => false,
            'reason' => $reason,
            'messages' => [$message],
        ];
    }

    /**
     * Note that something was actually written or cleared in a registered
     * scope. A clear counts: the scope was applied, it just applied a removal.
     */
    public function markScopeApplied(string|int $sku, int $storeId): void
    {
        $index = $this->scopeIndex[$sku][$storeId] ?? null;
        if ($index !== null) {
            $this->scopes[$sku][$index]['applied'] = true;
        }
    }

    /**
     * One row per scope the product's payload named beyond the request's own, in
     * payload order, ready for {@see StoreResultInterface}.
     *
     * A failed product fails every one of its scopes: the batch is one
     * transaction, so nothing it wrote survives, in any scope.
     *
     * @return array<int, array{store_id: int|null, status: string, reason: string|null,
     *     messages: string[]}>
     */
    public function getScopeResults(string|int $sku): array
    {
        $failed = $this->isFailed($sku);

        return array_map(
            function (array $scope) use ($sku, $failed): array {
                $storeId = $scope['store_id'];

                return [
                    'store_id' => $storeId,
                    'status' => match (true) {
                        $failed => StoreResultInterface::STATUS_ERROR,
                        $scope['applied'] => StoreResultInterface::STATUS_UPDATED,
                        default => StoreResultInterface::STATUS_SKIPPED,
                    },
                    'reason' => $scope['reason'],
                    'messages' => $storeId === null
                        ? $scope['messages']
                        : $this->getScopeMessages($sku, $storeId),
                ];
            },
            $this->scopes[$sku] ?? []
        );
    }

    /**
     * Share arbitrary state with downstream processors
     * (e.g. EE row_id link values, generated url_keys).
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Final status for a product in ImportResultInterface terms.
     */
    public function getStatus(string|int $sku): string
    {
        if ($this->isFailed($sku)) {
            return ImportResultInterface::STATUS_ERROR;
        }

        return $this->isExisting($sku)
            ? ImportResultInterface::STATUS_UPDATED
            : ImportResultInterface::STATUS_CREATED;
    }

    /**
     * Whether a store scope of this product has a result row of its own.
     */
    private function hasScope(string|int $sku, int $storeId): bool
    {
        return isset($this->scopeIndex[$sku][$storeId]);
    }

    /**
     * One message as a caller reads it: scope-tagged ones say which store view
     * they came from, so they cannot be mistaken for the product's own.
     *
     * @param array{store_id: int|null, text: string} $message
     */
    private static function render(array $message): string
    {
        return $message['store_id'] === null
            ? $message['text']
            : sprintf('[store %d] %s', $message['store_id'], $message['text']);
    }
}
