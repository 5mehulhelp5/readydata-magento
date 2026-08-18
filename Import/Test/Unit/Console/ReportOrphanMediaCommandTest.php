<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Console\ReportOrphanMediaCommand;
use ReadyData\Import\Model\Media\Cleanup\OrphanReport;
use ReadyData\Import\Model\Media\Cleanup\OrphanScanner;
use ReadyData\Import\Model\ResourceModel\MediaOrphanScan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReportOrphanMediaCommandTest extends TestCase
{
    private OrphanScanner&MockObject $scanner;
    private State&MockObject $state;

    protected function setUp(): void
    {
        $this->scanner = $this->createMock(OrphanScanner::class);
        $this->state = $this->createMock(State::class);
    }

    /**
     * MediaConfig and the storage helper reach the store manager, and "Area code
     * is not set" surfacing from inside one of those calls is a miserable thing
     * to debug on production.
     */
    public function testTheGlobalAreaCodeIsSetWhenNoneHasBeen(): void
    {
        $this->state->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        $this->state->expects(self::once())->method('setAreaCode')->with(Area::AREA_GLOBAL);
        $this->scanner->method('scan')->willReturn($this->report());

        $this->runCommand();
    }

    public function testAnAlreadySetAreaCodeIsLeftAlone(): void
    {
        $this->state->method('getAreaCode')->willReturn(Area::AREA_ADMINHTML);
        $this->state->expects(self::never())->method('setAreaCode');
        $this->scanner->method('scan')->willReturn($this->report());

        $this->runCommand();
    }

    public function testARefusalIsReportedAndFailsWithoutScanning(): void
    {
        $this->scanner->method('assertSupported')
            ->willThrowException(new LocalizedException(__('Database media storage is enabled')));
        $this->scanner->expects(self::never())->method('scan');

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Database media storage is enabled', $tester->getDisplay());
    }

    public function testRemoteStorageIsAllowedThroughOnlyWithTheFlag(): void
    {
        $allowed = [];
        $this->scanner->method('assertSupported')->willReturnCallback(
            static function (bool $allow) use (&$allowed): void {
                $allowed[] = $allow;
            }
        );
        $this->scanner->method('scan')->willReturn($this->report());

        $this->runCommand();
        $this->runCommand(['--allow-remote-storage' => true]);

        self::assertSame([false, true], $allowed);
    }

    public function testTheExcludedSizingFlagIsPassedThroughInverted(): void
    {
        $sizeExcluded = [];
        $this->scanner->method('scan')->willReturnCallback(
            function (bool $size) use (&$sizeExcluded): OrphanReport {
                $sizeExcluded[] = $size;

                return $this->report();
            }
        );

        $this->runCommand();
        $this->runCommand(['--skip-excluded-sizing' => true]);

        self::assertSame([true, false], $sizeExcluded);
    }

    public function testAHealthyScanSucceedsAndReportsTheHeadlineNumbers(): void
    {
        $this->scanner->method('scan')->willReturn($this->report(
            scannedFiles: 100,
            scannedBytes: 5000,
            orphansByAge: ['>180d' => ['files' => 40, 'bytes' => 2000]]
        ));

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Product images (candidates)', $display);
        self::assertStringContainsString('Unreferenced by age', $display);
        self::assertStringContainsString('Reference sources', $display);
    }

    /**
     * The whole point of the trust guard: a broken path normalisation produces
     * a confident, very large, completely wrong orphan count. A non-zero exit
     * is what stops a script acting on it.
     */
    public function testAnUntrustworthyScanWarnsLoudlyAndFails(): void
    {
        $this->scanner->method('scan')->willReturn($this->report(
            referencesLoaded: [MediaOrphanScan::SOURCE_GALLERY => 1000],
            missingReferences: [MediaOrphanScan::SOURCE_GALLERY => 990]
        ));

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DO NOT TRUST THESE NUMBERS', $tester->getDisplay());
    }

    /**
     * The same miss rate that condemns the report above is benign when the files
     * that ARE present matched: the conventions agree and the misses are images
     * this environment does not have. A staging copy with a production database
     * and a pruned media directory looks exactly like this, and reading it as a
     * normalisation failure — which the first version of this guard did — makes
     * the alarm useless everywhere it matters.
     */
    public function testAHighMissRateWithMatchesIsReportedAsMissingMediaAndStillSucceeds(): void
    {
        $this->scanner->method('scan')->willReturn($this->report(
            scannedFiles: 2,
            referencesLoaded: [MediaOrphanScan::SOURCE_GALLERY => 16614],
            referencedCandidates: [MediaOrphanScan::SOURCE_GALLERY => 2],
            missingReferences: [MediaOrphanScan::SOURCE_GALLERY => 16612]
        ));

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringNotContainsString('DO NOT TRUST', $display);
        self::assertStringContainsString('16612 gallery references point at files that are not on disk', $display);
        self::assertStringContainsString('images this environment does not have', $display);
    }

    /**
     * The condemning case is specifically "files were present and none of them
     * matched", not "the rate is high".
     */
    public function testAnEmptyMediaDirectoryIsNotTreatedAsBroken(): void
    {
        $this->scanner->method('scan')->willReturn($this->report(
            scannedFiles: 0,
            referencesLoaded: [MediaOrphanScan::SOURCE_GALLERY => 16614],
            missingReferences: [MediaOrphanScan::SOURCE_GALLERY => 16614],
            orphansByAge: ['>180d' => ['files' => 0, 'bytes' => 0]]
        ));

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('DO NOT TRUST', $tester->getDisplay());
    }

    public function testUnboundGalleryRowsAreSurfacedAsCoresLeftovers(): void
    {
        $this->scanner->method('scan')->willReturn($this->report(unboundGalleryRows: 17));

        self::assertStringContainsString('17 gallery rows have no product binding', $this->runCommand()->getDisplay());
    }

    public function testTheCacheDirectoryDominatingIsCalledOut(): void
    {
        $this->scanner->method('scan')->willReturn($this->report(
            scannedBytes: 1000,
            excluded: ['cache' => ['files' => 50, 'bytes' => 900000]]
        ));

        self::assertStringContainsString('catalog:images:resize', $this->runCommand()->getDisplay());
    }

    public function testOrphanPathsAreStreamedOnlyWhenListingIsRequested(): void
    {
        $consumers = [];
        $this->scanner->method('scan')->willReturnCallback(
            function (bool $size, ?callable $progress, ?callable $onPath) use (&$consumers): OrphanReport {
                $consumers[] = $onPath !== null;

                return $this->report();
            }
        );

        $this->runCommand();
        $this->runCommand(['--list-orphans' => true]);

        self::assertSame([false, true], $consumers);
    }

    public function testSkippedFilesAreReportedWithTheirReason(): void
    {
        $this->scanner->method('scan')->willReturn($this->report(
            skipped: ['too_long' => 2, 'vanished' => 1, 'unreadable' => 0, 'outside_tree' => 0]
        ));

        $display = $this->runCommand()->getDisplay();

        self::assertStringContainsString('too long: 2', $display);
        self::assertStringContainsString('vanished: 1', $display);
        self::assertStringNotContainsString('unreadable', $display);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input = []): CommandTester
    {
        $tester = new CommandTester(new ReportOrphanMediaCommand($this->scanner, $this->state));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param array<string, array{files: int, bytes: int}> $excluded
     * @param array{too_long: int, vanished: int, unreadable: int, outside_tree: int}|null $skipped
     * @param array<int, int> $referencesLoaded
     * @param array<int, int> $missingReferences
     * @param array<string, array{files: int, bytes: int}> $orphansByAge
     */
    private function report(
        int $scannedFiles = 10,
        int $scannedBytes = 100,
        array $excluded = [],
        ?array $skipped = null,
        array $referencesLoaded = [],
        array $referencedCandidates = [],
        array $missingReferences = [],
        array $orphansByAge = [],
        int $unboundGalleryRows = 0,
    ): OrphanReport {
        return new OrphanReport(
            $scannedFiles,
            $scannedBytes,
            $excluded ?: ['cache' => ['files' => 0, 'bytes' => 0]],
            $skipped ?? ['too_long' => 0, 'vanished' => 0, 'unreadable' => 0, 'outside_tree' => 0],
            $referencesLoaded ?: [MediaOrphanScan::SOURCE_GALLERY => 10],
            $referencedCandidates,
            $missingReferences,
            $orphansByAge ?: ['>180d' => ['files' => 1, 'bytes' => 10]],
            $unboundGalleryRows
        );
    }
}
