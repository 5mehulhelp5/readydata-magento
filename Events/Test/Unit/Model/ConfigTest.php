<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use ReadyData\Events\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig, $this->createMock(DeploymentConfig::class));
    }

    public function testQueueGridIsOnWhenTheFlagIsSet(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('readydata_events/queue_grid/enabled')
            ->willReturn(true);

        $this->assertTrue($this->config->isQueueGridEnabled());
    }

    public function testQueueGridIsOffWhenTheFlagIsCleared(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('readydata_events/queue_grid/enabled')
            ->willReturn(false);

        $this->assertFalse($this->config->isQueueGridEnabled());
    }

    /**
     * The grid is an admin surface, not a pipeline switch. Reading its flag
     * must never be mistaken for reading the module's own enabled flag —
     * hiding the page while events stop flowing would be a silent outage.
     */
    public function testQueueGridAndModuleEnabledReadDifferentPaths(): void
    {
        $asked = [];
        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(static function (string $path) use (&$asked): bool {
                $asked[] = $path;
                return $path === 'readydata_events/general/enabled';
            });

        $this->assertTrue($this->config->isEnabled());
        $this->assertFalse($this->config->isQueueGridEnabled());
        $this->assertSame(
            ['readydata_events/general/enabled', 'readydata_events/queue_grid/enabled'],
            $asked
        );
    }
}
