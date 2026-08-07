<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;
use Throwable;

final class ZenderProviderStatusRepository
{
    private const TABLE = 'zender_dispatch_queue';
    private const LOCK_NAME = '7cats.mautic_zender.provider_status_sync';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    public function fetchCandidates(int $limit): array
    {
        $limit = max(1, min(5000, $limit));

        return $this->connection->fetchAllAssociative(
            'SELECT
                id,
                provider_message_id,
                provider_accepted_at,
                provider_status,
                provider_status_response_sha256
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE status = :status
               AND provider_message_id REGEXP :numeric_pattern
             ORDER BY id ASC
             LIMIT '.$limit,
            [
                'status' => 'dispatched',
                'numeric_pattern' => '^[0-9]+$',
            ]
        );
    }

    public function acquireLock(): bool
    {
        return 1 === (int) $this->connection->fetchOne(
            'SELECT GET_LOCK(:lock_name, 0)',
            ['lock_name' => self::LOCK_NAME]
        );
    }

    public function releaseLock(): void
    {
        $this->connection->fetchOne(
            'SELECT RELEASE_LOCK(:lock_name)',
            ['lock_name' => self::LOCK_NAME]
        );
    }

    /**
     * @param array<int, array{
     *     queue_id: int,
     *     provider_message_id: string,
     *     provider_status: string,
     *     observed_at: string,
     *     response_sha256: string
     * }> $observations
     */
    public function persistObservations(array $observations): int
    {
        if ([] === $observations) {
            return 0;
        }

        return $this->connection->transactional(
            function () use ($observations): int {
                $updated = 0;

                foreach ($observations as $observation) {
                    $updated += $this->connection->executeStatement(
                        'UPDATE '.MAUTIC_TABLE_PREFIX.self::TABLE.'
                         SET
                            provider_status = :provider_status,
                            provider_status_observed_at = :observed_at,
                            provider_status_response_sha256 = :response_sha256
                         WHERE id = :queue_id
                           AND provider_message_id = :provider_message_id
                           AND status = :queue_status
                           AND (
                                provider_status IS NULL
                                OR provider_status <> :provider_status_compare
                                OR provider_status_response_sha256 IS NULL
                                OR provider_status_response_sha256
                                    <> :response_sha256_compare
                           )',
                        [
                            'provider_status' => $observation['provider_status'],
                            'observed_at' => $observation['observed_at'],
                            'response_sha256' => (
                                $observation['response_sha256']
                            ),
                            'queue_id' => $observation['queue_id'],
                            'provider_message_id' => (
                                $observation['provider_message_id']
                            ),
                            'queue_status' => 'dispatched',
                            'provider_status_compare' => (
                                $observation['provider_status']
                            ),
                            'response_sha256_compare' => (
                                $observation['response_sha256']
                            ),
                        ]
                    );
                }

                return $updated;
            }
        );
    }

    public function safeReleaseLock(): void
    {
        try {
            $this->releaseLock();
        } catch (Throwable) {
        }
    }
}
