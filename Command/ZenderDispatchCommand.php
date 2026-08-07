<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Command;

use MauticPlugin\MauticZenderBundle\Service\DispatchAccountRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchAttemptRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchQuotaResolver;
use MauticPlugin\MauticZenderBundle\Service\DispatchConfigRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchQueueRepository;
use MauticPlugin\MauticZenderBundle\Service\GuardedRetryScheduler;
use MauticPlugin\MauticZenderBundle\Service\RetrySafetyClassifier;
use MauticPlugin\MauticZenderBundle\Service\RoundRobinPlanner;
use MauticPlugin\MauticZenderBundle\Service\ZenderAccountApiClient;
use MauticPlugin\MauticZenderBundle\Transport\ZenderTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ZenderDispatchCommand extends Command
{
    protected static $defaultName = '7cats:zender:dispatch';

    public function __construct(
        private DispatchConfigRepository $configRepository,
        private DispatchAccountRepository $accountRepository,
        private DispatchQuotaResolver $quotaResolver,
        private DispatchQueueRepository $queueRepository,
        private DispatchAttemptRepository $attemptRepository,
        private RetrySafetyClassifier $retrySafetyClassifier,
        private GuardedRetryScheduler $retryScheduler,
        private RoundRobinPlanner $planner,
        private ZenderAccountApiClient $accountApiClient,
        private ZenderTransport $transport,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Dispatch production Zender queue under quotas and time window.')
            ->addOption('execute', null, InputOption::VALUE_NONE)
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional maximum distinct eligible Zender IDs for this run.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->configRepository->get();

        if (!$config['enabled']) {
            $output->writeln('CONTROLLED_DISPATCH_ENABLED=0');
            $output->writeln('MESSAGES_SENT=0');

            return Command::SUCCESS;
        }

        $timezone = new \DateTimeZone($config['timezone']);
        $nowLocal = new \DateTimeImmutable('now', $timezone);
        $windowStart = new \DateTimeImmutable(
            $nowLocal->format('Y-m-d').' '.$config['window_start'],
            $timezone
        );
        $windowEnd = new \DateTimeImmutable(
            $nowLocal->format('Y-m-d').' '.$config['window_end'],
            $timezone
        );

        if ($nowLocal < $windowStart || $nowLocal >= $windowEnd) {
            $output->writeln('OUTSIDE_DISPATCH_WINDOW=1');
            $output->writeln('LOCAL_NOW='.$nowLocal->format('Y-m-d H:i:s T'));
            $output->writeln('MESSAGES_SENT=0');

            return Command::SUCCESS;
        }

        if (!$this->queueRepository->acquireLock()) {
            $output->writeln('DISPATCH_LOCK_BUSY=1');
            $output->writeln('MESSAGES_SENT=0');

            return Command::SUCCESS;
        }

        try {
            $expired = $this->queueRepository->expireScheduled();
            $quarantined = $this->queueRepository->quarantineStaleDispatching(900);
            $utc = new \DateTimeZone('UTC');
            $dayStartLocal = new \DateTimeImmutable(
                $nowLocal->format('Y-m-d').' 00:00:00',
                $timezone
            );
            $dayEndLocal = $dayStartLocal->modify('+1 day');
            $dayStartUtc = $dayStartLocal->setTimezone($utc);
            $dayEndUtc = $dayEndLocal->setTimezone($utc);

            $globalUsed = $this->queueRepository->countDispatchedBetween(
                $dayStartUtc,
                $dayEndUtc
            );
            $globalRemaining = max(
                0,
                $config['global_daily_limit'] - $globalUsed
            );

            if (0 === $globalRemaining) {
                $output->writeln('GLOBAL_DAILY_QUOTA_REACHED=1');
                $output->writeln('MESSAGES_SENT=0');

                return Command::SUCCESS;
            }

            $requestedLimit = null;
            $rawRequestedLimit = $input->getOption('limit');
            if (
                null !== $rawRequestedLimit
                && '' !== trim((string) $rawRequestedLimit)
            ) {
                $requestedLimit = max(
                    1,
                    min(50000, (int) $rawRequestedLimit)
                );
            }

            $rows = $this->queueRepository->fetchEligible(50000);
            $rowsBeforeConnectivityGate = count($rows);

            if (0 === $rowsBeforeConnectivityGate) {
                $connectivityGate = [
                    'snapshot_available' => true,
                    'provider_read_requests' => 0,
                    'connected_account_count' => 0,
                    'eligible_rows' => [],
                    'held_not_connected_rows' => 0,
                    'held_unknown_rows' => 0,
                    'fail_closed' => false,
                ];
                $output->writeln(
                    'ACCOUNT_CONNECTIVITY_SNAPSHOT_SKIPPED_NO_ROWS=1'
                );
            } else {
                $accountSnapshot = $this->accountApiClient
                    ->getAccountSnapshot();
                $connectivityGate = self::filterRowsByAccountConnectivity(
                    $rows,
                    $accountSnapshot
                );
                $output->writeln(
                    'ACCOUNT_CONNECTIVITY_SNAPSHOT_SKIPPED_NO_ROWS=0'
                );
            }

            $rows = $connectivityGate['eligible_rows'];

            $output->writeln(
                'ACCOUNT_CONNECTIVITY_SNAPSHOT_AVAILABLE='
                .($connectivityGate['snapshot_available'] ? '1' : '0')
            );
            $output->writeln(
                'ACCOUNT_CONNECTIVITY_PROVIDER_READ_REQUESTS='
                .$connectivityGate['provider_read_requests']
            );
            $output->writeln(
                'ACCOUNT_CONNECTIVITY_CONNECTED_ACCOUNT_COUNT='
                .$connectivityGate['connected_account_count']
            );
            $output->writeln(
                'ACCOUNT_CONNECTIVITY_ROWS_BEFORE='
                .$rowsBeforeConnectivityGate
            );
            $output->writeln(
                'ACCOUNT_CONNECTIVITY_ROWS_ELIGIBLE='
                .count($rows)
            );
            $output->writeln(
                'ACCOUNT_CONNECTIVITY_ROWS_HELD_NOT_CONNECTED='
                .$connectivityGate['held_not_connected_rows']
            );
            $output->writeln(
                'ACCOUNT_CONNECTIVITY_ROWS_HELD_UNKNOWN='
                .$connectivityGate['held_unknown_rows']
            );
            $output->writeln(
                'ACCOUNT_CONNECTIVITY_FAIL_CLOSED='
                .($connectivityGate['fail_closed'] ? '1' : '0')
            );
            $output->writeln(
                'ACCOUNT_CONNECTIVITY_SAME_ACCOUNT_PRESERVED=1'
            );
            $usedByAccount = $this->queueRepository->countDispatchedByAccountBetween(
                $dayStartUtc,
                $dayEndUtc
            );
            $enabledAccounts = $this->accountRepository->getDispatchEligibleAccounts();
            $accountLimits = $this->quotaResolver->buildAccountLimitMap(
                $enabledAccounts,
                (int) $config['per_account_daily_limit']
            );
            $activeOverrideCount = count(array_filter(
                $enabledAccounts,
                static fn (array $account): bool => null !== (
                    $account['daily_limit_override'] ?? null
                )
            ));
            $distinctLiveIds = $this->countQueueDrivenEligibleAccounts(
                $rows,
                $usedByAccount,
                (int) $config['per_account_daily_limit'],
                $accountLimits
            );
            $target = $this->resolveQueueDrivenTarget(
                $distinctLiveIds,
                $globalRemaining,
                $requestedLimit
            );
            $plan = $this->planner->plan(
                $rows,
                $target,
                $usedByAccount,
                (int) $config['per_account_daily_limit'],
                1,
                $accountLimits
            );

            $output->writeln('EXPIRED_SCHEDULED_ROWS='.$expired);
            $output->writeln('QUARANTINED_STALE_CLAIMS='.$quarantined);
            $output->writeln('GLOBAL_USED='.$globalUsed);
            $output->writeln('GLOBAL_REMAINING='.$globalRemaining);
            $output->writeln('DISTINCT_LIVE_IDS_ELIGIBLE='.$distinctLiveIds);
            $output->writeln(
                'REQUESTED_DISTINCT_ID_LIMIT='.(
                    null === $requestedLimit
                        ? 'unbounded'
                        : $requestedLimit
                )
            );
            $output->writeln('QUEUE_DRIVEN_TARGET='.$target);
            $output->writeln('SELECTED_ROWS='.count($plan));
            $output->writeln('SELECTED_DISTINCT_IDS='.count($plan));
            $output->writeln('BATCH_SIZE_AUTHORITY=0');
            $output->writeln('PER_RUN_ACCOUNT_LIMIT=1');
            $output->writeln(
                'EFFECTIVE_ACCOUNT_QUOTA_COUNT='.count($accountLimits)
            );
            $output->writeln(
                'ACTIVE_ACCOUNT_QUOTA_OVERRIDES='.$activeOverrideCount
            );
            $output->writeln('ACCOUNT_QUOTA_RESOLVER_ACTIVE=1');

            if (!$input->getOption('execute')) {
                foreach ($plan as $index => $row) {
                    $output->writeln(sprintf(
                        '%04d queue_id=%d contact_id=%d account_hash=%s dispatch_order=%d',
                        $index + 1,
                        $row['id'],
                        $row['contact_id'],
                        substr(hash('sha256', (string) $row['account_id']), 0, 12),
                        $row['dispatch_order']
                    ));
                }

                $output->writeln('DRY_RUN=1');
                $output->writeln('MESSAGES_SENT=0');

                return Command::SUCCESS;
            }

            $sent = 0;
            $failed = 0;
            $skipped = 0;
            $cooldownSkipped = 0;
            $attemptLedgerRecorded = 0;
            $attemptLedgerRecordingFailures = 0;
            $automaticRetriesScheduled = 0;
            $retrySuppressed = 0;
            $manualReviewFailures = 0;
            $permanentFailures = 0;
            $accountDisconnectedHeld = 0;

            foreach ($plan as $row) {
                $id = (int) $row['id'];
                $attemptNumber = $this->attemptRepository
                    ->nextAttemptNumber($id);

                if (!$this->queueRepository->claim($id)) {
                    ++$skipped;
                    continue;
                }

                try {
                    $result = $this->transport->dispatchQueuedMessage(
                        (string) $row['recipient'],
                        (string) $row['content'],
                        (string) $row['account_id'],
                        [
                            'queueId'   => $id,
                            'contactId' => (int) $row['contact_id'],
                            'whatsappMessageId' => (
                                isset($row['whatsapp_message_id'])
                                && null !== $row['whatsapp_message_id']
                                && (int) $row['whatsapp_message_id'] > 0
                            )
                                ? (int) $row['whatsapp_message_id']
                                : null,
                            'assetId' => (
                                isset($row['asset_id'])
                                && null !== $row['asset_id']
                                && (int) $row['asset_id'] > 0
                            )
                                ? (int) $row['asset_id']
                                : null,
                            'source' => (
                                isset($row['source'])
                                && null !== $row['source']
                            )
                                ? (string) $row['source']
                                : null,
                        ]
                    );

                    if (true === $result) {
                        $diagnostic = $this->transport->getLastProviderDiagnostic();
                        $this->queueRepository->markDispatched(
                            $id,
                            'provider_accepted',
                            isset($diagnostic['provider_message_id'])
                                ? (string) $diagnostic['provider_message_id']
                                : null,
                            isset($diagnostic['body_sha256'])
                                ? (string) $diagnostic['body_sha256']
                                : null
                        );

                        if (
                            $this->recordAttemptOutcome(
                                $id,
                                $attemptNumber,
                                $diagnostic,
                                null
                            )
                        ) {
                            ++$attemptLedgerRecorded;
                        } else {
                            ++$attemptLedgerRecordingFailures;
                        }

                        ++$sent;
                        continue;
                    }

                    $diagnostic = $this->transport->getLastProviderDiagnostic();

                    if (
                        'cooldown_not_eligible'
                        === ($diagnostic['classification'] ?? null)
                        && false
                        === ($diagnostic['provider_request_started'] ?? null)
                    ) {
                        if (!$this->queueRepository->releaseCooldownClaim($id)) {
                            throw new \RuntimeException(
                                'Cooldown-skipped queue claim could not be released.'
                            );
                        }

                        ++$skipped;
                        ++$cooldownSkipped;
                        continue;
                    }

                    $error = json_encode(
                        [
                            'classification'   => $diagnostic['classification'] ?? 'missing',
                            'http_status'       => $diagnostic['http_status'] ?? 'missing',
                            'content_type'      => $diagnostic['content_type'] ?? 'missing',
                            'body_bytes'        => $diagnostic['body_bytes'] ?? 'missing',
                            'body_sha256'       => $diagnostic['body_sha256'] ?? 'missing',
                            'json_keys'         => $diagnostic['json_keys'] ?? [],
                            'json_status_type'  => $diagnostic['json_status_type'] ?? 'missing',
                            'json_status_value' => $diagnostic['json_status_value'] ?? 'missing',
                            'json_message_value' => $diagnostic['json_message_value'] ?? 'missing',
                            'body_preview'      => $diagnostic['body_preview'] ?? '',
                        ],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );

                    $errorText = is_string($error)
                        ? $error
                        : 'Provider response diagnostic unavailable.';

                    $finalization = $this->finalizeFailureOutcome(
                        $id,
                        $attemptNumber,
                        (int) $row['attempts'],
                        $config,
                        $diagnostic,
                        $errorText
                    );

                    if ($finalization['ledger_recorded']) {
                        ++$attemptLedgerRecorded;
                    } else {
                        ++$attemptLedgerRecordingFailures;
                    }
                    if ($finalization['retry_scheduled']) {
                        ++$automaticRetriesScheduled;
                    }
                    if ('suppressed' === $finalization['retry_decision']) {
                        ++$retrySuppressed;
                    }
                    if ('manual_review' === $finalization['retry_decision']) {
                        ++$manualReviewFailures;
                    }
                    if ('permanent_failure' === $finalization['retry_decision']) {
                        ++$permanentFailures;
                    }
                    if (
                        RetrySafetyClassifier::
                        DECISION_HOLD_FOR_ASSIGNED_ACCOUNT
                        === $finalization['retry_decision']
                    ) {
                        ++$accountDisconnectedHeld;
                        ++$skipped;
                        continue;
                    }

                    ++$failed;
                } catch (\Throwable $exception) {
                    $this->logger->error(
                        '[ZENDER] Production dispatcher exception',
                        [
                            'queue_id'  => $id,
                            'exception' => $exception->getMessage(),
                        ]
                    );

                    $exceptionError = get_class($exception)
                        .': '.$exception->getMessage();

                    $diagnostic = $this->transport
                        ->getLastProviderDiagnostic();
                    if ([] === $diagnostic) {
                        $diagnostic = [
                            'classification' => 'dispatcher_exception',
                            'provider_request_started' => false,
                            'provider_message_id' => null,
                        ];
                    }

                    $finalization = $this->finalizeFailureOutcome(
                        $id,
                        $attemptNumber,
                        (int) $row['attempts'],
                        $config,
                        $diagnostic,
                        $exceptionError
                    );

                    if ($finalization['ledger_recorded']) {
                        ++$attemptLedgerRecorded;
                    } else {
                        ++$attemptLedgerRecordingFailures;
                    }
                    if ($finalization['retry_scheduled']) {
                        ++$automaticRetriesScheduled;
                    }
                    if ('suppressed' === $finalization['retry_decision']) {
                        ++$retrySuppressed;
                    }
                    if ('manual_review' === $finalization['retry_decision']) {
                        ++$manualReviewFailures;
                    }
                    if ('permanent_failure' === $finalization['retry_decision']) {
                        ++$permanentFailures;
                    }
                    if (
                        RetrySafetyClassifier::
                        DECISION_HOLD_FOR_ASSIGNED_ACCOUNT
                        === $finalization['retry_decision']
                    ) {
                        ++$accountDisconnectedHeld;
                        ++$skipped;
                        continue;
                    }

                    ++$failed;
                }
            }

            $output->writeln(sprintf(
                'DISPATCH_RESULT selected=%d sent=%d failed=%d skipped=%d',
                count($plan),
                $sent,
                $failed,
                $skipped
            ));
            $output->writeln('COOLDOWN_SKIPPED='.$cooldownSkipped);
            $output->writeln(
                'ATTEMPT_LEDGER_RECORDED='.$attemptLedgerRecorded
            );
            $output->writeln(
                'ATTEMPT_LEDGER_RECORDING_FAILURES='
                .$attemptLedgerRecordingFailures
            );
            $output->writeln('MESSAGES_SENT='.$sent);
            $output->writeln(
                'AUTOMATIC_RETRIES_SCHEDULED='.$automaticRetriesScheduled
            );
            $output->writeln('RETRIES_SUPPRESSED='.$retrySuppressed);
            $output->writeln('MANUAL_REVIEW_FAILURES='.$manualReviewFailures);
            $output->writeln('PERMANENT_FAILURES='.$permanentFailures);
            $output->writeln(
                'ACCOUNT_DISCONNECTED_HELD='.$accountDisconnectedHeld
            );

            return $failed > 0 || $attemptLedgerRecordingFailures > 0
                ? Command::FAILURE
                : Command::SUCCESS;
        } finally {
            $this->queueRepository->releaseLock();
        }
    }

    /**
     * Atomically finalize a failed production attempt.
     *
     * Classifier-approved safe retries return the queue row to pending only
     * when the configured and hard attempt caps permit it. Any scheduler
     * failure falls back to a terminal failed state with no automatic retry.
     *
     * @param array<string, int|string|bool> $config
     * @param array<string, mixed> $diagnostic
     *
     * @return array{
     *     ledger_recorded: bool,
     *     retry_scheduled: bool,
     *     retry_decision: string,
     *     queue_status: string,
     *     attempts_consumed: int
     * }
     */
    private function finalizeFailureOutcome(
        int $queueId,
        int $attemptNumber,
        int $currentAttempts,
        array $config,
        array $diagnostic,
        string $error
    ): array {
        $safetyDecision = $this->retrySafetyClassifier->classify(
            $diagnostic
        );

        try {
            $result = $this->retryScheduler->finalizeFailure(
                $queueId,
                $attemptNumber,
                $currentAttempts,
                (int) ($config['max_attempts'] ?? 1),
                (int) ($config['retry_delay_seconds'] ?? 900),
                $diagnostic,
                $error,
                $safetyDecision
            );

            return [
                'ledger_recorded' => true,
                'retry_scheduled' => true === (
                    $result['retry_scheduled'] ?? false
                ),
                'retry_decision' => (string) (
                    $result['retry_decision'] ?? 'manual_review'
                ),
                'queue_status' => (string) (
                    $result['queue_status'] ?? 'failed'
                ),
                'attempts_consumed' => (int) (
                    $result['attempts_consumed'] ?? ($currentAttempts + 1)
                ),
            ];
        } catch (\Throwable $schedulerException) {
            $this->logger->error(
                '[ZENDER] Guarded retry finalization failed',
                [
                    'queue_id' => $queueId,
                    'attempt_number' => $attemptNumber,
                    'exception_class' => get_class($schedulerException),
                    'exception_fingerprint' => hash(
                        'sha256',
                        $schedulerException->getMessage()
                    ),
                ]
            );

            if (
                RetrySafetyClassifier::
                DECISION_HOLD_FOR_ASSIGNED_ACCOUNT
                === $safetyDecision
            ) {
                $held = $this->queueRepository
                    ->holdAccountDisconnectedClaim($queueId, $error);
                if (!$held) {
                    $held = $this->queueRepository
                        ->releaseCooldownClaim($queueId);
                }

                $fallbackDecision = $held
                    ? RetrySafetyClassifier::
                        DECISION_HOLD_FOR_ASSIGNED_ACCOUNT
                    : RetrySafetyClassifier::DECISION_MANUAL_REVIEW;
                $recorded = $this->recordAttemptOutcome(
                    $queueId,
                    $attemptNumber,
                    $diagnostic,
                    $error,
                    $fallbackDecision
                );

                return [
                    'ledger_recorded' => $recorded,
                    'retry_scheduled' => false,
                    'retry_decision' => $fallbackDecision,
                    'queue_status' => $held ? 'pending' : 'dispatching',
                    'attempts_consumed' => $currentAttempts,
                ];
            }

            $this->queueRepository->markFailure(
                $queueId,
                $currentAttempts,
                1,
                (int) ($config['retry_delay_seconds'] ?? 900),
                $error,
                false
            );

            $recorded = $this->recordAttemptOutcome(
                $queueId,
                $attemptNumber,
                $diagnostic,
                $error,
                'manual_review'
            );

            return [
                'ledger_recorded' => $recorded,
                'retry_scheduled' => false,
                'retry_decision' => 'manual_review',
                'queue_status' => 'failed',
                'attempts_consumed' => $currentAttempts + 1,
            ];
        }
    }

    /**
     * Persist one completed dispatch attempt without changing queue behavior.
     *
     * Ledger failures are reported and logged, but the queue state already
     * reflects the provider outcome so no duplicate-producing retry is added.
     */
    private function recordAttemptOutcome(
        int $queueId,
        int $attemptNumber,
        array $diagnostic,
        ?string $error,
        ?string $retryDecisionOverride = null
    ): bool {
        try {
            $classification = trim(
                (string) (
                    $diagnostic['classification']
                    ?? 'provider_result_missing'
                )
            );
            $providerRequestStarted = true === (
                $diagnostic['provider_request_started'] ?? false
            );
            $providerStartedAt = null;

            $rawStartedAt = $diagnostic['cooldown_started_at'] ?? null;
            if (is_string($rawStartedAt) && '' !== trim($rawStartedAt)) {
                try {
                    $providerStartedAt = new \DateTimeImmutable(
                        $rawStartedAt,
                        new \DateTimeZone('UTC')
                    );
                } catch (\Throwable) {
                    $providerStartedAt = null;
                }
            }

            $retryDecision = null !== $retryDecisionOverride
                ? $retryDecisionOverride
                : $this->retrySafetyClassifier->classify(
                    $diagnostic
                );

            $this->attemptRepository->recordCompletedAttempt(
                $queueId,
                $attemptNumber,
                '' !== $classification
                    ? $classification
                    : 'provider_result_missing',
                $providerRequestStarted,
                $providerStartedAt,
                isset($diagnostic['http_status'])
                    && is_numeric($diagnostic['http_status'])
                    ? (int) $diagnostic['http_status']
                    : null,
                isset($diagnostic['provider_message_id'])
                    ? (string) $diagnostic['provider_message_id']
                    : null,
                isset($diagnostic['body_sha256'])
                    ? (string) $diagnostic['body_sha256']
                    : null,
                $error,
                $retryDecision
            );

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error(
                '[ZENDER] Attempt ledger persistence failed',
                [
                    'queue_id' => $queueId,
                    'attempt_number' => $attemptNumber,
                    'exception_class' => get_class($exception),
                    'exception_fingerprint' => hash(
                        'sha256',
                        $exception->getMessage()
                    ),
                ]
            );

            return false;
        }
    }

    /**
     * Keep only rows assigned to accounts currently reported as connected.
     *
     * Rows for disconnected, offline, connecting, unknown, or missing
     * accounts remain pending and untouched. If the read-only snapshot is
     * unavailable, the gate fails closed and holds every row. This method
     * never changes account_id, queue state, attempts, or provider state.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $snapshot
     *
     * @return array{
     *     snapshot_available: bool,
     *     provider_read_requests: int,
     *     connected_account_count: int,
     *     eligible_rows: array<int, array<string, mixed>>,
     *     held_not_connected_rows: int,
     *     held_unknown_rows: int,
     *     fail_closed: bool
     * }
     */
    private static function filterRowsByAccountConnectivity(
        array $rows,
        array $snapshot
    ): array {
        $snapshotAvailable = true === ($snapshot['available'] ?? false);
        $providerReadRequests = max(
            0,
            (int) ($snapshot['provider_read_requests'] ?? 0)
        );
        $connectedAccounts = [];
        $knownAccounts = [];

        if ($snapshotAvailable && is_array($snapshot['accounts'] ?? null)) {
            foreach ($snapshot['accounts'] as $account) {
                if (!is_array($account)) {
                    continue;
                }

                $accountId = trim((string) ($account['unique'] ?? ''));
                if ('' === $accountId) {
                    continue;
                }

                $status = strtolower(
                    trim((string) ($account['status'] ?? 'unknown'))
                );
                $knownAccounts[$accountId] = true;

                if ('connected' === $status) {
                    $connectedAccounts[$accountId] = true;
                }
            }
        }

        $eligibleRows = [];
        $heldNotConnectedRows = 0;
        $heldUnknownRows = 0;

        foreach ($rows as $row) {
            $accountId = trim((string) ($row['account_id'] ?? ''));

            if (!$snapshotAvailable || '' === $accountId) {
                ++$heldUnknownRows;
                continue;
            }

            if (isset($connectedAccounts[$accountId])) {
                $eligibleRows[] = $row;
                continue;
            }

            if (isset($knownAccounts[$accountId])) {
                ++$heldNotConnectedRows;
                continue;
            }

            ++$heldUnknownRows;
        }

        return [
            'snapshot_available' => $snapshotAvailable,
            'provider_read_requests' => $providerReadRequests,
            'connected_account_count' => count($connectedAccounts),
            'eligible_rows' => $eligibleRows,
            'held_not_connected_rows' => $heldNotConnectedRows,
            'held_unknown_rows' => $heldUnknownRows,
            'fail_closed' => !$snapshotAvailable,
        ];
    }

    // QUEUE_DRIVEN_DISTINCT_LIVE_IDS_3A3_BEGIN
    private function countQueueDrivenEligibleAccounts(
        array $rows,
        array $usedByAccount,
        int $perAccountLimit,
        array $accountLimits
    ): int {
        $perAccountLimit = max(0, $perAccountLimit);
        $seen = [];

        foreach ($rows as $row) {
            $accountId = (string) ($row['account_id'] ?? '');
            if ('' === $accountId) {
                continue;
            }

            $effectiveLimit = array_key_exists(
                $accountId,
                $accountLimits
            )
                ? max(0, (int) $accountLimits[$accountId])
                : $perAccountLimit;

            if (($usedByAccount[$accountId] ?? 0) >= $effectiveLimit) {
                continue;
            }

            $seen[$accountId] = true;
        }

        return count($seen);
    }

    private function resolveQueueDrivenTarget(
        int $distinctLiveIds,
        int $globalRemaining,
        ?int $requestedLimit
    ): int {
        $target = min(
            max(0, $distinctLiveIds),
            max(0, $globalRemaining)
        );

        if (null !== $requestedLimit) {
            $target = min($target, max(1, $requestedLimit));
        }

        return $target;
    }
    // QUEUE_DRIVEN_DISTINCT_LIVE_IDS_3A3_END
}
