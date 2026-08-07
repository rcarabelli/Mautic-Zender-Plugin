<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class DispatchQueueRepository
{
    private const TABLE = 'zender_dispatch_queue';
    private const ACCOUNT_TABLE = 'zender_dispatch_accounts';
    private const LOCK_NAME = '7cats.mautic_zender.dispatch';

    public function __construct(
        private Connection $connection,
        private RoundRobinPlanner $planner
    ) {
    }

    public function enqueue(array $message): bool
    {
        $availableAt = trim(
            (string) ($message['available_at'] ?? '')
        );
        $expiresAt = trim(
            (string) ($message['expires_at'] ?? '')
        );

        if ('' === $availableAt) {
            $availableAt = gmdate('Y-m-d H:i:s');
        }

        if ('' === $expiresAt) {
            $expiresAt = null;
        }

        try {
            $this->connection->insert(
                MAUTIC_TABLE_PREFIX.self::TABLE,
                [
                    'dedupe_key' => (string) $message['dedupe_key'],
                    'contact_id' => (int) $message['contact_id'],
                    'channel' => (string) ($message['channel'] ?? 'sms'),
                    'sms_id' => $message['sms_id'] ?? null,
                    'whatsapp_message_id' => (
                        $message['whatsapp_message_id'] ?? null
                    ),
                    'asset_id' => $message['asset_id'] ?? null,
                    'campaign_id' => $message['campaign_id'] ?? null,
                    'campaign_event_id' => (
                        $message['campaign_event_id'] ?? null
                    ),
                    'campaign_event_log_id' => (
                        $message['campaign_event_log_id'] ?? null
                    ),
                    'stat_tracking_hash' => (
                        $message['stat_tracking_hash'] ?? null
                    ),
                    'source' => $message['source'] ?? null,
                    'source_id' => $message['source_id'] ?? null,
                    'recipient'          => (string) $message['recipient'],
                    'account_id'         => (string) $message['account_id'],
                    'content'            => (string) $message['content'],
                    'status'             => (string) ($message['status'] ?? 'pending'),
                    'priority'           => (int) ($message['priority'] ?? 2),
                    'attempts'           => 0,
                    'queued_at'          => gmdate('Y-m-d H:i:s'),
                    'available_at'       => $availableAt,
                    'expires_at'         => $expiresAt,
                ]
            );

            return true;
        } catch (UniqueConstraintViolationException) {
            return true;
        }
    }

    /**
     * Return the most recently created active manual segment queue schedule
     * for one reusable WhatsApp message.
     *
     * @return array{
     *     contact_count:int,
     *     available_at:string,
     *     expires_at:string|null
     * }|null
     */
    public function findActiveWhatsAppMessageSchedule(
        int $messageId
    ): ?array {
        if ($messageId < 1) {
            return null;
        }

        $table = MAUTIC_TABLE_PREFIX.self::TABLE;
        $activeStatuses = [
            'pending',
            'queued',
            'blocked_account',
            'dispatching',
        ];

        $anchor = $this->connection->fetchAssociative(
            'SELECT
                source,
                source_id,
                available_at,
                expires_at
             FROM '.$table.'
             WHERE whatsapp_message_id = :message_id
               AND source IN (
                   :segment_source,
                   :multisegment_source
               )
               AND status IN (
                   :pending,
                   :queued,
                   :blocked_account,
                   :dispatching
               )
             ORDER BY id DESC
             LIMIT 1',
            [
                'message_id' => $messageId,
                'segment_source' => 'whatsapp.segment',
                'multisegment_source' => 'whatsapp.multisegment',
                'pending' => $activeStatuses[0],
                'queued' => $activeStatuses[1],
                'blocked_account' => $activeStatuses[2],
                'dispatching' => $activeStatuses[3],
            ]
        );

        if (false === $anchor) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) AS contact_count,
                MIN(available_at) AS available_at,
                MAX(expires_at) AS expires_at
             FROM '.$table.'
             WHERE whatsapp_message_id = :message_id
               AND source = :source
               AND source_id = :source_id
               AND available_at = :available_at
               AND expires_at <=> :expires_at
               AND status IN (
                   :pending,
                   :queued,
                   :blocked_account,
                   :dispatching
               )',
            [
                'message_id' => $messageId,
                'source' => (string) $anchor['source'],
                'source_id' => (int) $anchor['source_id'],
                'available_at' => (string) $anchor['available_at'],
                'expires_at' => $anchor['expires_at'],
                'pending' => $activeStatuses[0],
                'queued' => $activeStatuses[1],
                'blocked_account' => $activeStatuses[2],
                'dispatching' => $activeStatuses[3],
            ]
        );

        if (
            false === $row
            || (int) ($row['contact_count'] ?? 0) < 1
        ) {
            return null;
        }

        return [
            'contact_count' => (int) $row['contact_count'],
            'available_at' => (string) $row['available_at'],
            'expires_at' => null !== $row['expires_at']
                ? (string) $row['expires_at']
                : null,
        ];
    }

    /**
     * Cancel manual segment queue rows that are still safely unsent.
     *
     * Rows already claimed as dispatching are intentionally excluded.
     * The caller must hold the global dispatch lock before invoking this.
     */
    public function cancelPendingWhatsAppMessageSchedule(
        int $messageId
    ): int {
        if ($messageId < 1) {
            return 0;
        }

        return $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET status = :cancelled,
                 claimed_at = NULL,
                 dispatch_claim_key = NULL,
                 last_error = :reason
             WHERE whatsapp_message_id = :message_id
               AND source IN (
                   :segment_source,
                   :multisegment_source
               )
               AND status IN (
                   :pending,
                   :queued,
                   :blocked_account
               )
               AND dispatched_at IS NULL
               AND provider_accepted_at IS NULL
               AND (
                    provider_status IS NULL
                    OR provider_status = \'\'
               )
               AND (
                    provider_message_id IS NULL
                    OR provider_message_id = \'\'
               )',
            [
                'cancelled' => 'cancelled',
                'reason' => 'Manual queue operation cancelled by user.',
                'message_id' => $messageId,
                'segment_source' => 'whatsapp.segment',
                'multisegment_source' => 'whatsapp.multisegment',
                'pending' => 'pending',
                'queued' => 'queued',
                'blocked_account' => 'blocked_account',
            ]
        );
    }

    public function acquireLock(): bool
    {
        return 1 === (int) $this->connection->fetchOne(
            'SELECT GET_LOCK(?, 0)',
            [self::LOCK_NAME]
        );
    }

    public function releaseLock(): void
    {
        $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
    }

    public function quarantineStaleDispatching(int $seconds): int
    {
        $seconds = max(60, $seconds);

        return $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET status = :failed,
                 attempts = attempts + 1,
                 dispatch_claim_key = NULL,
                 last_error = :error
             WHERE status = :dispatching
               AND claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL '.$seconds.' SECOND)',
            [
                'failed'      => 'failed',
                'dispatching' => 'dispatching',
                'error'       => 'Ambiguous stale dispatch claim quarantined; manual review required.',
            ]
        );
    }

    public function expireScheduled(): int
    {
        return $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET status = :expired,
                 last_error = :error
             WHERE status IN (:pending, :blocked_account)
               AND expires_at IS NOT NULL
               AND expires_at <= UTC_TIMESTAMP()',
            [
                'expired' => 'expired',
                'pending' => 'pending',
                'blocked_account' => 'blocked_account',
                'error' => 'Queue operation expired before dispatch.',
            ]
        );
    }

    public function fetchEligible(int $limit): array
    {
        $limit = max(1, min(50000, $limit));

        return $this->connection->fetchAllAssociative(
            'SELECT q.*,
                    a.dispatch_order,
                    a.last_attempt_started_at,
                    a.next_eligible_at
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' q
             INNER JOIN '.MAUTIC_TABLE_PREFIX.self::ACCOUNT_TABLE.' a
               ON BINARY a.account_id = BINARY q.account_id
              AND a.enabled = 1
              AND (
                   a.paused_until IS NULL
                   OR a.paused_until <= UTC_TIMESTAMP()
              )
             WHERE q.status = :status
               AND q.available_at <= UTC_TIMESTAMP()
               AND (
                   q.expires_at IS NULL
                   OR q.expires_at > UTC_TIMESTAMP()
               )
               AND (
                   a.next_eligible_at IS NULL
                   OR a.next_eligible_at <= UTC_TIMESTAMP()
               )
             ORDER BY q.priority ASC,
                      q.queued_at ASC,
                      a.dispatch_order ASC,
                      q.id ASC
             LIMIT '.$limit,
            ['status' => 'pending']
        );
    }

    public function getRoundRobinPlan(int $limit): array
    {
        return $this->planner->plan(
            $this->fetchEligible(max(1000, $limit * 10)),
            $limit,
            [],
            PHP_INT_MAX,
            1
        );
    }

    // ATOMIC_ONE_MESSAGE_PER_ID_CLAIM_3A4_BEGIN
    /**
     * Atomically claim one queue row while enforcing one active claim per
     * exact Zender ID through the unique dispatch_claim_key constraint.
     *
     * A duplicate-key race is a normal lost claim and returns false. The
     * provider has not started and no attempt is consumed in that case.
     */
    public function claim(int $id): bool
    {
        try {
            return 1 === $this->connection->executeStatement(
                'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.' q
                 INNER JOIN '.MAUTIC_TABLE_PREFIX.self::ACCOUNT_TABLE.' a
                   ON BINARY a.account_id = BINARY q.account_id
                  AND a.enabled = 1
              AND (
                   a.paused_until IS NULL
                   OR a.paused_until <= UTC_TIMESTAMP()
              )
                 SET q.status = :dispatching,
                     q.claimed_at = UTC_TIMESTAMP(),
                     q.dispatch_claim_key = SHA2(
                         CAST(q.account_id AS BINARY),
                         256
                     )
                 WHERE q.id = :id
                   AND q.status = :pending
                   AND q.available_at <= UTC_TIMESTAMP()
                   AND (
                       q.expires_at IS NULL
                       OR q.expires_at > UTC_TIMESTAMP()
                   )
                   AND (
                       a.next_eligible_at IS NULL
                       OR a.next_eligible_at <= UTC_TIMESTAMP()
                   )',
                [
                    'dispatching' => 'dispatching',
                    'pending' => 'pending',
                    'id' => $id,
                ]
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
    // ATOMIC_ONE_MESSAGE_PER_ID_CLAIM_3A4_END

    // QUEUE_DRIVEN_COOLDOWN_3A2_BEGIN
    /**
     * Return a claimed row to pending when the account cooldown gate loses.
     *
     * This path does not increment attempts and is valid only before any
     * provider request has started.
     */
    public function releaseCooldownClaim(int $id): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET status = :pending,
                 claimed_at = NULL,
                 dispatch_claim_key = NULL
             WHERE id = :id
               AND status = :dispatching
               AND dispatched_at IS NULL',
            [
                'pending' => 'pending',
                'dispatching' => 'dispatching',
                'id' => $id,
            ]
        );
    }

    public function holdAccountDisconnectedClaim(
        int $id,
        string $error
    ): bool {
        return 1 === $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET status = :pending,
                 claimed_at = NULL,
                 dispatch_claim_key = NULL,
                 available_at = UTC_TIMESTAMP(),
                 last_error = :error
             WHERE id = :id
               AND status = :dispatching
               AND dispatched_at IS NULL
               AND (
                    provider_status IS NULL
                    OR provider_status = \'\'
               )
               AND (
                    provider_message_id IS NULL
                    OR provider_message_id = \'\'
               )',
            [
                'pending' => 'pending',
                'dispatching' => 'dispatching',
                'error' => mb_substr($error, 0, 4000),
                'id' => $id,
            ]
        );
    }

    public function recoverAccountDisconnectedFailure(
        int $id,
        int $expectedAttempts,
        string $expectedAccountHash
    ): bool {
        $expectedAccountHash = strtolower(
            trim($expectedAccountHash)
        );
        if (
            $id < 1
            || $expectedAttempts < 0
            || 1 !== preg_match(
                '/^[a-f0-9]{12}$/',
                $expectedAccountHash
            )
        ) {
            throw new \InvalidArgumentException(
                'Recovery identity is invalid.'
            );
        }

        return 1 === $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET status = :pending,
                 attempts = 0,
                 claimed_at = NULL,
                 dispatch_claim_key = NULL,
                 available_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND status = :failed
               AND attempts = :expected_attempts
               AND dispatched_at IS NULL
               AND (
                    provider_status IS NULL
                    OR provider_status = \'\'
               )
               AND (
                    provider_message_id IS NULL
                    OR provider_message_id = \'\'
               )
               AND LEFT(SHA2(account_id, 256), 12)
                    = :expected_account_hash',
            [
                'pending' => 'pending',
                'failed' => 'failed',
                'id' => $id,
                'expected_attempts' => $expectedAttempts,
                'expected_account_hash' => $expectedAccountHash,
            ]
        );
    }
    // QUEUE_DRIVEN_COOLDOWN_3A2_END
    public function markDispatched(
        int $id,
        string $providerStatus = 'provider_accepted',
        ?string $providerMessageId = null,
        ?string $providerResponseSha256 = null
    ): void {
        $providerMessageId = null !== $providerMessageId
            ? trim($providerMessageId)
            : null;
        if ('' === $providerMessageId) {
            $providerMessageId = null;
        }

        $providerResponseSha256 = null !== $providerResponseSha256
            ? strtolower(trim($providerResponseSha256))
            : null;
        if (
            null !== $providerResponseSha256
            && 1 !== preg_match('/^[a-f0-9]{64}$/', $providerResponseSha256)
        ) {
            $providerResponseSha256 = null;
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET status = :status,
                 dispatched_at = :dispatched_at,
                 dispatch_claim_key = NULL,
                 provider_status = :provider_status,
                 provider_message_id = COALESCE(
                     provider_message_id,
                     :provider_message_id
                 ),
                 provider_accepted_at = COALESCE(
                     provider_accepted_at,
                     :provider_accepted_at
                 ),
                 provider_response_sha256 = COALESCE(
                     provider_response_sha256,
                     :provider_response_sha256
                 ),
                 last_error = NULL
             WHERE id = :id',
            [
                'status'                   => 'dispatched',
                'dispatched_at'            => $now,
                'provider_status'          => mb_substr($providerStatus, 0, 191),
                'provider_message_id'      => null !== $providerMessageId
                    ? mb_substr($providerMessageId, 0, 191)
                    : null,
                'provider_accepted_at'     => $now,
                'provider_response_sha256' => $providerResponseSha256,
                'id'                       => $id,
            ]
        );
    }

    public function markFailure(
        int $id,
        int $currentAttempts,
        int $maxAttempts,
        int $retryDelaySeconds,
        string $error,
        bool $retryable = false
    ): void {
        $attempts = $currentAttempts + 1;
        $retry = $retryable && $attempts < $maxAttempts;

        $this->connection->update(
            MAUTIC_TABLE_PREFIX.self::TABLE,
            [
                'status'       => $retry ? 'pending' : 'failed',
                'attempts'     => $attempts,
                'dispatch_claim_key' => null,
                'available_at' => gmdate(
                    'Y-m-d H:i:s',
                    time() + max(60, $retryDelaySeconds)
                ),
                'last_error'   => mb_substr($error, 0, 4000),
            ],
            ['id' => $id]
        );
    }

    public function countDispatchedBetween(
        \DateTimeInterface $startUtc,
        \DateTimeInterface $endUtc
    ): int {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE status = :status
               AND dispatched_at >= :start
               AND dispatched_at < :end',
            [
                'status' => 'dispatched',
                'start'  => $startUtc->format('Y-m-d H:i:s'),
                'end'    => $endUtc->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function countDispatchedByAccountBetween(
        \DateTimeInterface $startUtc,
        \DateTimeInterface $endUtc
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT account_id, COUNT(*) AS quantity
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE status = :status
               AND dispatched_at >= :start
               AND dispatched_at < :end
             GROUP BY account_id',
            [
                'status' => 'dispatched',
                'start'  => $startUtc->format('Y-m-d H:i:s'),
                'end'    => $endUtc->format('Y-m-d H:i:s'),
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['account_id']] = (int) $row['quantity'];
        }

        return $result;
    }

    public function getStatusCounts(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT status, COUNT(*) AS quantity
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             GROUP BY status
             ORDER BY status'
        );
    }

    public function getRecentProviderOutcomes(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return $this->connection->fetchAllAssociative(
            'SELECT id,
                    contact_id,
                    sms_id,
                    stat_tracking_hash,
                    source,
                    source_id,
                    status,
                    attempts,
                    provider_status,
                    provider_message_id,
                    provider_accepted_at,
                    provider_status_observed_at,
                    provider_response_sha256,
                    provider_status_response_sha256,
                    queued_at,
                    dispatched_at
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             ORDER BY id DESC
             LIMIT '.$limit
        );
    }

    /**
     * PHASE_1A_PROTECTED_QUEUE_HISTORY_V1
     *
     * Return the default unfiltered Queue and History page.
     *
     * @return array{
     *     contract_version: string,
     *     items: array<int, array<string, bool|int|string|null>>,
     *     limit: int,
     *     offset: int,
     *     has_more: bool,
     *     next_offset: int|null
     * }
     */
    public function getHistoryPage(
        int $limit = 25,
        int $offset = 0
    ): array {
        return $this->getFilteredHistoryPage(
            [],
            $limit,
            $offset
        );
    }

    /**
     * PHASE_1F1A_STATUS_SOURCE_FILTERS_V1
     *
     * Return a bounded Queue and History page filtered by status and/or
     * source. Filter values are normalized and always bound as SQL
     * parameters. Unknown filter keys are ignored.
     *
     * Supported filters:
     * - status
     * - source
     * - contact_id
     * - sms_id
     * - account_hash
     * - queued_from
     * - queued_to
     *
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     contract_version: string,
     *     items: array<int, array<string, bool|int|string|null>>,
     *     limit: int,
     *     offset: int,
     *     has_more: bool,
     *     next_offset: int|null
     * }
     */
    public function getFilteredHistoryPage(
        array $filters = [],
        int $limit = 25,
        int $offset = 0
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $fetchLimit = $limit + 1;

        $statusFilter = $this->normalizeHistoryFilterValue(
            $filters['status'] ?? null
        );
        $sourceFilter = $this->normalizeHistoryFilterValue(
            $filters['source'] ?? null
        );
        $contactIdFilter = $this->normalizePositiveHistoryIdFilter(
            $filters['contact_id'] ?? null
        );
        $smsIdFilter = $this->normalizePositiveHistoryIdFilter(
            $filters['sms_id'] ?? null
        );

        // PHASE_1F1B2_ACCOUNT_HASH_FILTER_V1
        $accountHashFilter = $this->normalizeHistoryAccountHashFilter(
            $filters['account_hash'] ?? null
        );

        // PHASE_1F1B3_QUEUED_DATE_RANGE_FILTERS_V1
        $queuedFromFilter = $this->normalizeHistoryDateFilter(
            $filters['queued_from'] ?? null
        );
        $queuedToFilter = $this->normalizeHistoryDateFilter(
            $filters['queued_to'] ?? null
        );

        $conditions = [];
        $parameters = [];

        if (null !== $statusFilter) {
            $conditions[] = 'q.status = :history_status';
            $parameters['history_status'] = $statusFilter;
        }

        if (null !== $sourceFilter) {
            $conditions[] = 'q.source = :history_source';
            $parameters['history_source'] = $sourceFilter;
        }

        if (null !== $contactIdFilter) {
            $conditions[] = 'q.contact_id = :history_contact_id';
            $parameters['history_contact_id'] = $contactIdFilter;
        }

        if (null !== $smsIdFilter) {
            $conditions[] = 'q.sms_id = :history_sms_id';
            $parameters['history_sms_id'] = $smsIdFilter;
        }

        if (null !== $accountHashFilter) {
            $conditions[] = (
                'LEFT(SHA2(q.account_id, 256), 12)'
                .' = :history_account_hash'
            );
            $parameters['history_account_hash'] = $accountHashFilter;
        }

        if (
            null !== $queuedFromFilter
            && null !== $queuedToFilter
            && $queuedFromFilter > $queuedToFilter
        ) {
            $conditions[] = '1 = 0';
        } else {
            if (null !== $queuedFromFilter) {
                $conditions[] = 'q.queued_at >= :history_queued_from';
                $parameters['history_queued_from'] = (
                    $queuedFromFilter.' 00:00:00'
                );
            }

            if (null !== $queuedToFilter) {
                $queuedToExclusive = (
                    new \DateTimeImmutable(
                        $queuedToFilter,
                        new \DateTimeZone('UTC')
                    )
                )->modify('+1 day');

                $conditions[] = (
                    'q.queued_at < :history_queued_to_exclusive'
                );
                $parameters['history_queued_to_exclusive'] = (
                    $queuedToExclusive->format('Y-m-d')
                    .' 00:00:00'
                );
            }
        }

        $whereSql = [] === $conditions
            ? ''
            : ' WHERE '.implode(' AND ', $conditions);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT q.id,
                    q.contact_id,
                    q.sms_id,
                    q.source,
                    q.source_id,
                    q.status,
                    q.priority,
                    q.attempts,
                    q.queued_at,
                    q.available_at,
                    q.claimed_at,
                    q.dispatched_at,
                    q.provider_status,
                    q.provider_accepted_at,
                    q.provider_status_observed_at,
                    LEFT(SHA2(q.account_id, 256), 12) AS account_hash,
                    CASE
                        WHEN q.stat_tracking_hash IS NULL
                          OR q.stat_tracking_hash = \'\'
                        THEN 0 ELSE 1
                    END AS has_stat_tracking_hash,
                    CASE
                        WHEN q.provider_message_id IS NULL
                          OR q.provider_message_id = \'\'
                        THEN 0 ELSE 1
                    END AS has_provider_message_id,
                    CASE
                        WHEN q.provider_response_sha256 IS NULL
                          OR q.provider_response_sha256 = \'\'
                        THEN 0 ELSE 1
                    END AS has_provider_response_sha256,
                    CASE
                        WHEN q.provider_status_response_sha256 IS NULL
                          OR q.provider_status_response_sha256 = \'\'
                        THEN 0 ELSE 1
                    END AS has_provider_status_response_sha256,
                    CASE
                        WHEN q.provider_status_observed_at IS NULL
                        THEN 0 ELSE 1
                    END AS has_provider_status_observation,
                    CASE
                        WHEN q.last_error IS NULL
                          OR q.last_error = \'\'
                        THEN 0 ELSE 1
                    END AS has_error,
                    CASE
                        WHEN q.last_error IS NULL
                          OR q.last_error = \'\'
                        THEN NULL
                        ELSE LEFT(SHA2(q.last_error, 256), 16)
                    END AS error_fingerprint
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' q'
             .$whereSql.'
             ORDER BY q.id DESC
             LIMIT '.$fetchLimit.'
             OFFSET '.$offset,
            $parameters
        );

        $hasMore = count($rows) > $limit;

        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id' => max(0, (int) ($row['id'] ?? 0)),
                'contact_id' => max(
                    0,
                    (int) ($row['contact_id'] ?? 0)
                ),
                'sms_id' => null !== ($row['sms_id'] ?? null)
                    ? max(0, (int) $row['sms_id'])
                    : null,
                'source' => $this->safeHistoryIdentifier(
                    (string) ($row['source'] ?? ''),
                    'unknown'
                ),
                'source_id' => null !== ($row['source_id'] ?? null)
                    ? (int) $row['source_id']
                    : null,
                'status' => $this->safeHistoryIdentifier(
                    (string) ($row['status'] ?? ''),
                    'unknown'
                ),
                'priority' => (int) ($row['priority'] ?? 0),
                'attempts' => max(
                    0,
                    (int) ($row['attempts'] ?? 0)
                ),
                'queued_at' => $row['queued_at'] ?? null,
                'available_at' => $row['available_at'] ?? null,
                'claimed_at' => $row['claimed_at'] ?? null,
                'dispatched_at' => $row['dispatched_at'] ?? null,
                'provider_status' => $this->safeHistoryIdentifier(
                    (string) ($row['provider_status'] ?? ''),
                    'not_reported'
                ),
                'provider_accepted_at' => (
                    $row['provider_accepted_at'] ?? null
                ),
                'provider_status_observed_at' => (
                    $row['provider_status_observed_at'] ?? null
                ),
                'account_hash' => strtolower(
                    (string) ($row['account_hash'] ?? '')
                ),
                'has_stat_tracking_hash' => (
                    1 === (int) (
                        $row['has_stat_tracking_hash'] ?? 0
                    )
                ),
                'has_provider_message_id' => (
                    1 === (int) (
                        $row['has_provider_message_id'] ?? 0
                    )
                ),
                'has_provider_response_sha256' => (
                    1 === (int) (
                        $row[
                            'has_provider_response_sha256'
                        ] ?? 0
                    )
                ),
                'has_provider_status_response_sha256' => (
                    1 === (int) (
                        $row[
                            'has_provider_status_response_sha256'
                        ] ?? 0
                    )
                ),
                'has_provider_status_observation' => (
                    1 === (int) (
                        $row[
                            'has_provider_status_observation'
                        ] ?? 0
                    )
                ),
                'has_error' => (
                    1 === (int) ($row['has_error'] ?? 0)
                ),
                'error_fingerprint' => (
                    $row['error_fingerprint'] ?? null
                ),
            ];
        }

        return [
            'contract_version' => 'queue_history_v1',
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore
                ? $offset + $limit
                : null,
        ];
    }

    private function normalizeHistoryFilterValue(
        mixed $value
    ): ?string {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        if ('' === $value) {
            return null;
        }

        $value = preg_replace(
            '/[^a-z0-9._:-]+/',
            '_',
            $value
        ) ?? '';
        $value = trim($value, '_');

        return '' === $value
            ? null
            : substr($value, 0, 64);
    }

    private function normalizeHistoryDateFilter(
        mixed $value
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            1 !== preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/',
                $value
            )
        ) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            false === $date
            || (
                false !== $errors
                && (
                    0 < $errors['warning_count']
                    || 0 < $errors['error_count']
                )
            )
            || $value !== $date->format('Y-m-d')
        ) {
            return null;
        }

        return $value;
    }

    private function normalizeHistoryAccountHashFilter(
        mixed $value
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return 1 === preg_match(
            '/^[a-f0-9]{12}$/',
            $value
        )
            ? $value
            : null;
    }

    private function normalizePositiveHistoryIdFilter(
        mixed $value
    ): ?int {
        if (is_int($value)) {
            return $value > 0
                ? $value
                : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            '' === $value
            || 1 !== preg_match('/^[1-9][0-9]*$/', $value)
        ) {
            return null;
        }

        $normalized = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => PHP_INT_MAX,
                ],
            ]
        );

        return false === $normalized
            ? null
            : $normalized;
    }

    private function safeHistoryIdentifier(
        string $value,
        string $fallback
    ): string {
        $value = strtolower(trim($value));
        $value = preg_replace(
            '/[^a-z0-9._:-]+/',
            '_',
            $value
        ) ?? '';
        $value = trim($value, '_');

        return '' === $value
            ? $fallback
            : substr($value, 0, 64);
    }

    // SENDING_SPEED_VISUAL_3A5V1_BEGIN
    /**
     * Return read-only pending work state grouped by exact Zender ID.
     *
     * pending_total includes all pending rows. available_now includes only
     * rows whose queue-level available_at has elapsed. Account enablement,
     * cooldown, and daily quota are applied by the control read model.
     */
    public function getPendingDispatchStateByAccount(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT q.account_id,
                    COUNT(*) AS pending_total,
                    SUM(
                        CASE
                            WHEN q.available_at <= UTC_TIMESTAMP()
                            THEN 1 ELSE 0
                        END
                    ) AS available_now
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' q
             WHERE q.status = :status
             GROUP BY q.account_id
             ORDER BY q.account_id',
            ['status' => 'pending']
        );
    }
    // SENDING_SPEED_VISUAL_3A5V1_END
    public function getPendingCountsByAccount(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT q.account_id, COUNT(*) AS quantity
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' q
             WHERE q.status = :status
             GROUP BY q.account_id
             ORDER BY q.account_id',
            ['status' => 'pending']
        );
    }

