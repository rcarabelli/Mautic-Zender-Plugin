<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Command;

use MauticPlugin\MauticZenderBundle\Service\DispatchAccountRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchConfigRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ZenderActivationCommand extends Command
{
    protected static $defaultName = '7cats:zender:activation';

    public function __construct(
        private DispatchConfigRepository $configRepository,
        private DispatchAccountRepository $accountRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Enable or disable production controlled dispatch.')
            ->addOption('enable', null, InputOption::VALUE_NONE)
            ->addOption('disable', null, InputOption::VALUE_NONE)
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $enable = (bool) $input->getOption('enable');
        $disable = (bool) $input->getOption('disable');

        if ($enable === $disable) {
            $output->writeln('<error>Choose exactly one of --enable or --disable.</error>');

            return Command::INVALID;
        }

        if ($enable) {
            if ('ENABLE-PRODUCTION-CONTROLLED-DISPATCH' !== (string) $input->getOption('confirm')) {
                $output->writeln('<error>ACTIVATION_CONFIRMATION_MISMATCH=1</error>');

                return Command::INVALID;
            }

            if (5 !== $this->accountRepository->countEnabled()) {
                $output->writeln('<error>ENABLED_PRODUCTION_ACCOUNT_COUNT_INVALID=1</error>');

                return Command::FAILURE;
            }
        } elseif ('DISABLE-PRODUCTION-CONTROLLED-DISPATCH' !== (string) $input->getOption('confirm')) {
            $output->writeln('<error>DEACTIVATION_CONFIRMATION_MISMATCH=1</error>');

            return Command::INVALID;
        }

        $config = $this->configRepository->setEnabled($enable);
        $output->writeln('CONTROLLED_DISPATCH_ENABLED='.(int) $config['enabled']);
        $output->writeln('ENABLED_PRODUCTION_ACCOUNTS='.$this->accountRepository->countEnabled());

        return Command::SUCCESS;
    }
}
