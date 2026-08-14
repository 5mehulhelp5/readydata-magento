<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Cache;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Data\CategoryStoreValues;
use ReadyData\Import\Model\Data\ProductStoreValues;

/**
 * Scope resolution: the two forms a caller may name a store view in, and the
 * difference between a scope the whole request depends on (throws) and one that
 * arrived on a single payload item (answers null so the item can be reported).
 */
class StoreWebsiteMapTest extends TestCase
{
    private const STORES = ['de_de' => 3, 'fr_fr' => 5];

    private StoreWebsiteMap $map;

    protected function setUp(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(function (): Select {
            $select = $this->createMock(Select::class);
            foreach (['from', 'join', 'where', 'order', 'limit'] as $method) {
                $select->method($method)->willReturnSelf();
            }

            return $select;
        });
        $connection->method('fetchPairs')->willReturn(self::STORES);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->map = new StoreWebsiteMap($resourceConnection);
    }

    public function testCodeResolvesToItsId(): void
    {
        self::assertSame(3, $this->map->resolveStoreId('de_de'));
    }

    /**
     * @dataProvider defaultScopeSpellings
     */
    public function testDefaultScopeSpellingsResolveToZero(?string $code): void
    {
        self::assertSame(0, $this->map->resolveStoreId($code));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function defaultScopeSpellings(): array
    {
        return ['null' => [null], 'empty' => [''], 'admin' => ['admin']];
    }

    public function testUnknownCodeFailsTheRequest(): void
    {
        $this->expectException(LocalizedException::class);
        $this->map->resolveStoreId('nope');
    }

    public function testIdWinsOverCode(): void
    {
        // A caller that holds the ID should not have to agree with itself about
        // the code; the ID is the more specific answer and is taken as given.
        self::assertSame(5, $this->map->resolveScopeStoreId(5, 'de_de'));
    }

    public function testScopeFallsBackToTheCodeWhenNoIdIsGiven(): void
    {
        self::assertSame(3, $this->map->resolveScopeStoreId(null, 'de_de'));
        self::assertSame(0, $this->map->resolveScopeStoreId(null, null));
    }

    public function testUnknownIdFailsTheRequest(): void
    {
        $this->expectException(LocalizedException::class);
        $this->map->resolveScopeStoreId(99, null);
    }

    /**
     * Per-item scopes answer null rather than throwing: one unresolvable store
     * view is that item's problem to report, not the whole request's to fail on.
     */
    public function testFindScopeStoreIdAnswersNullInsteadOfThrowing(): void
    {
        self::assertNull($this->map->findScopeStoreId(99, null));
        self::assertNull($this->map->findScopeStoreId(null, 'nope'));
        self::assertNull($this->map->findScopeStoreId(null, null));
        self::assertSame(3, $this->map->findScopeStoreId(3, null));
        self::assertSame(5, $this->map->findScopeStoreId(null, 'fr_fr'));
        self::assertSame(0, $this->map->findScopeStoreId(null, 'admin'));
    }

    /**
     * IDs are local to an instance and go stale across a rebuild or a re-created
     * store view; codes travel. A block carrying both has already given the
     * answer, and discarding it because the ID half aged out threw that away.
     */
    public function testAStaleIdFallsBackToTheCodeSentBesideIt(): void
    {
        self::assertSame(5, $this->map->findScopeStoreId(99, 'fr_fr'));
        // Neither half resolves: still nothing to write.
        self::assertNull($this->map->findScopeStoreId(99, 'nope'));
    }

    /** The ID still wins whenever it resolves — the code is a fallback, not a vote. */
    public function testTheIdWinsWhenItResolves(): void
    {
        self::assertSame(3, $this->map->findScopeStoreId(3, 'fr_fr'));
    }

    /**
     * Resolution has one answer; whether the payload gave it twice, differently,
     * is a separate question — asked separately so the answer can be reported
     * instead of assumed.
     */
    public function testScopeMismatchIsSilentWhenThereIsNothingToDisagreeAbout(): void
    {
        self::assertNull($this->map->scopeMismatch(3, null));
        self::assertNull($this->map->scopeMismatch(null, 'fr_fr'));
        self::assertNull($this->map->scopeMismatch(null, null));
        self::assertNull($this->map->scopeMismatch(3, ''));
        // Both sent, both naming the same scope.
        self::assertNull($this->map->scopeMismatch(5, 'fr_fr'));
        // Neither resolves — the block is skipped, and describeScope names both.
        self::assertNull($this->map->scopeMismatch(99, 'nope'));
    }

    public function testScopeMismatchNamesBothScopesAndWhichOneWasUsed(): void
    {
        $message = $this->map->scopeMismatch(3, 'fr_fr');

        self::assertNotNull($message);
        self::assertStringContainsString('ID 3', $message);
        self::assertStringContainsString('"fr_fr" (ID 5)', $message);
        self::assertStringContainsString('the ID was used', $message);
    }

    public function testScopeMismatchSaysWhenTheCodeWasUsedBecauseTheIdWasStale(): void
    {
        $message = $this->map->scopeMismatch(99, 'fr_fr');

        self::assertNotNull($message);
        self::assertStringContainsString('no store view with ID 99', $message);
        self::assertStringContainsString('"fr_fr" (ID 5)', $message);
        self::assertStringContainsString('older snapshot', $message);
    }

    public function testScopeMismatchReportsAnUnknownCodeAlongsideAUsableId(): void
    {
        $message = $this->map->scopeMismatch(3, 'nope');

        self::assertNotNull($message);
        self::assertStringContainsString('no such code', $message);
        self::assertStringContainsString('ID 3', $message);
    }

    /**
     * 0 is not a store view, but it is a scope values can be written in, which
     * is what the callers are asking about.
     */
    public function testDefaultScopeIsAValidScopeId(): void
    {
        self::assertTrue($this->map->hasStoreId(0));
        self::assertTrue($this->map->hasStoreId(3));
        self::assertFalse($this->map->hasStoreId(99));
    }

    /**
     * One wording for both endpoints: a caller reading a product result and a
     * category result should not have to learn two ways of being told the same
     * thing.
     */
    public function testAnUnresolvableScopeIsNamedByWhicheverFormThePayloadUsed(): void
    {
        self::assertSame(
            'store view ID 99',
            $this->map->describeScope((new ProductStoreValues())->setStoreId(99))
        );
        self::assertSame(
            'store view "nope"',
            $this->map->describeScope((new CategoryStoreValues())->setStoreViewCode('nope'))
        );
        // Both, when the payload sent both. Reaching this message means NEITHER
        // form resolved, and naming only the ID left the code out of the one
        // message anybody was going to read about it.
        self::assertSame(
            'store view ID 3, also named as "fr_fr"',
            $this->map->describeScope((new ProductStoreValues())->setStoreId(3)->setStoreViewCode('fr_fr'))
        );
        self::assertSame(
            'a block naming no store view',
            $this->map->describeScope(new CategoryStoreValues())
        );
    }
}