public function findIdByDedupeKey(string $dedupeKey): ?int
{
    if (1 !== preg_match('/^[a-f0-9]{64}$/', $dedupeKey)) {
        return null;
    }

    $id = $this->connection->fetchOne(
        'SELECT id
         FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
         WHERE dedupe_key = :dedupe_key',
        ['dedupe_key' => $dedupeKey]
    );

    return false === $id ? null : (int) $id;
}

/**
 * @param array<string, mixed> $queryOptions
 */
public function countWhatsAppTimeline(
    int $contactId,
    array $queryOptions = []
): int {
    [$where, $parameters] = $this->buildWhatsAppTimelineWhere(
        $contactId,
        $queryOptions
    );

    return (int) $this->connection->fetchOne(
        'SELECT COUNT(*)
         FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' q
         LEFT JOIN '.MAUTIC_TABLE_PREFIX.'zender_whatsapp_messages m
           ON m.id = q.whatsapp_message_id
         WHERE '.$where,
        $parameters
    );
}

/**
 * @param array<string, mixed> $queryOptions
 *
 * @return array<int, array<string, mixed>>
 */
public function getWhatsAppTimelinePage(
    int $contactId,
    array $queryOptions = []
): array {
    [$where, $parameters] = $this->buildWhatsAppTimelineWhere(
        $contactId,
        $queryOptions
    );

    $limit = max(
        1,
        min(100, (int) ($queryOptions['limit'] ?? 25))
    );
    $offset = max(0, (int) ($queryOptions['start'] ?? 0));

    $rows = $this->connection->fetchAllAssociative(
        'SELECT q.id,
                q.whatsapp_message_id,
                q.source,
                q.status,
                q.attempts,
                q.available_at,
                q.provider_status,
                q.campaign_id,
                q.campaign_event_id,
                q.campaign_event_log_id,
                COALESCE(
                    q.provider_accepted_at,
                    q.dispatched_at,
                    q.queued_at
                ) AS timeline_at,
                COALESCE(m.name, \'\') AS message_name,
                COALESCE(c.name, \'\') AS campaign_name
         FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' q
         LEFT JOIN '.MAUTIC_TABLE_PREFIX.'zender_whatsapp_messages m
           ON m.id = q.whatsapp_message_id
         LEFT JOIN '.MAUTIC_TABLE_PREFIX.'campaigns c
           ON c.id = q.campaign_id
         WHERE '.$where.'
         ORDER BY timeline_at DESC, q.id DESC
         LIMIT '.$limit.'
         OFFSET '.$offset,
        $parameters
    );

    return array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'whatsapp_message_id' => null !== $row[
                'whatsapp_message_id'
            ]
                ? (int) $row['whatsapp_message_id']
                : null,
            'source' => null !== $row['source']
                ? (string) $row['source']
                : '',
            'status' => (string) $row['status'],
            'attempts' => max(0, (int) $row['attempts']),
            'available_at' => null !== $row['available_at']
                ? (string) $row['available_at']
                : null,
            'provider_status' => null !== $row['provider_status']
                ? (string) $row['provider_status']
                : '',
            'campaign_id' => null !== $row['campaign_id']
                ? (int) $row['campaign_id']
                : null,
            'campaign_event_id' => null !== $row[
                'campaign_event_id'
            ]
                ? (int) $row['campaign_event_id']
                : null,
            'campaign_event_log_id' => null !== $row[
                'campaign_event_log_id'
            ]
                ? (int) $row['campaign_event_log_id']
                : null,
            'timeline_at' => (string) $row['timeline_at'],
            'message_name' => (string) $row['message_name'],
            'campaign_name' => (string) $row['campaign_name'],
        ],
        $rows
    );
}

