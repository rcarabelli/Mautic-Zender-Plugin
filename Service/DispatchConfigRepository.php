<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;

final class DispatchConfigRepository
{
    private const TABLE = 'zender_dispatch_config';

    public function __construct(private Connection $connection)
    {
    }

    public function get(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' WHERE id = 1'
        );

        if (!$row) {
            return $this->defaults();
        }

        return [
            'enabled' => (bool) $row['enabled'],
            'timezone' => (string) $row['timezone'],
            'window_start' => substr(
                (string) $row['window_start'],
                0,
                5
            ),
            'window_end' => substr(
                (string) $row['window_end'],
                0,
                5
            ),
            'global_daily_limit' => (
                (int) $row['global_daily_limit']
            ),
            'per_account_daily_limit' => (
                (int) $row['per_account_daily_limit']
            ),
            'batch_size' => (int) $row['batch_size'],
            'dispatch_interval_seconds' => (
                (int) $row['dispatch_interval_seconds']
            ),
            'max_attempts' => (int) $row['max_attempts'],
            'retry_delay_seconds' => (
                (int) $row['retry_delay_seconds']
            ),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->get()['enabled'];
    }

    public function setEnabled(bool $enabled): array
    {
        $this->connection->update(
            MAUTIC_TABLE_PREFIX.self::TABLE,
            [
                'enabled' => $enabled ? 1 : 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => 1]
        );

        return $this->get();
    }

    public function update(array $changes): array
    {
        $allowed = [
            'timezone',
            'window_start',
            'window_end',
            'global_daily_limit',
            'per_account_daily_limit',
            'batch_size',
            'dispatch_interval_seconds',
            'max_attempts',
            'retry_delay_seconds',
        ];

        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $changes)) {
                $payload[$key] = $changes[$key];
            }
        }

        if ($payload) {
            $payload['updated_at'] = gmdate('Y-m-d H:i:s');
            $this->connection->update(
                MAUTIC_TABLE_PREFIX.self::TABLE,
                $payload,
                ['id' => 1]
            );
        }

        return $this->get();
    }

    private function defaults(): array
    {
        return [
            'enabled' => false,
            'timezone' => 'America/Lima',
            'window_start' => '08:00',
            'window_end' => '21:00',
            'global_daily_limit' => 1400,
            'per_account_daily_limit' => 280,
            'batch_size' => 5,
            'dispatch_interval_seconds' => 165,
            'max_attempts' => 1,
            'retry_delay_seconds' => 900,
        ];
    }
}
