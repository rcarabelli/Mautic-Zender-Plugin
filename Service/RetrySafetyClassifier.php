<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

final class RetrySafetyClassifier
{
    public const DECISION_NONE = 'none';
    public const DECISION_SAFE_RETRY = 'safe_retry';
    public const DECISION_HOLD_FOR_ASSIGNED_ACCOUNT =
        'hold_for_assigned_account';
    public const DECISION_PERMANENT_FAILURE = 'permanent_failure';
    public const DECISION_MANUAL_REVIEW = 'manual_review';

    /**
     * Classify retry safety without scheduling or executing a retry.
     *
     * The policy is intentionally conservative:
     * - accepted outcomes never retry;
     * - failures before any provider request are safe to retry later;
     * - explicit transient HTTP responses may be retried later;
     * - explicit permanent client errors are blocked;
     * - ambiguous provider-started outcomes require manual review.
     *
     * @param array<string, mixed> $diagnostic
     */
    public function classify(array $diagnostic): string
    {
        $classification = $this->normalizeClassification(
            (string) ($diagnostic['classification'] ?? 'provider_result_missing')
        );
        $success = true === ($diagnostic['success'] ?? false);
        $providerRequestStarted = true === (
            $diagnostic['provider_request_started'] ?? false
        );
        $providerMessageId = trim(
            (string) ($diagnostic['provider_message_id'] ?? '')
        );
        $httpStatus = isset($diagnostic['http_status'])
            && is_numeric($diagnostic['http_status'])
            ? (int) $diagnostic['http_status']
            : null;

        if (
            $success
            || in_array(
                $classification,
                ['legacy_status_200', 'legacy_status_success'],
                true
            )
        ) {
            return self::DECISION_NONE;
        }

        // A cooldown race is not an attempt and must never become a retry.
        if ('cooldown_not_eligible' === $classification) {
            return self::DECISION_NONE;
        }

        // A provider message ID on a non-success result is contradictory evidence.
        if ('' !== $providerMessageId) {
            return self::DECISION_MANUAL_REVIEW;
        }

        if ('account_disconnected' === $classification) {
            return self::DECISION_HOLD_FOR_ASSIGNED_ACCOUNT;
        }

        // No provider request means there is no duplicate-send risk.
        if (!$providerRequestStarted) {
            return self::DECISION_SAFE_RETRY;
        }

        if ('http_non_2xx' === $classification && null !== $httpStatus) {
            if (
                in_array(
                    $httpStatus,
                    [408, 425, 429, 500, 502, 503, 504],
                    true
                )
            ) {
                return self::DECISION_SAFE_RETRY;
            }

            if ($httpStatus >= 400 && $httpStatus < 500) {
                return self::DECISION_PERMANENT_FAILURE;
            }
        }

        return self::DECISION_MANUAL_REVIEW;
    }

    private function normalizeClassification(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:-]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return '' === $value
            ? 'provider_result_missing'
            : substr($value, 0, 64);
    }
}