/**
 * @param array<string, mixed> $queryOptions
 *
 * @return array{0:string,1:array<string,mixed>}
 */
private function buildWhatsAppTimelineWhere(
    int $contactId,
    array $queryOptions
): array {
    $conditions = [
        'q.contact_id = :timeline_contact_id',
        'q.channel = :timeline_channel',
    ];
    $parameters = [
        'timeline_contact_id' => $contactId,
        'timeline_channel' => 'whatsapp',
    ];

    $search = trim((string) ($queryOptions['search'] ?? ''));
    if ('' !== $search) {
        $conditions[] = '(
            m.name LIKE :timeline_search
            OR q.status LIKE :timeline_search
            OR q.provider_status LIKE :timeline_search
        )';
        $parameters['timeline_search'] = '%'.$search.'%';
    }

    $fromDate = $queryOptions['fromDate'] ?? null;
    if ($fromDate instanceof \DateTimeInterface) {
        $conditions[] = 'q.queued_at >= :timeline_from';
        $parameters['timeline_from'] = $fromDate->format(
            'Y-m-d H:i:s'
        );
    }

    $toDate = $queryOptions['toDate'] ?? null;
    if ($toDate instanceof \DateTimeInterface) {
        $conditions[] = 'q.queued_at <= :timeline_to';
        $parameters['timeline_to'] = $toDate->format(
            'Y-m-d H:i:s'
        );
    }

    return [implode(' AND ', $conditions), $parameters];
}


