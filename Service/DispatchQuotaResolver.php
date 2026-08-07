<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use InvalidArgumentException;

final class DispatchQuotaResolver
{
    public function resolve(
        ?int $override,
        int $calculatedDefault
    ): int {
        $calculatedDefault = max(0, $calculatedDefault);

        if (null === $override) {
            return $calculatedDefault;
        }

        if ($override < 1) {
            throw new InvalidArgumentException(
                'daily_limit_override must be a positive integer or null.'
            );
        }

        return $override;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveFromRow(
        array $row,
        int $calculatedDefault
    ): int {
        $rawOverride = $row['daily_limit_override'] ?? null;

        return $this->resolve(
            null === $rawOverride
                ? null
                : (int) $rawOverride,
            $calculatedDefault
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    public function buildAccountLimitMap(
        array $rows,
        int $calculatedDefault
    ): array {
        $limits = [];

        foreach ($rows as $row) {
            $accountId = trim(
                (string) ($row['account_id'] ?? '')
            );

            if ('' === $accountId) {
                continue;
            }

            $limits[$accountId] = $this->resolveFromRow(
                $row,
                $calculatedDefault
            );
        }

        return $limits;
    }
}
