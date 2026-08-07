<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Command;

use MauticPlugin\MauticZenderBundle\Service\DispatchConfigRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ZenderConfigCommand extends Command
{
    protected static $defaultName = '7cats:zender:config';

    public function __construct(private DispatchConfigRepository $configRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Inspect or update the Zender production controlled-dispatch settings.')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED)
            ->addOption('window-start', null, InputOption::VALUE_REQUIRED)
            ->addOption('window-end', null, InputOption::VALUE_REQUIRED)
            ->addOption('global-limit', null, InputOption::VALUE_REQUIRED)
            ->addOption('per-account-limit', null, InputOption::VALUE_REQUIRED)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED)
            ->addOption('max-attempts', null, InputOption::VALUE_REQUIRED)
            ->addOption('retry-delay', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mapping = [
            'timezone'          => 'timezone',
            'window-start'      => 'window_start',
            'window-end'        => 'window_end',
            'global-limit'      => 'global_daily_limit',
            'per-account-limit' => 'per_account_daily_limit',
            'batch-size'        => 'batch_size',
            'max-attempts'      => 'max_attempts',
            'retry-delay'       => 'retry_delay_seconds',
        ];

        $changes = [];
        foreach ($mapping as $option => $column) {
            $value = $input->getOption($option);
            if (null === $value) {
                continue;
            }
            $changes[$column] = in_array(
                $column,
                ['global_daily_limit', 'per_account_daily_limit', 'batch_size', 'max_attempts', 'retry_delay_seconds'],
                true
            ) ? max(1, (int) $value) : (string) $value;
        }

        if (isset($changes['timezone'])) {
            try {
                new \DateTimeZone($changes['timezone']);
            } catch (\Throwable) {
                $output->writeln('<error>Invalid timezone.</error>');
                return Command::INVALID;
            }
        }

        foreach (['window_start', 'window_end'] as $field) {
            if (isset($changes[$field]) && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $changes[$field])) {
                $output->writeln('<error>Window values must use HH:MM.</error>');
                return Command::INVALID;
            }
        }

        $config = $this->configRepository->update($changes);
        foreach ($config as $key => $value) {
            $output->writeln($key.'='.(is_bool($value) ? (int) $value : $value));
        }

        $output->writeln('<comment>enabled is managed by 7cats:zender:activation.</comment>');
        return Command::SUCCESS;
    }
}