/**
 * @return array<int, array{
 *     campaign_id:int,
 *     campaign_name:string,
 *     all:int,
 *     queued:int,
 *     sent_by_zender:int,
 *     held:int,
 *     retry_pending:int,
 *     failed:int,
 *     last_activity_at:string
 * }>
 */
public function getWhatsAppCampaignStatusSummaries(): array
{
    $sql = <<<'SQL'
SELECT q.campaign_id,
       c.name AS campaign_name,
       CASE
           WHEN LOWER(TRIM(COALESCE(
               q.provider_status,
               ''
           ))) IN ('zender_sent', 'sent')
           THEN 'sent_by_zender'
           WHEN q.status = 'blocked_account'
           THEN 'held'
           WHEN q.status = 'failed'
           THEN 'failed'
           WHEN (
               q.status IN ('pending', 'queued')
               AND COALESCE(
                   attempts.attempt_count,
                   0
               ) > 0
           ) OR q.status LIKE '%%retry%%'
           THEN 'retry_pending'
           WHEN q.status IN (
               'pending',
               'queued',
               'dispatching'
           )
           THEN 'queued'
           ELSE 'failed'
       END AS visible_status,
       COUNT(*) AS quantity,
       MAX(COALESCE(
           q.provider_status_observed_at,
           q.dispatched_at,
           attempts.last_attempt_at,
           q.queued_at
       )) AS last_activity_at
FROM %s q
INNER JOIN %s c
  ON c.id = q.campaign_id
LEFT JOIN (
    SELECT queue_id,
           COUNT(*) AS attempt_count,
           MAX(COALESCE(finished_at, started_at))
               AS last_attempt_at
    FROM %s
    GROUP BY queue_id
) attempts
  ON attempts.queue_id = q.id
WHERE q.channel = :campaign_list_channel
  AND q.source = :campaign_list_source
  AND q.campaign_id IS NOT NULL
GROUP BY q.campaign_id, c.name, visible_status
ORDER BY last_activity_at DESC, q.campaign_id DESC
SQL;

    $rows = $this->connection->fetchAllAssociative(
        sprintf(
            $sql,
            MAUTIC_TABLE_PREFIX.self::TABLE,
            MAUTIC_TABLE_PREFIX.'campaigns',
            MAUTIC_TABLE_PREFIX.'zender_dispatch_attempts'
        ),
        [
            'campaign_list_channel' => 'whatsapp',
            'campaign_list_source' => 'campaign.event',
        ]
    );

    $campaigns = [];

    foreach ($rows as $row) {
        $campaignId = (int) $row['campaign_id'];

        if ($campaignId < 1) {
            continue;
        }

        if (!isset($campaigns[$campaignId])) {
            $campaigns[$campaignId] = [
                'campaign_id' => $campaignId,
                'campaign_name' => (string) $row['campaign_name'],
                'all' => 0,
                'queued' => 0,
                'sent_by_zender' => 0,
                'held' => 0,
                'retry_pending' => 0,
                'failed' => 0,
                'last_activity_at' => '',
            ];
        }

        $quantity = max(0, (int) $row['quantity']);
        $visibleStatus = (string) $row['visible_status'];
        $lastActivityAt = (string) $row['last_activity_at'];

        $campaigns[$campaignId]['all'] += $quantity;

        if (array_key_exists($visibleStatus, $campaigns[$campaignId])) {
            $campaigns[$campaignId][$visibleStatus] += $quantity;
        }

        if (
            '' !== $lastActivityAt
            && (
                '' === $campaigns[$campaignId]['last_activity_at']
                || $lastActivityAt > $campaigns[$campaignId]['last_activity_at']
            )
        ) {
            $campaigns[$campaignId]['last_activity_at'] = $lastActivityAt;
        }
    }

    $items = array_values($campaigns);

    usort(
        $items,
        static function (array $left, array $right): int {
            $activityComparison = strcmp(
                (string) $right['last_activity_at'],
                (string) $left['last_activity_at']
            );

            return 0 !== $activityComparison
                ? $activityComparison
                : (int) $right['campaign_id'] <=> (int) $left['campaign_id'];
        }
    );

    return $items;
}

