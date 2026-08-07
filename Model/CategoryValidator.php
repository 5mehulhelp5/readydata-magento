<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use ReadyData\Import\Api\Data\CategoryDefinitionInterface;
use ReadyData\Import\Api\Data\CategorySyncResultInterface;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\Exception\CategoryValidationException;

/**
 * Fail-fast shape validation of one category entry, before any tree lookup or
 * write. Everything that can be judged from the payload alone lives here;
 * anything needing the database (does the parent exist, is the name ambiguous)
 * is the service's job.
 */
class CategoryValidator
{
    /**
     * Attributes core owns. Accepting them through custom_attributes would let
     * a caller corrupt the tree (path/level/children_count) or fight the
     * url_path generator, so they are rejected loudly rather than dropped.
     */
    public const UNSUPPORTED_ATTRIBUTES = [
        'entity_id',
        'attribute_set_id',
        'parent_id',
        'path',
        'level',
        'position',
        'children_count',
        'children',
        'all_children',
        'path_in_store',
        'url_path',
        'image',
        'created_at',
        'updated_at',
        'row_id',
        'created_in',
        'updated_in',
    ];

    /**
     * Attributes that may never be cleared. Clearing a required attribute makes
     * every later save of that category fail validation — the category becomes
     * permanently unwritable — and clearing name/url_key strands the category's
     * URL rewrites and its descendants' url_path values with nothing to repair
     * them.
     */
    public const PROTECTED_FROM_CLEARING = [
        'name',
        'url_key',
        'url_path',
        'is_active',
        'include_in_menu',
        'is_anchor',
    ];

    public function __construct(
        private readonly PathParser $pathParser
    ) {
    }

    /**
     * @return string[] the parsed path segments (empty when identified only by category_id)
     * @throws CategoryValidationException
     */
    public function validate(CategoryDefinitionInterface $definition): array
    {
        $segments = [];
        $path = $definition->getPath();

        if ($path !== null && trim($path) !== '') {
            $parsed = $this->pathParser->parse($path);
            if ($parsed === null) {
                throw new CategoryValidationException(
                    CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                    __('Path "%1" is empty after normalization.', $path)
                );
            }
            // A digits-only entry is an ID reference in the product payload;
            // here identity by ID has its own field, so it can only be a typo.
            if ($parsed['type'] === PathParser::TYPE_ID) {
                throw new CategoryValidationException(
                    CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                    __(
                        'Path "%1" is a bare number; use the category_id field to address a category by ID,'
                        . ' or escape the segment ("\\%1") to name a category whose name is a number.',
                        $path
                    )
                );
            }
            $segments = $parsed['segments'];
        }

        $categoryId = $definition->getCategoryId();
        if ($segments === [] && ($categoryId === null || $categoryId <= 0)) {
            throw new CategoryValidationException(
                CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                __('A category needs either a path or a category_id.')
            );
        }

        $name = $definition->getName();
        if ($name !== null && trim($name) === '') {
            throw new CategoryValidationException(
                CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                __('The name cannot be empty.')
            );
        }
        if ($segments === [] && ($name === null || trim($name) === '')) {
            // A bare ID with no corroborating field is one typo away from
            // rewriting an unrelated category. The path is cross-checked
            // against the stored parent; the name is what a rename supplies.
            throw new CategoryValidationException(
                CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                __('A category addressed by category_id needs a path to cross-check it, or a name to set.')
            );
        }

        $this->assertFlag('is_active', $definition->getIsActive());
        $this->assertFlag('include_in_menu', $definition->getIncludeInMenu());
        $this->assertFlag('is_anchor', $definition->getIsAnchor());
        $this->assertFlag('delete', $definition->getDelete());
        $this->assertFlag('delete_children', $definition->getDeleteChildren());

        $this->assertCustomAttributes($definition);
        $this->assertClearAttributes($definition);
        $this->assertDelete($definition);

        return $segments;
    }

