<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Amasty;

use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Amasty\AmastyAttributeWriter;
use ReadyData\Import\Model\Data\AmastyAttributeSettings;
use ReadyData\Import\Model\Data\AmastyOptionSetting;
use ReadyData\Import\Model\Data\AttributeDefinition;
use ReadyData\Import\Model\ResourceModel\AmastyAttribute as AmastyAttributeResource;
use ReadyData\Import\Model\ResourceModel\AttributeOption;

class AmastyAttributeWriterTest extends TestCase
{
    private const BRAND_PATH = 'amshopby_brand/general/attribute_code';

    private AmastyAttributeResource&MockObject $resource;
    private AttributeOption&MockObject $attributeOption;
    private ModuleManager&MockObject $moduleManager;
    private ConfigWriter&MockObject $configWriter;
    private AmastyAttributeWriter $writer;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(AmastyAttributeResource::class);
        $this->attributeOption = $this->createMock(AttributeOption::class);
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->configWriter = $this->createMock(ConfigWriter::class);

        $this->writer = new AmastyAttributeWriter(
            $this->resource,
            $this->attributeOption,
            $this->moduleManager,
            $this->configWriter
        );
    }

    private function definition(?AmastyAttributeSettings $amasty): AttributeDefinition
    {
        return (new AttributeDefinition())
            ->setAttributeCode('brand')
            ->setFrontendInput('select')
            ->setAmasty($amasty);
    }

    public function testNoAmastyBlockIsANoOp(): void
    {
        $this->resource->expects(self::never())->method('upsertFilter');
        $this->configWriter->expects(self::never())->method('save');

        $messages = [];
        self::assertFalse($this->writer->apply($this->definition(null), 42, $messages));
        self::assertSame([], $messages);
    }

    public function testFilterSettingsMapToRealColumns(): void
    {
        $this->resource->method('hasFilterTable')->willReturn(true);

        $captured = null;
        $this->resource->expects(self::once())->method('upsertFilter')
            ->with(
                self::equalTo('brand'),
                self::equalTo(42),
                self::callback(function (array $values) use (&$captured): bool {
                    $captured = $values;
                    return true;
                }),
                self::anything()
            )
            ->willReturn(true);

        $amasty = (new AmastyAttributeSettings())
            ->setDisplayMode(4)
            ->setIsMultiselect(1)
            ->setUrlAlias('brand')
            ->setIsExpanded(0)
            ->setTooltip('Pick a brand')
            ->setSliderStep(1)
            ->setFilterExtra(['block_position' => 2]);

        $messages = [];
        self::assertTrue($this->writer->apply($this->definition($amasty), 42, $messages));
        self::assertSame(4, $captured['display_mode']);
        self::assertSame(1, $captured['is_multiselect']);
        self::assertSame('brand', $captured['attribute_url_alias']); // not "url_alias"
        self::assertSame('Pick a brand', $captured['tooltip']);
        self::assertSame(2, $captured['block_position']); // filter_extra passthrough
        self::assertArrayNotHasKey('url_alias', $captured);
    }

    public function testFilterSkippedWithMessageWhenTableAbsent(): void
    {
        $this->resource->method('hasFilterTable')->willReturn(false);
        $this->resource->expects(self::never())->method('upsertFilter');

        $amasty = (new AmastyAttributeSettings())->setDisplayMode(1);
        $messages = [];
        $this->writer->apply($this->definition($amasty), 42, $messages);

        self::assertNotEmpty($messages);
        self::assertStringContainsString('table not found', $messages[0]);
    }

    public function testBrandDesignationWritesConfigWhenModuleEnabled(): void
    {
        $this->moduleManager->method('isEnabled')
            ->with('Amasty_ShopbyBrand')->willReturn(true);
        $this->configWriter->expects(self::once())->method('save')
            ->with(self::BRAND_PATH, 'brand');

        $amasty = (new AmastyAttributeSettings())->setIsBrand(1);
        $messages = [];
        self::assertTrue($this->writer->apply($this->definition($amasty), 42, $messages));
    }

    public function testBrandDesignationSkippedWhenModuleDisabled(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(false);
        $this->configWriter->expects(self::never())->method('save');

        $amasty = (new AmastyAttributeSettings())->setIsBrand(1);
        $messages = [];
        $this->writer->apply($this->definition($amasty), 42, $messages);

        self::assertNotEmpty($messages);
        self::assertStringContainsString('Shop by Brand is not enabled', $messages[0]);
    }

    public function testBrandNotTouchedWhenIsBrandNotOne(): void
    {
        $this->moduleManager->expects(self::never())->method('isEnabled');
        $this->configWriter->expects(self::never())->method('save');

        $amasty = (new AmastyAttributeSettings())->setIsBrand(0);
        $messages = [];
        $this->writer->apply($this->definition($amasty), 42, $messages);
    }

    public function testOptionSettingsResolveLabelToIdAndMapImageColumn(): void
    {
        $this->resource->method('hasOptionSettingTable')->willReturn(true);
        $this->attributeOption->method('getOptionId')
            ->with(42, 'Nike')->willReturn(7);

        $captured = null;
        $this->resource->expects(self::once())->method('upsertOptionSetting')
            ->with(
                self::equalTo('brand'),
                self::equalTo(7),
                self::equalTo(0),
                self::callback(function (array $values) use (&$captured): bool {
                    $captured = $values;
                    return true;
                }),
                self::anything()
            )
            ->willReturn(true);

        $setting = (new AmastyOptionSetting())
            ->setOption('Nike')
            ->setTitle('Nike')
            ->setImage('brands/nike.png')
            ->setUrl('nike')
            ->setDescription('Just do it.');
        $amasty = (new AmastyAttributeSettings())->setOptionSettings([$setting]);

        $messages = [];
        self::assertTrue($this->writer->apply($this->definition($amasty), 42, $messages));
        self::assertSame('brands/nike.png', $captured['image']); // not "img"
        self::assertSame('nike', $captured['url_alias']);
        self::assertSame('Nike', $captured['title']);
    }

    public function testOptionSettingSkippedWithMessageWhenLabelUnknown(): void
    {
        $this->resource->method('hasOptionSettingTable')->willReturn(true);
        $this->attributeOption->method('getOptionId')->willReturn(null);
        $this->resource->expects(self::never())->method('upsertOptionSetting');

        $setting = (new AmastyOptionSetting())->setOption('Ghost')->setTitle('x');
        $amasty = (new AmastyAttributeSettings())->setOptionSettings([$setting]);

        $messages = [];
        $this->writer->apply($this->definition($amasty), 42, $messages);

        self::assertNotEmpty($messages);
        self::assertStringContainsString('not found', $messages[0]);
    }
}
