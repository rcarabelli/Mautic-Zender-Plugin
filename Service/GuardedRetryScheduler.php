<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Atomically finalizes a failed production attempt and schedules only
 * classifier-approved safe retries.
 *
 * Duplicate-risk outcomes never return to pending. The hard cap is three
 * total attempts (initial attempt plus at most two retries).
 */
final class GuardedRetryScheduler
{
    public const HARD_MAX_ATTEMPTS = 3;
    public const MIN_RETRY_DELAY_SECONDS = 300;
    public const MAX_RETRY_DELAY_SECONDS = 86400;

    private const QUEUE_TABLE = 'zender_dispatch_queue';

    public function __construct(
        private Connection $connection,
        private DispatchAttemptRepository $attemptRepository
    ) {
    }

    /**
     * @param array<string, mixed> $diagnostic
     *
     * @return array{
     *     attempt_id: int,
     *     queue_status: string,
     *     retry_decision: string,
     *     retry_scheduled: bool,
     *     retry_scheduled_at: string|null,
     *     held_for_assigned_account: bool,
     *     attempts_consumed: int,
     *     attempts_remaining: int
     * }
     */
    public function finalizeFailure(
        int $queueId,
        int $attemptNumber,
        int $currentAttempts,
        int $configuredMaxAttempts,
        int $retryDelaySeconds,
        array $diagnostic,
        string $error,
        string $safetyDecision
    ): array {
        if ($queueId < 1 || $attemptNumber < 1 || $currentAttempts < 0) {
            throw new \InvalidArgumentException(
                'Queue, attempt number, and current attempts are invalid.'
            );
        }

        $allowedSafetyDecisions = [
            RetrySafetyClassifier::DECISION_SAFE_RETRY,
            RetrySafetyClassifier::DECISION_HOLD_FOR_ASSIGNED_ACCOUNT,
            RetrySafetyClassifier::DECISION_PERMANENT_FAILURE,
            RetrySafetyClassifier::DECISION_MANUAL_REVIEW,
        ];
        if (!in_array($safetyDecision, $allowedSafetyDecisions, true)) {
            $safetyDecision = RetrySafetyClassifier::DECISION_MANUAL_REVIEW;
        }

        $effectiveMaxAttempts = min(
            self::HARD_MAX_ATTEMPTS,
            max(1, $configuredMaxAttempts)
        );
        $holdForAssignedAccount = (
            RetrySafetyClassifier::DECISION_HOLD_FOR_ASSIGNED_ACCOUNT
            === $safetyDecision
        );
        $attemptsConsumed = $holdForAssignedAccount
            ? $currentAttempts
            : $currentAttempts + 1;
        $retryScheduled = !$holdForAssignedAccount
            && RetrySafetyClassifier::DECISION_SAFE_RETRY
                === $safetyDecision
            && $attemptsConsumed < $effectiveMaxAttempts;

        $retryDelaySeconds = min(
            self::MAX_RETRY_DELAY_SECONDS,
            max(self::MIN_RETRY_DELAY_SECONDS, $retryDelaySeconds)
        );
        $retryScheduledAt = $retryScheduled
            ? new \DateTimeImmutable(
                '+'.$retryDelaySeconds.' seconds',
                new \DateTimeZone('UTC')
            )
            : null;

        $ledgerDecision = $holdForAssignedAccount
            ? RetrySafetyClassifier::DECISION_HOLD_FOR_ASSIGNED_ACCOUNT
            : (
                $retryScheduled
                    ? 'scheduled'
                    : (
                        RetrySafetyClassifier::DECISION_SAFE_RETRY
                            === $safetyDecision
                            ? 'suppressed'
                            : $safetyDecision
                    )
            );
        $queueStatus = (
            $retryScheduled || $holdForAssignedAccount
        ) ? 'pending' : 'failed';

        $classification = trim(
            (string) (
                $diagnostic['classification']
                ?? 'provider_result_missing'
            )
        );
        if ('' === $classification) {
            $classification = 'provider_result_missing';
        }

        $providerRequestStarted = true === (
            $diagnostic['provider_request_started'] ?? false
        );
        $providerStartedAt = $this->parseProviderStartedAt(
            $diagnostic['cooldown_started_at'] ?? null
        );
        $httpStatus = isset($diagnostic['http_status'])
            && is_numeric($diagnostic['http_status'])
            ? (int) $diagnostic['http_status']
            : null;
        $providerMessageId = isset($diagnostic['provider_message_id'])
            ? (string) $diagnostic['provider_message_id']
            : null;
        $responseSha256 = isset($diagnostic['body_sha256'])
            ? (string) $diagnostic['body_sha256']
            : null;

        return $this->connection->transactional(
            function () use (
                $queueId,
                $attemptNumber,
                $currentAttempts,
                $attemptsConsumed,
                $effectiveMaxAttempts,
                $holdForAssignedAccount,
                $retryScheduled,
                $retryScheduledAt,
                $ledgerDecision,
                $queueStatus,
                $classification,
                $providerRequestStarted,
                $providerStartedAt,
                $httpStatus,
                $providerMessageId,
                $responseSha256,
                $error
            ): array {
                $attemptId = $this->attemptRepository->beginAttempt(
                    $queueId,
                    $attemptNumber
                );

                if (
                    $providerRequestStarted
                    && !$this->attemptRepository->markProviderRequestStarted(
                        $attemptId,
                        $providerStartedAt
                    )
                ) {
                    throw new \RuntimeException(
                        'Provider-start evidence could not be persisted.'
                    );
                }

                $parameters = [
                    'status' => $queueStatus,
                    'attempts' => $attemptsConsumed,
                    'error' => mb_substr($error, 0, 4000),
                    'id' => $queueId,
                    'dispatching' => 'dispatching',
                    'current_attempts' => $currentAttempts,
                ];

                if ($holdForAssignedAccount) {
                    $updated = $this->connection->executeStatement(
                        'UPDATE '.MAUTIC_TABLE_PREFIX.self::QUEUE_TABLE.'
                         SET status = :status,
                             attempts = :attempts,
                             claimed_at = NULL,
                             dispatch_claim_key = NULL,
                             available_at = UTC_TIMESTAMP(),
                             last_error = :error
                         WHERE id = :id
                           AND status = :dispatching
                           AND attempts = :current_attempts',
                        $parameters
                    );
                } elseif ($retryScheduled && null !== $retryScheduledAt) {
                    $parameters['available_at'] = $retryScheduledAt
                        ->format('Y-m-d H:i:s');

                    $updated = $this->connection->executeStatement(
                        'UPDATE '.MAUTIC_TABLE_PREFIX.self::QUEUE_TABLE.'
                         SET status = :status,
                             attempts = :attempts,
                             claimed_at = NULL,
                             dispatch_claim_key = NULL,
                             available_at = :available_at,
                             last_error = :error
                         WHERE id = :id
                           AND status = :dispatching
                           AND attempts = :current_attempts',
                        $parameters
                    );
                } else {
                    $updated = $this->connection->executeStatement(
                        'UPDATE '.MAUTIC_TABLE_PREFIX.self::QUEUE_TABLE.'
                         SET status = :status,
                             attempts = :attempts,
                             claimed_at = NULL,
                             dispatch_claim_key = NULL,
                             last_error = :error
                         WHERE id = :id
                           AND status = :dispatching
                           AND attempts = :current_attempts',
                        $parameters
                    );
                }

                if (1 !== $updated) {
                    throw new \RuntimeException(
                        'Queue failure finalization lost its atomic guard.'
                    );
                }

                if (
                    !$this->attemptRepository->finishAttempt(
                        $attemptId,
                        $classification,
                        $httpStatus,
                        $providerMessageId,
                        $responseSha256,
                        $error,
                        $ledgerDecision,
                        $retryScheduledAt
                    )
                ) {
                    throw new \RuntimeException(
                        'Attempt failure finalization could not be persisted.'
                    );
                }

                return [
                    'attempt_id' => $attemptId,
                    'queue_status' => $queueStatus,
                    'retry_decision' => $ledgerDecision,
                    'retry_scheduled' => $retryScheduled,
                    'retry_scheduled_at' => null !== $retryScheduledAt
                        ? $retryScheduledAt->format('Y-m-d H:i:s')
                        : null,
                    'held_for_assigned_account' => (
                        $holdForAssignedAccount
                    ),
                    'attempts_consumed' => $attemptsConsumed,
                    'attempts_remaining' => max(
                        0,
                        $effectiveMaxAttempts - $attemptsConsumed
                    ),
                ];
            }
        );
    }

    private function parseProviderStartedAt(
        mixed $rawStartedAt
    ): ?\DateTimeImmutable {
        if (!is_string($rawStartedAt) || '' === trim($rawStartedAt)) {
            return null;
        }

        try {
            return new \DateTimeImmutable(
                $rawStartedAt,
                new \DateTimeZone('UTC')
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