/**
 * @return array{id:int,name:string,is_published:bool}|null
 */
public function findCampaignWhatsAppStatusHeader(
    int $campaignId
): ?array {
    if ($campaignId < 1) {
        return null;
    }

    $row = $this->connection->fetchAssociative(
        'SELECT id, name, is_published
         FROM '.MAUTIC_TABLE_PREFIX.'campaigns
         WHERE id = :campaign_id',
        ['campaign_id' => $campaignId]
    );

    if (false === $row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'is_published' => 1 === (int) $row['is_published'],
    ];
}

/**
 * @return array<string, int>
 */
public function getCampaignWhatsAppStatusSummary(
    int $campaignId
): array {
    $summary = [
        'all' => 0,
        'queued' => 0,
        'sent_by_zender' => 0,
        'held' => 0,
        'retry_pending' => 0,
        'failed' => 0,
    ];

    if ($campaignId < 1) {
        return $summary;
    }

    $rows = $this->connection->fetchAllAssociative(
        'SELECT status_rows.visible_status, COUNT(*) AS quantity
         FROM ('.$this->campaignWhatsAppStatusBaseSql().') status_rows
         GROUP BY status_rows.visible_status',
        [
            'campaign_status_campaign_id' => $campaignId,
            'campaign_status_channel' => 'whatsapp',
        ]
    );

    foreach ($rows as $row) {
        $status = (string) $row['visible_status'];
        $quantity = max(0, (int) $row['quantity']);
        $summary['all'] += $quantity;

        if (array_key_exists($status, $summary)) {
            $summary[$status] = $quantity;
        }
    }

    return $summary;
}

