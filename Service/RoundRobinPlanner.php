<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

final class RoundRobinPlanner
{
    public function plan(
        array $rows,
        int $limit,
        array $usedByAccount = [],
        int $perAccountLimit = PHP_INT_MAX,
        int $maxPerAccountPerPlan = PHP_INT_MAX,
        array $accountLimits = []
    ): array {
        $limit = max(0, $limit);
        $maxPerAccountPerPlan = max(1, $maxPerAccountPerPlan);

        if (0 === $limit || !$rows) {
            return [];
        }

        $buckets = [];
        foreach ($rows as $row) {
            $accountId = (string) ($row['account_id'] ?? '');
            if ('' === $accountId) {
                continue;
            }

            $effectiveLimit = $this->resolveAccountLimit(
                $accountId,
                $perAccountLimit,
                $accountLimits
            );

            if (($usedByAccount[$accountId] ?? 0) >= $effectiveLimit) {
                continue;
            }

            if (!isset($buckets[$accountId])) {
                $buckets[$accountId] = [
                    'dispatch_order' => (int) ($row['dispatch_order'] ?? PHP_INT_MAX),
                    'rows'           => [],
                ];
            }

            $buckets[$accountId]['rows'][] = $row;
        }

        uasort(
            $buckets,
            static function (array $left, array $right): int {
                return $left['dispatch_order'] <=> $right['dispatch_order'];
            }
        );

        $plan = [];
        $selectedByAccount = [];

        while ($buckets && count($plan) < $limit) {
            $progress = false;

            foreach (array_keys($buckets) as $accountId) {
                if (count($plan) >= $limit) {
                    break;
                }

                $dailyUsed = $usedByAccount[$accountId] ?? 0;
                $selectedNow = $selectedByAccount[$accountId] ?? 0;

                $effectiveLimit = $this->resolveAccountLimit(
                    $accountId,
                    $perAccountLimit,
                    $accountLimits
                );

                if ($dailyUsed >= $effectiveLimit
                    || $selectedNow >= $maxPerAccountPerPlan) {
                    unset($buckets[$accountId]);
                    continue;
                }

                $row = array_shift($buckets[$accountId]['rows']);
                if (null === $row) {
                    unset($buckets[$accountId]);
                    continue;
                }

                $plan[] = $row;
                $usedByAccount[$accountId] = $dailyUsed + 1;
                $selectedByAccount[$accountId] = $selectedNow + 1;
                $progress = true;

                if (!$buckets[$accountId]['rows']) {
                    unset($buckets[$accountId]);
                }
            }

            if (!$progress) {
                break;
            }
        }

        return $plan;
    }

    /**
     * Resolve one account quota while preserving the scalar default contract.
     *
     * @param array<string, int> $accountLimits
     */
    private function resolveAccountLimit(
        string $accountId,
        int $defaultLimit,
        array $accountLimits
    ): int {
        $defaultLimit = max(0, $defaultLimit);

        if (!array_key_exists($accountId, $accountLimits)) {
            return $defaultLimit;
        }

        return max(0, (int) $accountLimits[$accountId]);
    }
}
