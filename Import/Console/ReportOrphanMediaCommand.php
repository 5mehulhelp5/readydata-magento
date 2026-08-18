<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use ReadyData\Import\Model\Media\Cleanup\OrphanReport;
use ReadyData\Import\Model\Media\Cleanup\OrphanScanner;
use ReadyData\Import\Model\ResourceModel\MediaOrphanScan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports how much of pub/media/catalog/product nothing points at, and how old
 * it is. Deletes nothing.
 *
 * This exists to answer one question before anyone writes a deleter: is
 * orphaned media actually where the disk has gone? Read the cache/ line first —
 * renditions are derived and regenerate, so if the bytes are there the answer
 * is `catalog:images:resize` and no cleanup feature is needed at all.
 */
class ReportOrphanMediaCommand extends Command
{
    private const SOURCE_LABELS = [
        MediaOrphanScan::SOURCE_GALLERY => 'Gallery rows (bound)',
        MediaOrphanScan::SOURCE_ROLE => 'Image role attributes',
        MediaOrphanScan::SOURCE_CONTENT => 'Media gallery content links',
    ];

    public function __construct(
        private readonly OrphanScanner $scanner,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('readydata:media:report-orphans')
            ->setDescription('Report unreferenced files under pub/media/catalog/product (read-only)')
            ->addOption(
                'list-orphans',
                'l',
                InputOption::VALUE_NONE,
                'Write every unreferenced path to stdout, one per line, with the summary on stderr'
            )
            ->addOption(
                'skip-excluded-sizing',
                null,
                InputOption::VALUE_NONE,
                'Do not visit cache/, watermark/ and placeholder/ to size them. Faster, but drops the'
                . ' comparison that usually answers the question.'
            )
            ->addOption(
                'allow-remote-storage',
                null,
                InputOption::VALUE_NONE,
                'Run against remote storage anyway. Every file read is a request to the remote AND a write'
                . ' into the Magento cache backend; on a large catalogue that can evict live caches.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $listOrphans = (bool)$input->getOption('list-orphans');
        // With --list-orphans the paths own stdout so they can be piped, and
        // everything a human reads goes to stderr. No file-writing code path in
        // a read-only tool: the operator redirects.
        $summary = $listOrphans && $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        $this->setAreaCode();

        try {
            $this->scanner->assertSupported((bool)$input->getOption('allow-remote-storage'));

            $report = $this->scanner->scan(
                !$input->getOption('skip-excluded-sizing'),
                static function (string $message) use ($summary): void {
                    $summary->writeln('<comment>' . $message . '</comment>');
                },
                $listOrphans ? static function (string $path) use ($output): void {
                    $output->writeln($path);
                } : null
            );
        } catch (LocalizedException $e) {
            $summary->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $this->renderReport($summary, $report);

        // Non-zero when the numbers cannot be trusted, so this can be scripted
        // without someone acting on a confidently wrong orphan count.
        return $report->isTrustworthy() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * MediaConfig and the storage helper reach StoreManagerInterface, and
     * "Area code is not set" surfacing from inside a store-manager call is not
     * a failure mode worth discovering on production. Already-set is fine and
     * means someone else got there first.
     */
    private function setAreaCode(): void
    {
        try {
            $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            // Not set yet, which is the normal case for a custom CLI command.
            $this->state->setAreaCode(Area::AREA_GLOBAL);
        }
    }

    private function renderReport(OutputInterface $output, OrphanReport $report): void
    {
        $output->writeln('');
        $output->writeln('<info>Disk</info>');
        $disk = new Table($output);
        $disk->setHeaders(['Set', 'Files', 'Size']);
        $disk->addRow(['Product images (candidates)', $report->scannedFiles, $this->bytes($report->scannedBytes)]);
        foreach ($report->excluded as $name => $totals) {
            $disk->addRow([
                sprintf('%s/ (excluded, %s)', $name, $name === 'cache' ? 'regenerable' : 'not product images'),
                $totals['files'],
                $this->bytes($totals['bytes']),
            ]);
        }
        $disk->render();

        if (($report->excluded['cache']['bytes'] ?? 0) > $report->scannedBytes) {
            $output->writeln(
                '<comment>Most of this directory is regenerable cache. Deleting pub/media/catalog/product/cache'
                . ' and running catalog:images:resize reclaims it with no reference check at all.</comment>'
            );
        }

        $output->writeln('');
        $output->writeln('<info>Unreferenced by age</info>');
        $age = new Table($output);
        $age->setHeaders(['Age', 'Files', 'Size']);
        foreach ($report->orphansByAge as $bucket => $totals) {
            $age->addRow([$bucket, $totals['files'], $this->bytes($totals['bytes'])]);
        }
        $age->addRow(['<info>Total unreferenced</info>', $report->orphanFiles(), $this->bytes($report->orphanBytes())]);
        $age->addRow(['Referenced', $report->referencedFiles(), '']);
        $age->render();
        $output->writeln(
            '<comment>The oldest bucket is the recoverable disk. Recent files are often an import still in'
            . ' flight, which is unreferenced only until its batch commits.</comment>'
        );

        $output->writeln('');
        $output->writeln('<info>Reference sources</info>');
        $sources = new Table($output);
        $sources->setHeaders(['Source', 'References', 'Candidates matched', 'No file on disk']);
        foreach (self::SOURCE_LABELS as $source => $label) {
            $sources->addRow([
                $label,
                $report->referencesLoaded[$source] ?? 0,
                $report->referencedCandidates[$source] ?? 0,
                $report->missingReferences[$source] ?? 0,
            ]);
        }
        $sources->render();

        $this->renderCaveats($output, $report);
    }

    private function renderCaveats(OutputInterface $output, OrphanReport $report): void
    {
        if (!$report->isTrustworthy()) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<error>DO NOT TRUST THESE NUMBERS. %.1f%% of gallery references point at files that are not'
                . ' on disk. That is what a broken path normalisation looks like from the inside, and if it is'
                . ' the cause then the unreferenced count above is meaningless.</error>',
                $report->galleryMissRate() * 100
            ));
        }

        $output->writeln('');
        if ($report->assetRowsUnderBasePath === 0 && $report->mediaGalleryCatalogEnabled) {
            $output->writeln(
                '<comment>Content links found nothing because Magento_MediaGalleryCatalog excludes'
                . ' catalog/product from media gallery synchronisation. That is the stock configuration, not a'
                . ' fault — but it does mean a {{media url=...}} reference in a CMS page or block is invisible'
                . ' here, so the unreferenced count is an upper bound.</comment>'
            );
        } else {
            $output->writeln(
                '<comment>Only product references are checked. CMS pages, blocks, category images and'
                . ' third-party tables are not, so the unreferenced count is an upper bound.</comment>'
            );
        }

        if ($report->unboundGalleryRows > 0) {
            $output->writeln(sprintf(
                '<comment>%d gallery rows have no product binding — what core leaves behind on a product'
                . ' delete. Reported only; this module does not touch them.</comment>',
                $report->unboundGalleryRows
            ));
        }

        $skipped = array_filter($report->skipped);
        if ($skipped !== []) {
            $parts = [];
            foreach ($skipped as $reason => $count) {
                $parts[] = sprintf('%s: %d', str_replace('_', ' ', $reason), $count);
            }
            $output->writeln('<comment>Files skipped during the walk — ' . implode(', ', $parts) . '.</comment>');
        }
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float)$bytes;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0 ? sprintf('%d B', $bytes) : sprintf('%.1f %s', $value, $units[$unit]);
    }
}
