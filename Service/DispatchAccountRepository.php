<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final class DispatchAccountRepository
{
    private const TABLE = 'zender_dispatch_accounts';

    public function __construct(private Connection $connection)
    {
    }

    public function isEnabledAccount(string $accountId): bool
    {
        if ('' === trim($accountId)) {
            return false;
        }

        return 1 === (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE BINARY account_id = BINARY ?
               AND enabled = 1',
            [$accountId]
        );
    }

    public function getEnabledAccounts(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT account_id, label, phone, dispatch_order, daily_limit,
                    daily_limit_override
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE enabled = 1
             ORDER BY dispatch_order ASC'
        );
    }


    public function getDispatchEligibleAccounts(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT account_id, label, phone, dispatch_order, daily_limit,
                    daily_limit_override, paused_until
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE enabled = 1
               AND (
                    paused_until IS NULL
                    OR paused_until <= UTC_TIMESTAMP()
               )
             ORDER BY dispatch_order ASC'
        );
    }

    public function getStatusRows(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, account_id, label, phone, dispatch_order,
                    daily_limit, daily_limit_override, enabled,
                    last_attempt_started_at, next_eligible_at,
                    paused_until
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             ORDER BY dispatch_order ASC, id ASC'
        );

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['daily_limit'] = max(
                0,
                (int) ($row['daily_limit'] ?? 0)
            );
            $row['daily_limit_override'] = null === (
                $row['daily_limit_override'] ?? null
            )
                ? null
                : (int) $row['daily_limit_override'];
            $row['account_hash'] = substr(
                hash('sha256', (string) $row['account_id']),
                0,
                12
            );
            unset($row['account_id']);
        }

        return $rows;
    }

    /**
     * @return array<string,int>
     */
    public function getLeadCountsByAccountId(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id_whatsapp_in_zender AS account_id,
                    COUNT(*) AS lead_count
             FROM '.MAUTIC_TABLE_PREFIX.'leads
             WHERE id_whatsapp_in_zender IS NOT NULL
               AND TRIM(id_whatsapp_in_zender) <> \'\'
             GROUP BY id_whatsapp_in_zender'
        );

        $counts = [];

        foreach ($rows as $row) {
            $accountId = trim(
                (string) ($row['account_id'] ?? '')
            );

            if ('' === $accountId) {
                continue;
            }

            $counts[$accountId] = max(
                0,
                (int) ($row['lead_count'] ?? 0)
            );
        }

        return $counts;
    }

    public function getAllIds(): array
    {
        return array_map(
            'intval',
            $this->connection->fetchFirstColumn(
                'SELECT id
                 FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
                 ORDER BY id ASC'
            )
        );
    }

    public function applyEnabledIds(
        array $enabledIds,
        int $dailyLimit
    ): array {
        $allowed = array_flip($this->getAllIds());
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $enabledIds),
            static fn (int $id): bool => (
                $id > 0 && isset($allowed[$id])
            )
        )));
        sort($ids);

        $now = gmdate('Y-m-d H:i:s');
        $dailyLimit = max(0, $dailyLimit);
        $table = MAUTIC_TABLE_PREFIX.self::TABLE;

        $this->connection->executeStatement(
            'UPDATE '.$table.'
             SET enabled = 0,
                 daily_limit = ?,
                 updated_at = ?',
            [$dailyLimit, $now]
        );

        if ([] !== $ids) {
            $placeholders = implode(
                ',',
                array_fill(0, count($ids), '?')
            );
            $this->connection->executeStatement(
                'UPDATE '.$table.'
                 SET enabled = 1,
                     daily_limit = ?,
                     updated_at = ?
                 WHERE id IN ('.$placeholders.')',
                array_merge([$dailyLimit, $now], $ids)
            );
        }

        return $this->getStatusRows();
    }

    /**
     * Lock and return the complete managed account set for a
     * Phase 5A administration transaction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getManagedRowsForUpdate(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, label, dispatch_order, enabled, daily_limit,
                    daily_limit_override,
                    last_attempt_started_at, next_eligible_at,
                    paused_until
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             ORDER BY id ASC
             FOR UPDATE'
        );

        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['dispatch_order'] = (int) (
                $row['dispatch_order'] ?? 0
            );
            $row['enabled'] = (int) ($row['enabled'] ?? 0);
            $row['daily_limit'] = (int) (
                $row['daily_limit'] ?? 0
            );
            $row['daily_limit_override'] = null === (
                $row['daily_limit_override'] ?? null
            )
                ? null
                : (int) $row['daily_limit_override'];
        }
        unset($row);

        return $rows;
    }

    public function updateTemporaryPause(
        int $id,
        ?string $pausedUntilUtc
    ): array {
        if ($id < 1) {
            throw new InvalidArgumentException(
                'Phase 5C local account ID is invalid.'
            );
        }

        $affected = $this->connection->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             SET paused_until = ?,
                 updated_at = ?
             WHERE id = ?',
            [
                $pausedUntilUtc,
                gmdate('Y-m-d H:i:s'),
                $id,
            ]
        );

        if (1 !== $affected) {
            throw new InvalidArgumentException(
                'Phase 5C local account ID was not found.'
            );
        }

        return $this->getStatusRows();
    }
    /**
     * Update only nullable per-account daily quota overrides.
     *
     * @param array<int, ?int> $overridesById
     */
    public function updateDailyLimitOverrides(
        array $overridesById
    ): array {
        $table = MAUTIC_TABLE_PREFIX.self::TABLE;
        $now = gmdate('Y-m-d H:i:s');

        foreach ($overridesById as $id => $override) {
            $id = (int) $id;

            if (
                $id < 1
                || (
                    null !== $override
                    && $override < 1
                )
            ) {
                throw new InvalidArgumentException(
                    'Phase 5B quota override payload is invalid.'
                );
            }

            $this->connection->executeStatement(
                'UPDATE '.$table.'
                 SET daily_limit_override = ?,
                     updated_at = ?
                 WHERE id = ?',
                [
                    $override,
                    $now,
                    $id,
                ]
            );
        }

        return $this->getStatusRows();
    }
    /**
     * Update only alias and administrative order.
     *
     * @param array<int, string> $labelsById
     * @param array<int, int> $orderedIds
     */
    public function updateAdministration(
        array $labelsById,
        array $orderedIds
    ): array {
        $table = MAUTIC_TABLE_PREFIX.self::TABLE;
        $now = gmdate('Y-m-d H:i:s');
        $accountCount = count($orderedIds);

        if ($accountCount < 1) {
            throw new InvalidArgumentException(
                'Phase 5A administration payload is incomplete.'
            );
        }

        foreach ($orderedIds as $id) {
            $id = (int) $id;

            if ($id < 1 || !array_key_exists($id, $labelsById)) {
                throw new InvalidArgumentException(
                    'Phase 5A administration payload is incomplete.'
                );
            }
        }

        $persistedOrders = array_map(
            'intval',
            $this->connection->fetchFirstColumn(
                'SELECT dispatch_order
                 FROM '.$table.'
                 ORDER BY id ASC
                 FOR UPDATE'
            )
        );
        $usedOrders = array_fill_keys($persistedOrders, true);
        $temporaryOrders = [];

        for (
            $candidate = 65535;
            $candidate >= 1
                && count($temporaryOrders) < $accountCount;
            --$candidate
        ) {
            if (!isset($usedOrders[$candidate])) {
                $temporaryOrders[] = $candidate;
            }
        }

        if (count($temporaryOrders) !== $accountCount) {
            throw new \RuntimeException(
                'No collision-safe temporary dispatch order band is available.'
            );
        }

        // COLLISION_SAFE_TWO_PHASE_ORDER_BEGIN
        foreach ($orderedIds as $index => $id) {
            $this->connection->executeStatement(
                'UPDATE '.$table.'
                 SET dispatch_order = ?
                 WHERE id = ?',
                [
                    $temporaryOrders[$index],
                    (int) $id,
                ]
            );
        }

        foreach ($orderedIds as $index => $id) {
            $id = (int) $id;
            $this->connection->executeStatement(
                'UPDATE '.$table.'
                 SET label = ?,
                     dispatch_order = ?,
                     updated_at = ?
                 WHERE id = ?',
                [
                    $labelsById[$id],
                    $index + 1,
                    $now,
                    $id,
                ]
            );
        }
        // COLLISION_SAFE_TWO_PHASE_ORDER_END

        return $this->getStatusRows();
    }

    // QUEUE_DRIVEN_COOLDOWN_3A1_BEGIN
    /**
     * Atomically begin the independent cooldown for a real provider attempt.
     *
     * The update succeeds only for an enabled account whose existing cooldown
     * is absent or expired. The returned timestamps are UTC database values.
     * This method does not call the provider and is not connected to live
     * dispatch until a later boundary.
     *
     * @return array{last_attempt_started_at: string, next_eligible_at: string}|null
     */
    public function beginProviderAttemptCooldown(
        string $accountId,
        int $intervalSeconds
    ): ?array {
        $accountId = trim($accountId);
        if ('' === $accountId) {
            return null;
        }

        $intervalSeconds = max(0, min(86400, $intervalSeconds));
        $table = MAUTIC_TABLE_PREFIX.self::TABLE;

        $updated = $this->connection->executeStatement(
            'UPDATE '.$table.'
             SET last_attempt_started_at = UTC_TIMESTAMP(),
                 next_eligible_at = DATE_ADD(
                     UTC_TIMESTAMP(),
                     INTERVAL '.$intervalSeconds.' SECOND
                 ),
                 updated_at = UTC_TIMESTAMP()
             WHERE BINARY account_id = BINARY :account_id
               AND enabled = 1
               AND (
                   next_eligible_at IS NULL
                   OR next_eligible_at <= UTC_TIMESTAMP()
               )',
            ['account_id' => $accountId]
        );

        if (1 !== $updated) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT last_attempt_started_at, next_eligible_at
             FROM '.$table.'
             WHERE BINARY account_id = BINARY :account_id',
            ['account_id' => $accountId]
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'last_attempt_started_at' => (string) (
                $row['last_attempt_started_at'] ?? ''
            ),
            'next_eligible_at' => (string) (
                $row['next_eligible_at'] ?? ''
            ),
        ];
    }

    /**
     * Check the independent cooldown without changing persistent state.
     */
    public function isCooldownEligible(string $accountId): bool
    {
        $accountId = trim($accountId);
        if ('' === $accountId) {
            return false;
        }

        return 1 === (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE BINARY account_id = BINARY :account_id
               AND enabled = 1
               AND (
                   next_eligible_at IS NULL
                   OR next_eligible_at <= UTC_TIMESTAMP()
               )',
            ['account_id' => $accountId]
        );
    }
    // QUEUE_DRIVEN_COOLDOWN_3A1_END
    public function countEnabled(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE enabled = 1'
        );
    }
}
