<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final class DispatchSettingsWriter
{
    public const MAX_INTERVAL_SECONDS = 86400;
    public const MAX_ACCOUNT_LABEL_LENGTH = 120;

    public function __construct(
        private Connection $connection,
        private DispatchConfigRepository $configRepository,
        private DispatchAccountRepository $accountRepository,
        private DispatchCapacityCalculator $capacityCalculator
    ) {
    }

    /**
     * @param array<int, int|string> $enabledAccountIds
     *
     * @return array{
     *     config: array,
     *     accounts: array,
     *     capacity: array{
     *         window_start: string,
     *         window_end: string,
     *         window_seconds: int,
     *         dispatch_interval_seconds: int,
     *         active_account_count: int,
     *         cycles_per_day: int,
     *         capacity_per_device: int,
     *         total_daily_capacity: int
     *     }
     * }
     */
    public function save(
        bool $dispatcherEnabled,
        string $windowStart,
        string $windowEnd,
        int $dispatchIntervalSeconds,
        array $enabledAccountIds
    ): array {
        if (
            $dispatchIntervalSeconds
            > self::MAX_INTERVAL_SECONDS
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'dispatch_interval_seconds cannot exceed %d.',
                    self::MAX_INTERVAL_SECONDS
                )
            );
        }

        $normalizedIds = $this->normalizeAccountIds(
            $enabledAccountIds
        );
        $knownIds = $this->accountRepository->getAllIds();
        $unknownIds = array_values(array_diff(
            $normalizedIds,
            $knownIds
        ));

        if ([] !== $unknownIds) {
            throw new InvalidArgumentException(
                'enabled_account_ids contains unknown account IDs.'
            );
        }

        $capacity = $this->capacityCalculator->calculate(
            $windowStart,
            $windowEnd,
            $dispatchIntervalSeconds,
            count($normalizedIds)
        );

        if ($capacity['capacity_per_device'] < 1) {
            throw new InvalidArgumentException(
                'The selected operating window and interval produce zero capacity.'
            );
        }

        return $this->connection->transactional(
            function () use (
                $dispatcherEnabled,
                $capacity,
                $normalizedIds
            ): array {
                $this->configRepository->setEnabled(
                    $dispatcherEnabled
                );

                $config = $this->configRepository->update([
                    'window_start' => $capacity['window_start'],
                    'window_end' => $capacity['window_end'],
                    'dispatch_interval_seconds' => (
                        $capacity['dispatch_interval_seconds']
                    ),
                    'per_account_daily_limit' => (
                        $capacity['capacity_per_device']
                    ),
                    'global_daily_limit' => (
                        $capacity['total_daily_capacity']
                    ),
                ]);

                $accounts = $this
                    ->accountRepository
                    ->applyEnabledIds(
                        $normalizedIds,
                        $capacity['capacity_per_device']
                    );

                return [
                    'config' => $config,
                    'accounts' => $accounts,
                    'capacity' => $capacity,
                ];
            }
        );
    }

    /**
     * Save only the enabled account set while preserving the
     * dispatcher state, operating window, and interval.
     *
     * @param array<int, int|string> $enabledAccountIds
     */
    public function saveAccounts(
        array $enabledAccountIds
    ): array {
        if ([] === $enabledAccountIds) {
            throw new InvalidArgumentException(
                'At least one sending account must remain enabled.'
            );
        }

        $config = $this->configRepository->get();

        return $this->save(
            (bool) ($config['enabled'] ?? false),
            (string) (
                $config['window_start'] ?? '08:00'
            ),
            (string) (
                $config['window_end'] ?? '21:00'
            ),
            (int) (
                $config['dispatch_interval_seconds'] ?? 165
            ),
            $enabledAccountIds
        );
    }

    public function saveAccountPause(
        int $accountId,
        string $pausedUntilLocal
    ): array {
        return $this->connection->transactional(
            function () use (
                $accountId,
                $pausedUntilLocal
            ): array {
                $knownIds = array_map(
                    static fn (array $row): int => (int) (
                        $row['id'] ?? 0
                    ),
                    $this->accountRepository
                        ->getManagedRowsForUpdate()
                );

                if (
                    $accountId < 1
                    || !in_array(
                        $accountId,
                        $knownIds,
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        'pause_account_id is invalid.'
                    );
                }

                $value = trim($pausedUntilLocal);
                $pausedUntilUtc = null;

                if ('' !== $value) {
                    $config = $this->configRepository->get();
                    $timezone = new \DateTimeZone(
                        (string) (
                            $config['timezone']
                            ?? 'America/Lima'
                        )
                    );
                    $date = \DateTimeImmutable::createFromFormat(
                        'Y-m-d\TH:i',
                        $value,
                        $timezone
                    );
                    $errors = \DateTimeImmutable::getLastErrors();

                    if (
                        false === $date
                        || (
                            is_array($errors)
                            && (
                                $errors['warning_count'] > 0
                                || $errors['error_count'] > 0
                            )
                        )
                        || $date <= new \DateTimeImmutable(
                            'now',
                            $timezone
                        )
                    ) {
                        throw new InvalidArgumentException(
                            'paused_until_local must be a future local date and time.'
                        );
                    }

                    $pausedUntilUtc = $date
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s');
                }

                return $this->accountRepository
                    ->updateTemporaryPause(
                        $accountId,
                        $pausedUntilUtc
                    );
            }
        );
    }
    /**
     * Save nullable per-account quota overrides without changing
     * calculated limits, enabled state, order, or cooldown.
     *
     * @param array<int|string, mixed> $values
     */
    public function saveAccountQuotaOverrides(
        array $values
    ): array {
        return $this->connection->transactional(
            function () use ($values): array {
                $rows = $this->accountRepository
                    ->getManagedRowsForUpdate();
                $knownIds = array_map(
                    static fn (array $row): int => (int) (
                        $row['id'] ?? 0
                    ),
                    $rows
                );

                $normalized = $this
                    ->normalizeAccountQuotaOverrides(
                        $values,
                        $knownIds
                    );

                return $this->accountRepository
                    ->updateDailyLimitOverrides(
                        $normalized
                    );
            }
        );
    }
    /**
     * Save account aliases and administrative order without
     * changing enabled state, quota, cooldown, or queue ownership.
     *
     * @param array<int|string, mixed> $accountLabels
     * @param array<int|string, mixed> $accountOrder
     */
    public function saveAccountAdministration(
        array $accountLabels,
        array $accountOrder
    ): array {
        return $this->connection->transactional(
            function () use (
                $accountLabels,
                $accountOrder
            ): array {
                $rows = $this->accountRepository
                    ->getManagedRowsForUpdate();
                $knownIds = array_map(
                    static fn (array $row): int => (int) (
                        $row['id'] ?? 0
                    ),
                    $rows
                );

                $normalizedLabels = $this
                    ->normalizeAccountLabels(
                        $accountLabels,
                        $knownIds
                    );
                $normalizedOrder = $this
                    ->normalizeAccountOrder(
                        $accountOrder,
                        $knownIds
                    );

                return $this->accountRepository
                    ->updateAdministration(
                        $normalizedLabels,
                        $normalizedOrder
                    );
            }
        );
    }

    /**
     * Save only the dispatcher state while preserving the
     * current operating window, interval, and enabled accounts.
     */
    public function saveDispatcher(
        bool $enabled
    ): array {
        $config = $this->configRepository->get();
        $enabledAccountIds = [];

        foreach (
            $this->accountRepository->getStatusRows()
            as $account
        ) {
            if (1 === (int) ($account['enabled'] ?? 0)) {
                $enabledAccountIds[] = (int) (
                    $account['id'] ?? 0
                );
            }
        }

        return $this->save(
            $enabled,
            (string) (
                $config['window_start'] ?? '08:00'
            ),
            (string) (
                $config['window_end'] ?? '21:00'
            ),
            (int) (
                $config['dispatch_interval_seconds'] ?? 165
            ),
            $enabledAccountIds
        );
    }

    /**
     * Save only the operating window while preserving the
     * current dispatcher state, interval, and enabled accounts.
     */
    public function saveWindow(
        string $windowStart,
        string $windowEnd
    ): array {
        $config = $this->configRepository->get();
        $enabledAccountIds = [];

        foreach (
            $this->accountRepository->getStatusRows()
            as $account
        ) {
            if (1 === (int) ($account['enabled'] ?? 0)) {
                $enabledAccountIds[] = (int) (
                    $account['id'] ?? 0
                );
            }
        }

        return $this->save(
            (bool) ($config['enabled'] ?? false),
            $windowStart,
            $windowEnd,
            (int) (
                $config['dispatch_interval_seconds'] ?? 165
            ),
            $enabledAccountIds
        );
    }

    /**
     * Save only the dispatch interval while preserving the
     * current dispatcher state, window, and enabled accounts.
     */
    public function saveInterval(
        int $dispatchIntervalSeconds
    ): array {
        $config = $this->configRepository->get();
        $enabledAccountIds = [];

        foreach (
            $this->accountRepository->getStatusRows()
            as $account
        ) {
            if (1 === (int) ($account['enabled'] ?? 0)) {
                $enabledAccountIds[] = (int) (
                    $account['id'] ?? 0
                );
            }
        }

        return $this->save(
            (bool) ($config['enabled'] ?? false),
            (string) (
                $config['window_start'] ?? '08:00'
            ),
            (string) (
                $config['window_end'] ?? '21:00'
            ),
            $dispatchIntervalSeconds,
            $enabledAccountIds
        );
    }

    /**
     * @param array<int|string, mixed> $values
     * @param array<int, int> $knownIds
     *
     * @return array<int, ?int>
     */
    private function normalizeAccountQuotaOverrides(
        array $values,
        array $knownIds
    ): array {
        $allowed = array_fill_keys($knownIds, true);
        $normalized = [];

        foreach ($values as $key => $value) {
            if (
                !is_int($key)
                && !(
                    is_string($key)
                    && 1 === preg_match('/^[0-9]+$/', $key)
                )
            ) {
                throw new InvalidArgumentException(
                    'account_daily_limit_override keys must be local account IDs.'
                );
            }

            $id = (int) $key;

            if ($id < 1 || !isset($allowed[$id])) {
                throw new InvalidArgumentException(
                    'account_daily_limit_override contains an unknown account ID.'
                );
            }

            if (null === $value) {
                $normalized[$id] = null;
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);

                if ('' === $value) {
                    $normalized[$id] = null;
                    continue;
                }

                if (1 !== preg_match('/^[0-9]+$/', $value)) {
                    throw new InvalidArgumentException(
                        'account_daily_limit_override values must be blank or positive integers.'
                    );
                }
            } elseif (!is_int($value)) {
                throw new InvalidArgumentException(
                    'account_daily_limit_override values must be blank or positive integers.'
                );
            }

            $override = (int) $value;

            if (
                $override < 1
                || $override > 4294967295
            ) {
                throw new InvalidArgumentException(
                    'account_daily_limit_override is outside the unsigned integer range.'
                );
            }

            $normalized[$id] = $override;
        }

        $normalizedIds = array_keys($normalized);
        sort($normalizedIds);
        $expectedIds = $knownIds;
        sort($expectedIds);

        if ($normalizedIds !== $expectedIds) {
            throw new InvalidArgumentException(
                'account_daily_limit_override must contain every managed account exactly once.'
            );
        }

        return $normalized;
    }
    /**
     * @param array<int|string, mixed> $values
     * @param array<int, int> $knownIds
     *
     * @return array<int, string>
     */
    private function normalizeAccountLabels(
        array $values,
        array $knownIds
    ): array {
        $allowed = array_fill_keys($knownIds, true);
        $normalized = [];

        foreach ($values as $key => $value) {
            if (
                !is_int($key)
                && !(
                    is_string($key)
                    && 1 === preg_match('/^[0-9]+$/', $key)
                )
            ) {
                throw new InvalidArgumentException(
                    'account_label keys must be local account IDs.'
                );
            }

            $id = (int) $key;

            if ($id < 1 || !isset($allowed[$id])) {
                throw new InvalidArgumentException(
                    'account_label contains an unknown account ID.'
                );
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException(
                    'account_label values must be strings.'
                );
            }

            $label = trim($value);

            if (
                '' === $label
                || mb_strlen($label)
                    > self::MAX_ACCOUNT_LABEL_LENGTH
            ) {
                throw new InvalidArgumentException(
                    'account_label values must contain between 1 and 120 characters.'
                );
            }

            $normalized[$id] = $label;
        }

        $normalizedIds = array_keys($normalized);
        sort($normalizedIds);
        $expectedIds = $knownIds;
        sort($expectedIds);

        if ($normalizedIds !== $expectedIds) {
            throw new InvalidArgumentException(
                'account_label must contain every managed account exactly once.'
            );
        }

        return $normalized;
    }

    /**
     * @param array<int|string, mixed> $values
     * @param array<int, int> $knownIds
     *
     * @return array<int, int>
     */
    private function normalizeAccountOrder(
        array $values,
        array $knownIds
    ): array {
        $allowed = array_fill_keys($knownIds, true);
        $positions = [];
        $usedPositions = [];

        foreach ($values as $key => $value) {
            if (
                !is_int($key)
                && !(
                    is_string($key)
                    && 1 === preg_match('/^[0-9]+$/', $key)
                )
            ) {
                throw new InvalidArgumentException(
                    'account_order keys must be local account IDs.'
                );
            }

            $id = (int) $key;

            if ($id < 1 || !isset($allowed[$id])) {
                throw new InvalidArgumentException(
                    'account_order contains an unknown account ID.'
                );
            }

            if (is_int($value)) {
                $position = $value;
            } elseif (
                is_string($value)
                && 1 === preg_match('/^[0-9]+$/', $value)
            ) {
                $position = (int) $value;
            } else {
                throw new InvalidArgumentException(
                    'account_order values must be positive integers.'
                );
            }

            if ($position < 1 || isset($usedPositions[$position])) {
                throw new InvalidArgumentException(
                    'account_order positions must be unique positive integers.'
                );
            }

            $positions[$id] = $position;
            $usedPositions[$position] = true;
        }

        $positionIds = array_keys($positions);
        sort($positionIds);
        $expectedIds = $knownIds;
        sort($expectedIds);

        if ($positionIds !== $expectedIds) {
            throw new InvalidArgumentException(
                'account_order must contain every managed account exactly once.'
            );
        }

        asort($positions, SORT_NUMERIC);

        return array_map('intval', array_keys($positions));
    }

    /**
     * @param array<int, int|string> $values
     *
     * @return array<int, int>
     */
    private function normalizeAccountIds(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (
                is_string($value)
                && 1 === preg_match('/^[0-9]+$/', $value)
            ) {
                $id = (int) $value;
            } else {
                throw new InvalidArgumentException(
                    'enabled_account_ids must contain only positive integers.'
                );
            }

            if ($id < 1) {
                throw new InvalidArgumentException(
                    'enabled_account_ids must contain only positive integers.'
                );
            }

            $normalized[$id] = $id;
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }
}
