<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;

final class DispatchAttemptRepository
{
    private const TABLE = 'zender_dispatch_attempts';
    private const QUEUE_TABLE = 'zender_dispatch_queue';

    public function __construct(private Connection $connection)
    {
    }

    public function nextAttemptNumber(int $queueId): int
    {
        if ($queueId < 1) {
            throw new \InvalidArgumentException(
                'queueId must be positive.'
            );
        }

        return max(
            1,
            1 + (int) $this->connection->fetchOne(
                'SELECT COALESCE(MAX(attempt_number), 0)
                 FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
                 WHERE queue_id = :queue_id',
                ['queue_id' => $queueId]
            )
        );
    }

    public function beginAttempt(int $queueId, int $attemptNumber): int
    {
        if ($queueId < 1 || $attemptNumber < 1) {
            throw new \InvalidArgumentException('queueId and attemptNumber must be positive.');
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->connection->insert(
            MAUTIC_TABLE_PREFIX.self::TABLE,
            [
                'queue_id'                 => $queueId,
                'attempt_number'           => $attemptNumber,
                'outcome_classification'   => 'started',
                'provider_request_started' => 0,
                'retry_decision'           => 'not_evaluated',
                'started_at'               => $now,
                'created_at'               => $now,
                'updated_at'               => $now,
            ]
        );

        return (int) $this->connection->lastInsertId();
    }

    public function markProviderRequestStarted(
        int $attemptId,
        ?\DateTimeInterface $startedAt = null
    ): bool {
        if ($attemptId < 1) {
            throw new \InvalidArgumentException('attemptId must be positive.');
        }

        $startedAtUtc = null !== $startedAt
            ? \DateTimeImmutable::createFromInterface($startedAt)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s')
            : gmdate('Y-m-d H:i:s');

        return 1 === $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET provider_request_started = 1,
                 provider_request_started_at = COALESCE(
                     provider_request_started_at,
                     :provider_request_started_at
                 ),
                 outcome_classification = :classification,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND finished_at IS NULL',
            [
                'provider_request_started_at' => $startedAtUtc,
                'classification' => 'provider_request_started',
                'id' => $attemptId,
            ]
        );
    }

