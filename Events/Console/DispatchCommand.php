<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Console;

use ReadyData\Events\Model\Delivery\Dispatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drains the queue now, without waiting for the cron.
 *
 * Two audiences. An operator who has just fixed a broken endpoint and wants the
 * backlog gone rather than trickling out over the next hour — and cron being
 * the least-maintained subsystem on many client stores, someone who needs to
 * establish whether delivery works at all when the cron demonstrably is not
 * running.
 *
 * Runs the same dispatcher the cron and the priority consumer run, so there is
 * one delivery path with one set of behaviours rather than three.
 */
class DispatchCommand extends Command
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('readydata:events:dispatch')
            ->setDescription('Deliver queued ReadyData events now')
            ->addOption(
                'passes',
                'p',
                InputOption::VALUE_REQUIRED,
                'How many batches to send before stopping. Each pass sends one batch.',
                '1'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $passes = max(1, (int)$input->getOption('passes'));

        $sent = 0;
        $failed = 0;
        $reclaimed = 0;

        for ($pass = 0; $pass < $passes; $pass++) {
            $result = $this->dispatcher->dispatch();
            $sent += $result['sent'];
            $failed += $result['failed'];
            $reclaimed += $result['reclaimed'];

            // Nothing claimed means the queue is empty (or every remaining row
            // is waiting out its backoff), so further passes would be no-ops.
            if ($result['claimed'] === 0) {
                break;
            }
        }

        $output->writeln(sprintf(
            '<info>%d sent</info>, %d failed, %d reclaimed.',
            $sent,
            $failed,
            $reclaimed
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
