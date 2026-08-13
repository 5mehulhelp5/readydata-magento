<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\UrlKeyGenerator;

class UrlKeyGeneratorTest extends TestCase
{
    /**
     * @dataProvider generateDataProvider
     */
    public function testGenerate(string $name, string $expected): void
    {
        self::assertSame($expected, (new UrlKeyGenerator())->generate($name));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function generateDataProvider(): array
    {
        return [
            'simple' => ['Example Product', 'example-product'],
            'trims and collapses' => ['  Fancy   --  Shirt  ', 'fancy-shirt'],
            'special characters' => ['50% Off! (Today)', '50-off-today'],
            'accents' => ['Café Crème Ærø', 'cafe-creme-aero'],
            'empty' => ['', ''],
            'only symbols' => ['!!!', ''],
        ];
    }
}