    /**
     * The parent the definition asks for, as path segments.
     *
     * Separate from {@see validate()} rather than folded into its return value:
     * that return is the entry's own path and the service keys everything off it,
     * and a destination is optional in a way an identity is not.
     *
     * @return string[] the parsed segments, empty when no parent_path was sent
     * @throws CategoryValidationException
     */
    public function validateParent(CategoryDefinitionInterface $definition): array
    {
        $parentPath = $definition->getParentPath();
        if ($parentPath === null || trim($parentPath) === '') {
            return [];
        }

        $parsed = $this->pathParser->parse($parentPath);
        if ($parsed === null) {
            throw new CategoryValidationException(
                CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                __('Parent path "%1" is empty after normalization.', $parentPath)
            );
        }
        if ($parsed['type'] === PathParser::TYPE_ID) {
            throw new CategoryValidationException(
                CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                __(
                    'Parent path "%1" is a bare number; use the parent_category_id field to address a parent'
                    . ' by ID, or escape the segment ("\\%1") to name a category whose name is a number.',
                    $parentPath
                )
            );
        }

        return $parsed['segments'];
    }

    /**
     * A delete says "this category should not exist". Combining it with fields
     * that describe what the category should *be* is contradictory, and the two
     * readings (delete it / update it) differ enough that guessing is worse than
     * refusing. `name` is exempt: it is the cross-check a bare category_id needs,
     * not a value to write.
     *
     * @throws CategoryValidationException
     */
    private function assertDelete(CategoryDefinitionInterface $definition): void
    {
        $isDelete = $definition->getDelete() === 1;

        if (!$isDelete) {
            if ($definition->getDeleteChildren() === 1) {
                throw new CategoryValidationException(
                    CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                    __('Field "delete_children" is only meaningful together with "delete": 1.')
                );
            }

            return;
        }

        $conflicts = [];
        foreach (
            [
                'url_key' => $definition->getUrlKey(),
                'is_active' => $definition->getIsActive(),
                'include_in_menu' => $definition->getIncludeInMenu(),
                'is_anchor' => $definition->getIsAnchor(),
                'position' => $definition->getPosition(),
                'parent_path' => $definition->getParentPath(),
                'parent_category_id' => $definition->getParentCategoryId(),
            ] as $field => $value
        ) {
            if ($value !== null) {
                $conflicts[] = $field;
            }
        }
        if ($definition->getCustomAttributes()) {
            $conflicts[] = 'custom_attributes';
        }
        if ($definition->getClearAttributes()) {
            $conflicts[] = 'clear_attributes';
        }

        if ($conflicts !== []) {
            throw new CategoryValidationException(
                CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                __(
                    'A category being deleted cannot also set values; remove %1, or drop "delete".',
                    implode(', ', $conflicts)
                )
            );
        }
    }

    /**
     * @throws CategoryValidationException
     */
    private function assertFlag(string $field, ?int $value): void
    {
        if ($value !== null && $value !== 0 && $value !== 1) {
            throw new CategoryValidationException(
                CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                __('Field "%1" must be 0 or 1, got "%2".', $field, $value)
            );
        }
    }

    /**
     * @throws CategoryValidationException
     */
    private function assertCustomAttributes(CategoryDefinitionInterface $definition): void
    {
        foreach ($definition->getCustomAttributes() ?? [] as $attribute) {
            $code = trim($attribute->getAttributeCode());
            if ($code === '') {
                throw new CategoryValidationException(
                    CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                    __('A custom attribute is missing its attribute_code.')
                );
            }
            if (in_array($code, self::UNSUPPORTED_ATTRIBUTES, true)) {
                throw new CategoryValidationException(
                    CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                    __('Attribute "%1" is maintained by Magento and cannot be set through this endpoint.', $code)
                );
            }
        }
    }

    /**
     * @throws CategoryValidationException
     */
    private function assertClearAttributes(CategoryDefinitionInterface $definition): void
    {
        foreach ($definition->getClearAttributes() ?? [] as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            if (in_array($code, self::PROTECTED_FROM_CLEARING, true)
                || in_array($code, self::UNSUPPORTED_ATTRIBUTES, true)
            ) {
                throw new CategoryValidationException(
                    CategorySyncResultInterface::REASON_PROTECTED_ATTRIBUTE,
                    __('Attribute "%1" cannot be cleared; set it to a new value instead.', $code)
                );
            }
        }
    }
}
