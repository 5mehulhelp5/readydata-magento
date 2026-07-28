<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Amasty;

use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\Module\Manager as ModuleManager;
use ReadyData\Import\Api\Data\AmastyAttributeSettingsInterface;
use ReadyData\Import\Api\Data\AmastyOptionSettingInterface;
use ReadyData\Import\Api\Data\AttributeDefinitionInterface;
use ReadyData\Import\Model\ResourceModel\AmastyAttribute as AmastyAttributeResource;
use ReadyData\Import\Model\ResourceModel\AttributeOption;

/**
 * Applies the Amasty layered-navigation properties carried on an attribute
 * definition, translating the caller's friendly fields into the real Amasty
 * column names and persisting only what the installed Amasty version supports.
 *
 * Three independent concerns, each individually guarded so a store with only
 * some Amasty modules still gets what it can:
 *  - filter settings      -> ILN per-attribute row (table present?)
 *  - brand designation    -> Amasty_ShopbyBrand config (module enabled?)
 *  - per-option brand data -> option-setting rows (table present?)
 *
 * Nothing here is fatal: a missing module/table/column becomes a collected
 * message, never an exception, so the base attribute sync is unaffected.
 */
class AmastyAttributeWriter
{
    private const BRAND_MODULE = 'Amasty_ShopbyBrand';
    private const BRAND_ATTRIBUTE_CONFIG_PATH = 'amshopby_brand/general/attribute_code';

    public function __construct(
        private readonly AmastyAttributeResource $resource,
        private readonly AttributeOption $attributeOption,
        private readonly ModuleManager $moduleManager,
        private readonly ConfigWriter $configWriter
    ) {
    }

    /**
     * @param string[] $messages collected per-attribute warnings, by reference
     * @return bool whether anything Amasty-related was actually written
     */
    public function apply(
        AttributeDefinitionInterface $definition,
        int $attributeId,
        array &$messages
    ): bool {
        $amasty = $definition->getAmasty();
        if ($amasty === null) {
            return false;
        }

        $changed = false;
        $changed = $this->applyFilter($amasty, $definition->getAttributeCode(), $attributeId, $messages) || $changed;
        $changed = $this->applyBrand($amasty, $definition->getAttributeCode(), $messages) || $changed;
        $changed = $this->applyOptionSettings($amasty, $definition->getAttributeCode(), $attributeId, $messages)
            || $changed;

        return $changed;
    }

    private function applyFilter(
        AmastyAttributeSettingsInterface $amasty,
        string $attributeCode,
        int $attributeId,
        array &$messages
    ): bool {
        // Keys are real amasty_amshopby_filter_setting columns. display_mode is
        // Amasty's numeric enum (0 Labels, 1 Dropdown, 2 Slider, 3 From-To,
        // 4 Images, 5 Images+Labels, 6 Text swatch); the URL alias column is
        // attribute_url_alias.
        $values = $this->compact([
            'display_mode' => $amasty->getDisplayMode(),
            'is_multiselect' => $amasty->getIsMultiselect(),
            'attribute_url_alias' => $amasty->getUrlAlias(),
            'is_expanded' => $amasty->getIsExpanded(),
            'tooltip' => $amasty->getTooltip(),
            'slider_step' => $amasty->getSliderStep(),
        ]);
        // Version-specific columns supplied verbatim by the caller.
        $values += $amasty->getFilterExtra() ?? [];

        if ($values === []) {
            return false;
        }
        if (!$this->resource->hasFilterTable()) {
            $messages[] = 'Amasty layered-navigation table not found; filter settings skipped.';
            return false;
        }

        $dropped = [];
        $changed = $this->resource->upsertFilter($attributeCode, $attributeId, $values, $dropped);
        if ($dropped !== []) {
            $messages[] = sprintf('Amasty filter columns not present, skipped: %s.', implode(', ', array_unique($dropped)));
        }

        return $changed;
    }

    private function applyBrand(
        AmastyAttributeSettingsInterface $amasty,
        string $attributeCode,
        array &$messages
    ): bool {
        if ($amasty->getIsBrand() !== 1) {
            return false;
        }
        if (!$this->moduleManager->isEnabled(self::BRAND_MODULE)) {
            $messages[] = 'Amasty Shop by Brand is not enabled; brand designation skipped.';
            return false;
        }

        $this->configWriter->save(self::BRAND_ATTRIBUTE_CONFIG_PATH, $attributeCode);
        $messages[] = sprintf('Designated "%s" as the Amasty brand attribute.', $attributeCode);

        return true;
    }

    private function applyOptionSettings(
        AmastyAttributeSettingsInterface $amasty,
        string $attributeCode,
        int $attributeId,
        array &$messages
    ): bool {
        $settings = $amasty->getOptionSettings();
        if (!$settings) {
            return false;
        }
        if (!$this->resource->hasOptionSettingTable()) {
            $messages[] = 'Amasty option-setting table not found; per-option brand data skipped.';
            return false;
        }

        $changed = false;
        foreach ($settings as $setting) {
            if (!$setting instanceof AmastyOptionSettingInterface) {
                continue;
            }
            $label = $setting->getOption();
            $optionId = $this->attributeOption->getOptionId($attributeId, $label);
            if ($optionId === null) {
                $messages[] = sprintf('Option "%s" not found; its brand data was skipped.', $label);
                continue;
            }

            // Keys are real amasty_amshopby_option_setting columns.
            $values = $this->compact([
                'title' => $setting->getTitle(),
                'image' => $setting->getImage(),
                'url_alias' => $setting->getUrl(),
                'description' => $setting->getDescription(),
                'meta_title' => $setting->getMetaTitle(),
                'meta_description' => $setting->getMetaDescription(),
            ]);
            $values += $setting->getExtra() ?? [];
            if ($values === []) {
                continue;
            }

            $dropped = [];
            if ($this->resource->upsertOptionSetting(
                $attributeCode,
                $optionId,
                $setting->getStoreId() ?? 0,
                $values,
                $dropped
            )) {
                $changed = true;
            }
            if ($dropped !== []) {
                $messages[] = sprintf(
                    'Amasty option columns not present for "%s", skipped: %s.',
                    $label,
                    implode(', ', array_unique($dropped))
                );
            }
        }

        return $changed;
    }

    /**
     * Drop null values; keep explicit 0/'' the caller chose to send.
     *
     * @param array<string, string|int|null> $values
     * @return array<string, string|int>
     */
    private function compact(array $values): array
    {
        return array_filter($values, static fn ($value): bool => $value !== null);
    }
}
