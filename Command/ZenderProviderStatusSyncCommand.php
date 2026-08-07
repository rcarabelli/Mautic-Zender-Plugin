<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Command;

use MauticPlugin\MauticZenderBundle\Service\ZenderProviderStatusSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ZenderProviderStatusSyncCommand extends Command
{
    protected static $defaultName = '7cats:zender:sync:provider-status';

    public function __construct(
        private readonly ZenderProviderStatusSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Read Zender sent and pending chats and update provider status evidence.'
            )
            ->addOption(
                'execute',
                null,
                InputOption::VALUE_NONE,
                'Persist provider status observations.'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum dispatched queue rows to inspect.',
                '2000'
            )
            ->addOption(
                'max-pages',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum pages to read from each Zender endpoint.',
                '20'
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $execute = (bool) $input->getOption('execute');
        $limit = max(1, min(5000, (int) $input->getOption('limit')));
        $maxPages = max(
            1,
            min(100, (int) $input->getOption('max-pages'))
        );

        $result = $this->syncService->sync(
            $execute,
            $limit,
            $maxPages
        );

        foreach ($result as $key => $value) {
            $output->writeln(
                strtoupper($key).'='.(
                    is_bool($value)
                        ? ($value ? '1' : '0')
                        : (null === $value ? 'none' : (string) $value)
                )
            );
        }

        $output->writeln('PROVIDER_MUTATIONS=0');
        $output->writeln('MESSAGES_SENT=0');

        if (!(bool) ($result['accepted'] ?? false)) {
            $output->writeln('SYNC_ACCEPTANCE=FAILED');

            return Command::FAILURE;
        }

        $output->writeln('SYNC_ACCEPTANCE=PASS');

        return Command::SUCCESS;
    }
}
