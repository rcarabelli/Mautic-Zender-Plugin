<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use InvalidArgumentException;

final class DispatchCapacityCalculator
{
    public const MIN_INTERVAL_SECONDS = 0;

    /**
     * @return array{
     *     window_start: string,
     *     window_end: string,
     *     window_seconds: int,
     *     dispatch_interval_seconds: int,
     *     active_account_count: int,
     *     cycles_per_day: int,
     *     capacity_per_device: int,
     *     total_daily_capacity: int
     * }
     */
    public function calculate(
        string $windowStart,
        string $windowEnd,
        int $dispatchIntervalSeconds,
        int $activeAccountCount
    ): array {
        $startSeconds = $this->parseTimeToSeconds(
            $windowStart,
            'window_start'
        );
        $endSeconds = $this->parseTimeToSeconds(
            $windowEnd,
            'window_end'
        );

        if ($endSeconds <= $startSeconds) {
            throw new InvalidArgumentException(
                'window_end must be later than window_start within the same day.'
            );
        }

        if (
            $dispatchIntervalSeconds
            < self::MIN_INTERVAL_SECONDS
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'dispatch_interval_seconds must be at least %d.',
                    self::MIN_INTERVAL_SECONDS
                )
            );
        }

        if ($activeAccountCount < 0) {
            throw new InvalidArgumentException(
                'active_account_count cannot be negative.'
            );
        }

        $windowSeconds = $endSeconds - $startSeconds;
        $capacityDivisor = max(
            1,
            $dispatchIntervalSeconds
        );
        $cyclesPerDay = intdiv(
            $windowSeconds,
            $capacityDivisor
        );

        return [
            'window_start' => $this->normalizeTime(
                $startSeconds
            ),
            'window_end' => $this->normalizeTime(
                $endSeconds
            ),
            'window_seconds' => $windowSeconds,
            'dispatch_interval_seconds' => (
                $dispatchIntervalSeconds
            ),
            'active_account_count' => $activeAccountCount,
            'cycles_per_day' => $cyclesPerDay,
            'capacity_per_device' => $cyclesPerDay,
            'total_daily_capacity' => (
                $cyclesPerDay * $activeAccountCount
            ),
        ];
    }

    private function parseTimeToSeconds(
        string $value,
        string $field
    ): int {
        $value = trim($value);

        if (
            1 !== preg_match(
                '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
                $value
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s must use HH:MM or HH:MM:SS format.',
                    $field
                )
            );
        }

        $parts = array_map(
            static fn (string $part): int => (int) $part,
            explode(':', $value)
        );

        $hours = $parts[0];
        $minutes = $parts[1];
        $seconds = $parts[2] ?? 0;

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function normalizeTime(int $seconds): string
    {
        return sprintf(
            '%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60)
        );
    }
}