/**
 * @return array{
 *     items:array<int,array<string,mixed>>,
 *     total:int,
 *     limit:int,
 *     offset:int,
 *     has_more:bool
 * }
 */
public function getCampaignWhatsAppStatusPage(
    int $campaignId,
    ?string $visibleStatus,
    int $limit = 50,
    int $offset = 0
): array {
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $visibleStatus = $this->normalizeCampaignVisibleStatus(
        $visibleStatus
    );

    if ($campaignId < 1) {
        return [
            'items' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => false,
        ];
    }

    $parameters = [
        'campaign_status_campaign_id' => $campaignId,
        'campaign_status_channel' => 'whatsapp',
    ];
    $filterSql = '';

    if (null !== $visibleStatus) {
        $filterSql = ' WHERE status_rows.visible_status'
            .' = :campaign_visible_status';
        $parameters['campaign_visible_status'] = $visibleStatus;
    }

    $baseSql = $this->campaignWhatsAppStatusBaseSql();
    $total = (int) $this->connection->fetchOne(
        'SELECT COUNT(*)
         FROM ('.$baseSql.') status_rows'.$filterSql,
        $parameters
    );

    $rows = $this->connection->fetchAllAssociative(
        'SELECT status_rows.*
         FROM ('.$baseSql.') status_rows'
         .$filterSql.'
         ORDER BY status_rows.queued_at DESC,
                  status_rows.id DESC
         LIMIT '.$limit.' OFFSET '.$offset,
        $parameters
    );

    $items = array_map(
        static function (array $row): array {
            $contactName = trim(
                (string) $row['firstname'].' '
                .(string) $row['lastname']
            );
            $email = trim((string) $row['email']);
            $phoneDigits = preg_replace(
                '/\\D+/',
                '',
                (string) $row['phone']
            ) ?? '';

            if ('' === $contactName) {
                $contactName = '' !== $email
                    ? $email
                    : 'Contact #'.(int) $row['contact_id'];
            }

            $messageName = trim((string) $row['message_name']);
            $actionName = trim((string) $row['campaign_action_name']);

            return [
                'id' => (int) $row['id'],
                'contact_id' => (int) $row['contact_id'],
                'contact_name' => $contactName,
                'contact_email' => $email,
                'contact_phone_last4' => '' !== $phoneDigits
                    ? substr($phoneDigits, -4)
                    : '',
                'visible_status' => (string) $row['visible_status'],
                'message_name' => $messageName,
                'campaign_action_name' => $actionName,
                'queued_at' => (string) $row['queued_at'],
                'last_activity_at' => (string) $row['last_activity_at'],
                'attempt_count' => max(
                    0,
                    (int) $row['attempt_count']
                ),
            ];
        },
        $rows
    );

    return [
        'items' => $items,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => $offset + count($items) < $total,
    ];
}