    public function finishAttempt(
        int $attemptId,
        string $classification,
        ?int $httpStatus = null,
        ?string $providerMessageId = null,
        ?string $providerResponseSha256 = null,
        ?string $error = null,
        string $retryDecision = 'none',
        ?\DateTimeImmutable $retryScheduledAt = null
    ): bool {
        if ($attemptId < 1) {
            throw new \InvalidArgumentException('attemptId must be positive.');
        }

        $classification = $this->normalizeIdentifier(
            $classification,
            'unknown'
        );
        $retryDecision = $this->normalizeRetryDecision($retryDecision);
        $providerMessageId = $this->normalizeNullableText(
            $providerMessageId,
            191
        );
        $providerResponseSha256 = $this->normalizeSha256(
            $providerResponseSha256
        );
        $errorFingerprint = null !== $error && '' !== trim($error)
            ? hash('sha256', $error)
            : null;

        if (null !== $httpStatus) {
            $httpStatus = max(0, min(65535, $httpStatus));
        }

        return 1 === $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET finished_at = UTC_TIMESTAMP(),
                 duration_ms = GREATEST(
                     0,
                     TIMESTAMPDIFF(
                         MICROSECOND,
                         started_at,
                         UTC_TIMESTAMP()
                     ) DIV 1000
                 ),
                 outcome_classification = :classification,
                 http_status = :http_status,
                 provider_message_id = :provider_message_id,
                 provider_response_sha256 = :provider_response_sha256,
                 error_fingerprint = :error_fingerprint,
                 retry_decision = :retry_decision,
                 retry_scheduled_at = :retry_scheduled_at,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND finished_at IS NULL',
            [
                'classification' => $classification,
                'http_status' => $httpStatus,
                'provider_message_id' => $providerMessageId,
                'provider_response_sha256' => $providerResponseSha256,
                'error_fingerprint' => $errorFingerprint,
                'retry_decision' => $retryDecision,
                'retry_scheduled_at' => null !== $retryScheduledAt
                    ? $retryScheduledAt
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s')
                    : null,
                'id' => $attemptId,
            ]
        );
    }

    public function recordCompletedAttempt(
        int $queueId,
        int $attemptNumber,
        string $classification,
        bool $providerRequestStarted,
        ?\DateTimeInterface $providerRequestStartedAt = null,
        ?int $httpStatus = null,
        ?string $providerMessageId = null,
        ?string $providerResponseSha256 = null,
        ?string $error = null,
        string $retryDecision = 'none',
        ?\DateTimeImmutable $retryScheduledAt = null
    ): int {
        return (int) $this->connection->transactional(
            function () use (
                $queueId,
                $attemptNumber,
                $classification,
                $providerRequestStarted,
                $providerRequestStartedAt,
                $httpStatus,
                $providerMessageId,
                $providerResponseSha256,
                $error,
                $retryDecision,
                $retryScheduledAt
            ): int {
                $attemptId = $this->beginAttempt(
                    $queueId,
                    $attemptNumber
                );

                if (
                    $providerRequestStarted
                    && !$this->markProviderRequestStarted(
                        $attemptId,
                        $providerRequestStartedAt
                    )
                ) {
                    throw new \RuntimeException(
                        'Attempt provider-start marker could not be persisted.'
                    );
                }

                if (
                    !$this->finishAttempt(
                        $attemptId,
                        $classification,
                        $httpStatus,
                        $providerMessageId,
                        $providerResponseSha256,
                        $error,
                        $retryDecision,
                        $retryScheduledAt
                    )
                ) {
                    throw new \RuntimeException(
                        'Attempt completion could not be persisted.'
                    );
                }

                return $attemptId;
            }
        );
    }

    public function reclassifyAccountDisconnectedAttempt(
        int $queueId,
        int $attemptNumber,
        string $providerResponseSha256,
        string $errorFingerprint
    ): bool {
        if ($queueId < 1 || $attemptNumber < 1) {
            throw new \InvalidArgumentException(
                'Queue and attempt number must be positive.'
            );
        }

        $providerResponseSha256 = $this->normalizeSha256(
            $providerResponseSha256
        );
        $errorFingerprint = $this->normalizeSha256($errorFingerprint);

        if (
            null === $providerResponseSha256
            || null === $errorFingerprint
        ) {
            throw new \InvalidArgumentException(
                'Recovery fingerprints must be valid SHA256 values.'
            );
        }

        return 1 === $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET outcome_classification = :classification,
                 retry_decision = :retry_decision,
                 retry_scheduled_at = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE queue_id = :queue_id
               AND attempt_number = :attempt_number
               AND provider_request_started = 1
               AND http_status = 200
               AND (
                    provider_message_id IS NULL
                    OR provider_message_id = \'\'
               )
               AND provider_response_sha256 = :provider_response_sha256
               AND error_fingerprint = :error_fingerprint
               AND outcome_classification = :expected_classification
               AND retry_decision = :expected_retry_decision',
            [
                'classification' => 'account_disconnected',
                'retry_decision' => (
                    RetrySafetyClassifier::
                    DECISION_HOLD_FOR_ASSIGNED_ACCOUNT
                ),
                'queue_id' => $queueId,
                'attempt_number' => $attemptNumber,
                'provider_response_sha256' => $providerResponseSha256,
                'error_fingerprint' => $errorFingerprint,
                'expected_classification' => (
                    'http_2xx_unrecognized_json'
                ),
                'expected_retry_decision' => 'manual_review',
            ]
        );
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    public function getRecentForQueue(int $queueId, int $limit = 20): array
    {
        if ($queueId < 1) {
            return [];
        }

        $limit = max(1, min(100, $limit));

        return $this->connection->fetchAllAssociative(
            'SELECT id,
                    queue_id,
                    attempt_number,
                    outcome_classification,
                    provider_request_started,
                    started_at,
                    provider_request_started_at,
                    finished_at,
                    duration_ms,
                    http_status,
                    CASE
                        WHEN provider_message_id IS NULL
                          OR provider_message_id = \'\'
                        THEN 0 ELSE 1
                    END AS has_provider_message_id,
                    CASE
                        WHEN provider_response_sha256 IS NULL
                          OR provider_response_sha256 = \'\'
                        THEN 0 ELSE 1
                    END AS has_provider_response_sha256,
                    CASE
                        WHEN error_fingerprint IS NULL
                          OR error_fingerprint = \'\'
                        THEN 0 ELSE 1
                    END AS has_error_fingerprint,
                    retry_decision,
                    retry_scheduled_at
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE queue_id = :queue_id
             ORDER BY attempt_number DESC, id DESC
             LIMIT '.$limit,
            ['queue_id' => $queueId]
        );
    }

    /**
     * Return a read-only operational summary for the latest attempt of each
     * queue item. Historical rows are preserved, but the status command uses
     * only the latest ledger decision per queue item to avoid double counting.
     *
     * @return array{
     *     observed_queue_items: int,
     *     scheduled_pending: int,
     *     scheduled_due: int,
     *     safe_retry_classified: int,
     *     suppressed: int,
     *     manual_review: int,
     *     permanent_failure: int,
     *     next_retry_at: string|null
     * }
     */
    public function getRetryStatusSummary(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) AS observed_queue_items,
                COALESCE(SUM(
                    CASE
                        WHEN attempts.retry_decision = \'scheduled\'
                         AND queue.status = \'pending\'
                         AND queue.available_at > UTC_TIMESTAMP()
                        THEN 1 ELSE 0
                    END
                ), 0) AS scheduled_pending,
                COALESCE(SUM(
                    CASE
                        WHEN attempts.retry_decision = \'scheduled\'
                         AND queue.status = \'pending\'
                         AND queue.available_at <= UTC_TIMESTAMP()
                        THEN 1 ELSE 0
                    END
                ), 0) AS scheduled_due,
                COALESCE(SUM(
                    CASE
                        WHEN attempts.retry_decision = \'safe_retry\'
                        THEN 1 ELSE 0
                    END
                ), 0) AS safe_retry_classified,
                COALESCE(SUM(
                    CASE
                        WHEN attempts.retry_decision = \'suppressed\'
                        THEN 1 ELSE 0
                    END
                ), 0) AS suppressed,
                COALESCE(SUM(
                    CASE
                        WHEN attempts.retry_decision = \'manual_review\'
                        THEN 1 ELSE 0
                    END
                ), 0) AS manual_review,
                COALESCE(SUM(
                    CASE
                        WHEN attempts.retry_decision = \'permanent_failure\'
                        THEN 1 ELSE 0
                    END
                ), 0) AS permanent_failure,
                MIN(
                    CASE
                        WHEN attempts.retry_decision = \'scheduled\'
                         AND queue.status = \'pending\'
                         AND queue.available_at > UTC_TIMESTAMP()
                        THEN queue.available_at
                        ELSE NULL
                    END
                ) AS next_retry_at
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' attempts
             INNER JOIN (
                SELECT queue_id, MAX(id) AS latest_attempt_id
                FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
                GROUP BY queue_id
             ) latest
                ON latest.latest_attempt_id = attempts.id
             LEFT JOIN '.MAUTIC_TABLE_PREFIX.self::QUEUE_TABLE.' queue
                ON queue.id = attempts.queue_id'
        );

        if (false === $row) {
            return [
                'observed_queue_items' => 0,
                'scheduled_pending' => 0,
                'scheduled_due' => 0,
                'safe_retry_classified' => 0,
                'suppressed' => 0,
                'manual_review' => 0,
                'permanent_failure' => 0,
                'next_retry_at' => null,
            ];
        }

        return [
            'observed_queue_items' => (int) (
                $row['observed_queue_items'] ?? 0
            ),
            'scheduled_pending' => (int) (
                $row['scheduled_pending'] ?? 0
            ),
            'scheduled_due' => (int) (
                $row['scheduled_due'] ?? 0
            ),
            'safe_retry_classified' => (int) (
                $row['safe_retry_classified'] ?? 0
            ),
            'suppressed' => (int) ($row['suppressed'] ?? 0),
            'manual_review' => (int) ($row['manual_review'] ?? 0),
            'permanent_failure' => (int) (
                $row['permanent_failure'] ?? 0
            ),
            'next_retry_at' => isset($row['next_retry_at'])
                && is_string($row['next_retry_at'])
                && '' !== trim($row['next_retry_at'])
                    ? $row['next_retry_at']
                    : null,
        ];
    }

    private function normalizeIdentifier(
        string $value,
        string $fallback
    ): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:-]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return '' === $value
            ? $fallback
            : substr($value, 0, 64);
    }

    private function normalizeRetryDecision(string $value): string
    {
        $value = $this->normalizeIdentifier($value, 'not_evaluated');
        $allowed = [
            'not_evaluated',
            'none',
            'safe_retry',
            'hold_for_assigned_account',
            'permanent_failure',
            'scheduled',
            'suppressed',
            'manual_review',
        ];

        return in_array($value, $allowed, true)
            ? $value
            : 'manual_review';
    }

    private function normalizeNullableText(
        ?string $value,
        int $maximumLength
    ): ?string {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value
            ? null
            : mb_substr($value, 0, $maximumLength);
    }

    private function normalizeSha256(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = strtolower(trim($value));

        return 1 === preg_match('/^[a-f0-9]{64}$/', $value)
            ? $value
            : null;
    }
}
