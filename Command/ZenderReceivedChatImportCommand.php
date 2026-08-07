<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Command;

use MauticPlugin\MauticZenderBundle\Service\ZenderReceivedChatImporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ZenderReceivedChatImportCommand extends Command
{
    protected static $defaultName = (
        '7cats:zender:import:received'
    );

    public function __construct(
        private readonly ZenderReceivedChatImporter $importer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Import received Zender chats through the read-only API.'
            )
            ->addOption(
                'execute',
                null,
                InputOption::VALUE_NONE,
                'Persist valid received chats.'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Received rows requested per page.',
                '50'
            )
            ->addOption(
                'max-pages',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum received pages requested in one run.',
                '5'
            )
            ->addOption(
                'verify-idempotency',
                null,
                InputOption::VALUE_NONE,
                'Replay the same in-memory snapshot after the first pass.'
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        try {
            $summary = $this->importer->run(
                max(
                    1,
                    min(
                        100,
                        (int) $input->getOption('limit')
                    )
                ),
                (bool) $input->getOption('execute'),
                (bool) $input->getOption(
                    'verify-idempotency'
                ),
                max(
                    1,
                    min(
                        20,
                        (int) $input->getOption('max-pages')
                    )
                )
            );

            $map = [
                'IMPORT_MODE' => 'mode',
                'PROVIDER_READ_REQUESTS' => (
                    'provider_read_requests'
                ),
                'RECEIVED_HTTP_STATUS' => 'http_status',
                'RECEIVED_PROVIDER_STATUS' => (
                    'provider_status'
                ),
                'PROVIDER_BODY_SHA256' => (
                    'provider_body_sha256'
                ),
                'RECEIVED_ROWS_FETCHED' => (
                    'received_rows_fetched'
                ),
                'UNIQUE_ROWS_FETCHED' => (
                    'unique_rows_fetched'
                ),
                'CROSS_PAGE_DUPLICATES' => (
                    'cross_page_duplicates'
                ),
                'PAGES_REQUESTED' => 'pages_requested',
                'PAGES_WITH_ROWS' => 'pages_with_rows',
                'PAGINATION_MAX_PAGES' => (
                    'pagination_max_pages'
                ),
                'PAGINATION_STOP_REASON' => (
                    'pagination_stop_reason'
                ),
                'PAGE_ROW_COUNTS' => 'page_row_counts',
                'VALID_ROWS' => 'valid_rows',
                'SKIPPED_ROWS' => 'skipped_rows',
                'UNIQUE_CONTACT_MATCH_COUNT' => (
                    'unique_contact_matches'
                ),
                'AMBIGUOUS_CONTACT_MATCH_COUNT' => (
                    'ambiguous_contact_matches'
                ),
                'UNMATCHED_CONTACT_COUNT' => (
                    'unmatched_contacts'
                ),
                'UNMATCHABLE_NUMBER_COUNT' => (
                    'unmatchable_numbers'
                ),
                'FIRST_PASS_INSERTED' => (
                    'first_pass_inserted'
                ),
                'FIRST_PASS_REPLAYED' => (
                    'first_pass_replayed'
                ),
                'SECOND_PASS_INSERTED' => (
                    'second_pass_inserted'
                ),
                'SECOND_PASS_REPLAYED' => (
                    'second_pass_replayed'
                ),
                'SAME_SNAPSHOT_IDEMPOTENCY' => (
                    'same_snapshot_idempotency'
                ),
            ];

            foreach ($map as $marker => $key) {
                $output->writeln(
                    $marker.'='.($summary[$key] ?? '')
                );
            }

            $output->writeln(
                'PAGINATION_STRATEGY='.
                'BOUNDED_STOP_ON_EMPTY_SHORT_REPEAT_OR_NO_PROGRESS'
            );
            $output->writeln(
                'MATCHING_POLICY='.
                'EXACT_E164_THEN_EXACT_DIGITS_NO_SUFFIX'
            );
            $output->writeln(
                'LOCAL_REGION_GUESSING=0'
            );
            $output->writeln('LAST4_MATCHING=0');
            $output->writeln(
                'FULL_PHONE_NUMBERS_PRINTED=0'
            );
            $output->writeln(
                'MESSAGE_CONTENTS_PRINTED=0'
            );
            $output->writeln(
                'RAW_PROVIDER_JSON_PRINTED=0'
            );
            $output->writeln(
                'SECRET_VALUES_PRINTED=0'
            );
            $output->writeln('PROVIDER_MUTATIONS=0');
            $output->writeln('MESSAGES_SENT=0');
            $output->writeln('CONTACT_MUTATIONS=0');
            $output->writeln(
                'OUTGOING_QUEUE_MUTATIONS=0'
            );
            $output->writeln('ATTEMPT_MUTATIONS=0');

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $fingerprint = hash(
                'sha256',
                $exception->getMessage()
            );

            $this->logger->error(
                'Received-chat import command failed.',
                [
                    'error_class' => $exception::class,
                    'error_fingerprint' => $fingerprint,
                ]
            );

            $output->writeln(
                '<error>IMPORT_ERROR_CLASS='.
                $exception::class.
                '</error>'
            );
            $output->writeln(
                '<error>IMPORT_ERROR_FINGERPRINT='.
                $fingerprint.
                '</error>'
            );
            $output->writeln('PROVIDER_MUTATIONS=0');
            $output->writeln('MESSAGES_SENT=0');

            return Command::FAILURE;
        }
    }
}
