<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Command;

use MauticPlugin\MauticZenderBundle\Service\DispatchAccountRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchAttemptRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchConfigRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchQueueRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ZenderStatusCommand extends Command
{
    protected static $defaultName = '7cats:zender:status';

    public function __construct(
        private DispatchConfigRepository $configRepository,
        private DispatchQueueRepository $queueRepository,
        private DispatchAccountRepository $accountRepository,
        private DispatchAttemptRepository $attemptRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Show production controlled-dispatch status.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->configRepository->get();
        foreach ($config as $key => $value) {
            $output->writeln(
                $key.'='.(is_bool($value) ? (int) $value : $value)
            );
        }

        $output->writeln('production_account_allowlist_installed=1');
        $output->writeln('automatic_dispatch_timer_expected=1');
        $output->writeln(
            'automatic_retry_enabled='.(
                (int) ($config['max_attempts'] ?? 1) > 1 ? 1 : 0
            )
        );
        $output->writeln('automatic_retry_policy=safe_retry_only');
        $output->writeln('automatic_retry_hard_max_attempts=3');

        $retry = $this->attemptRepository->getRetryStatusSummary();
        $output->writeln(
            'retry_observed_queue_items='
            .$retry['observed_queue_items']
        );
        $output->writeln(
            'retry_scheduled_pending='.$retry['scheduled_pending']
        );
        $output->writeln(
            'retry_scheduled_due='.$retry['scheduled_due']
        );
        $output->writeln(
            'retry_safe_retry_classified='
            .$retry['safe_retry_classified']
        );
        $output->writeln(
            'retry_suppressed='.$retry['suppressed']
        );
        $output->writeln(
            'retry_manual_review='.$retry['manual_review']
        );
        $output->writeln(
            'retry_permanent_failure='.$retry['permanent_failure']
        );

        $nextRetryAt = $retry['next_retry_at'];
        $output->writeln(
            'retry_next_retry_at='.(
                is_string($nextRetryAt) && '' !== trim($nextRetryAt)
                    ? str_replace(' ', 'T', $nextRetryAt).'Z'
                    : 'none'
            )
        );

        $accounts = new Table($output);
        $accounts->setHeaders([
            'Order',
            'Label',
            'Phone',
            'Account hash',
            'Daily limit',
            'Enabled',
        ]);
        foreach ($this->accountRepository->getStatusRows() as $row) {
            $accounts->addRow([
                $row['dispatch_order'],
                $row['label'],
                $row['phone'],
                $row['account_hash'],
                $row['daily_limit'],
                $row['enabled'],
            ]);
        }
        $accounts->render();

        $table = new Table($output);
        $table->setHeaders(['Status', 'Quantity']);
        foreach ($this->queueRepository->getStatusCounts() as $row) {
            $table->addRow([$row['status'], $row['quantity']]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
