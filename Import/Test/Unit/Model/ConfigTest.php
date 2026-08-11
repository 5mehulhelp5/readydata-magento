<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Config;

/**
 * The replace scope is the one setting here whose fallback direction is a
 * safety property rather than a convenience: anything unrecognised must land on
 * the behaviour the module has always had, never on the narrower one, or a
 * typo'd config value would silently stop a feed from removing links it owns.
 */
class ConfigTest extends TestCase
{
    /**
     * @dataProvider replaceScopeValues
     */
    public function testReplaceScopeFallsBackToTheWholeCatalog(?string $stored, string $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with('readydata_import/categories/replace_scope')
            ->willReturn($stored);

        self::assertSame($expected, (new Config($scopeConfig))->getCategoryReplaceScope());
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function replaceScopeValues(): array
    {
        return [
            'payload roots' => [Config::REPLACE_SCOPE_PAYLOAD_ROOTS, Config::REPLACE_SCOPE_PAYLOAD_ROOTS],
            'all roots' => [Config::REPLACE_SCOPE_ALL_ROOTS, Config::REPLACE_SCOPE_ALL_ROOTS],
            'never set' => [null, Config::REPLACE_SCOPE_ALL_ROOTS],
            'empty' => ['', Config::REPLACE_SCOPE_ALL_ROOTS],
            'unrecognised' => ['payload-roots', Config::REPLACE_SCOPE_ALL_ROOTS],
        ];
    }
}