private function campaignWhatsAppStatusBaseSql(): string
{
    return 'SELECT q.id,
                   q.contact_id,
                   q.queued_at,
                   COALESCE(
                       q.provider_status_observed_at,
                       q.dispatched_at,
                       attempts.last_attempt_at,
                       q.queued_at
                   ) AS last_activity_at,
                   COALESCE(attempts.attempt_count, 0)
                       AS attempt_count,
                   COALESCE(l.firstname, \'\') AS firstname,
                   COALESCE(l.lastname, \'\') AS lastname,
                   COALESCE(l.email, \'\') AS email,
                   COALESCE(l.phone, \'\') AS phone,
                   COALESCE(messages.name, \'\') AS message_name,
                   COALESCE(events.name, \'\')
                       AS campaign_action_name,
                   CASE
                       WHEN LOWER(TRIM(COALESCE(
                           q.provider_status,
                           \'\'
                       ))) IN (\'zender_sent\', \'sent\')
                       THEN \'sent_by_zender\'
                       WHEN q.status = \'blocked_account\'
                       THEN \'held\'
                       WHEN q.status = \'failed\'
                       THEN \'failed\'
                       WHEN (
                           q.status IN (\'pending\', \'queued\')
                           AND COALESCE(
                               attempts.attempt_count,
                               0
                           ) > 0
                       ) OR q.status LIKE \'%retry%\'
                       THEN \'retry_pending\'
                       WHEN q.status IN (
                           \'pending\',
                           \'queued\',
                           \'dispatching\'
                       )
                       THEN \'queued\'
                       ELSE \'failed\'
                   END AS visible_status
            FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' q
            INNER JOIN '.MAUTIC_TABLE_PREFIX.'leads l
              ON l.id = q.contact_id
            LEFT JOIN '.MAUTIC_TABLE_PREFIX.'zender_whatsapp_messages messages
              ON messages.id = q.whatsapp_message_id
            LEFT JOIN '.MAUTIC_TABLE_PREFIX.'campaign_events events
              ON events.id = q.campaign_event_id
            LEFT JOIN (
                SELECT queue_id,
                       COUNT(*) AS attempt_count,
                       MAX(COALESCE(finished_at, started_at))
                           AS last_attempt_at
                FROM '.MAUTIC_TABLE_PREFIX.'zender_dispatch_attempts
                GROUP BY queue_id
            ) attempts
              ON attempts.queue_id = q.id
            WHERE q.campaign_id = :campaign_status_campaign_id
              AND q.channel = :campaign_status_channel';
}

private function normalizeCampaignVisibleStatus(
    ?string $visibleStatus
): ?string {
    $visibleStatus = strtolower(trim((string) $visibleStatus));

    return in_array(
        $visibleStatus,
        [
            'queued',
            'sent_by_zender',
            'held',
            'retry_pending',
            'failed',
        ],
        true
    )
        ? $visibleStatus
        : null;
}

}
