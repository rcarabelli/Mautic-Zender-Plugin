<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ZenderControlReadModel
{
    public function __construct(
        private readonly DispatchConfigRepository $configRepository,
        private readonly DispatchAccountRepository $accountRepository,
        private readonly DispatchQueueRepository $queueRepository,
        private readonly ZenderReceivedChatRepository $receivedChatRepository,
        private readonly ZenderAccountApiClient $accountApiClient,
    ) {
    }

    public function getDashboard(): array
    {
        $config = $this->normalizeConfig(
            $this->configRepository->get()
        );
        [$startUtc, $endUtc] = $this->todayUtcBounds(
            $config['timezone']
        );
        $nowUtc = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );
        $displayTimezone = new DateTimeZone(
            (string) $config['timezone']
        );

        $queue = [
            'pending' => 0,
            'dispatching' => 0,
            'dispatched' => 0,
            'failed' => 0,
            'other' => 0,
        ];

        foreach ($this->queueRepository->getStatusCounts() as $row) {
            $status = strtolower(
                trim((string) ($row['status'] ?? ''))
            );
            $quantity = max(
                0,
                (int) ($row['quantity'] ?? 0)
            );

            if (array_key_exists($status, $queue)) {
                $queue[$status] = $quantity;
            } else {
                $queue['other'] += $quantity;
            }
        }

        $pendingStateByHash = [];

        foreach (
            $this->queueRepository
                ->getPendingDispatchStateByAccount()
            as $row
        ) {
            $accountId = (string) ($row['account_id'] ?? '');

            if ('' !== $accountId) {
                $pendingStateByHash[
                    $this->accountHash($accountId)
                ] = [
                    'pending_total' => max(
                        0,
                        (int) ($row['pending_total'] ?? 0)
                    ),
                    'available_now' => max(
                        0,
                        (int) ($row['available_now'] ?? 0)
                    ),
                ];
            }
        }

        $dispatchedByHash = [];

        foreach (
            $this->queueRepository
                ->countDispatchedByAccountBetween(
                    $startUtc,
                    $endUtc
                )
            as $accountId => $quantity
        ) {
            $dispatchedByHash[
                $this->accountHash((string) $accountId)
            ] = max(0, (int) $quantity);
        }

        $enabledByHash = [];

        foreach (
            $this->accountRepository->getEnabledAccounts()
            as $row
        ) {
            $accountId = trim(
                (string) ($row['account_id'] ?? '')
            );

            if ('' === $accountId) {
                continue;
            }

            $enabledByHash[$this->accountHash($accountId)] = [
                'account_id' => $accountId,
                'phone' => trim(
                    (string) ($row['phone'] ?? '')
                ),
            ];
        }

        $liveSnapshot = $this->accountApiClient
            ->getAccountSnapshot();

        $liveByUnique = [];
        $liveByPhone = [];

        foreach (
            (array) ($liveSnapshot['accounts'] ?? [])
            as $index => $liveAccount
        ) {
            if (!is_array($liveAccount)) {
                continue;
            }

            $unique = trim(
                (string) ($liveAccount['unique'] ?? '')
            );
            $phoneKey = $this->normalizePhone(
                (string) ($liveAccount['phone'] ?? '')
            );

            if ('' !== $unique) {
                $liveByUnique[$unique] = $index;
            }

            if ('' !== $phoneKey) {
                $liveByPhone[$phoneKey] = $index;
            }
        }

        $matchedLiveIndexes = [];
        $managedMatched = 0;
        $managedConnected = 0;
        $accounts = [];

        foreach (
            $this->accountRepository->getStatusRows()
            as $row
        ) {
            $hash = (string) ($row['account_hash'] ?? '');

            if ('' === $hash) {
                continue;
            }

            $enabled = $enabledByHash[$hash] ?? [
                'account_id' => '',
                'phone' => trim(
                    (string) ($row['phone'] ?? '')
                ),
            ];

            $accountId = $enabled['account_id'];
            $configuredPhone = $enabled['phone'];
            $liveIndex = null;
            $matchBasis = null;

            if (
                '' !== $accountId
                && array_key_exists(
                    $accountId,
                    $liveByUnique
                )
            ) {
                $liveIndex = $liveByUnique[$accountId];
                $matchBasis = 'unique_id';
            } else {
                $phoneKey = $this->normalizePhone(
                    $configuredPhone
                );

                if (
                    '' !== $phoneKey
                    && array_key_exists(
                        $phoneKey,
                        $liveByPhone
                    )
                ) {
                    $liveIndex = $liveByPhone[$phoneKey];
                    $matchBasis = 'phone';
                }
            }

            $liveAccount = null;

            if (null !== $liveIndex) {
                $candidate = $liveSnapshot['accounts'][
                    $liveIndex
                ] ?? null;

                if (is_array($candidate)) {
                    $liveAccount = $candidate;
                    $matchedLiveIndexes[$liveIndex] = true;
                    ++$managedMatched;

                    if (
                        'connected'
                        === strtolower(
                            (string) (
                                $liveAccount['status'] ?? ''
                            )
                        )
                    ) {
                        ++$managedConnected;
                    }
                }
            }

            $calculatedLimit = max(
                0,
                (int) ($row['daily_limit'] ?? 0)
            );
            $override = null === (
                $row['daily_limit_override'] ?? null
            )
                ? null
                : max(
                    1,
                    (int) $row['daily_limit_override']
                );
            $effectiveLimit = null !== $override
                ? $override
                : $calculatedLimit;
            $phoneDigits = $this->normalizePhone(
                (string) ($row['phone'] ?? '')
            );
            $today = $dispatchedByHash[$hash] ?? 0;
            $remainingToday = max(
                0,
                $effectiveLimit - $today
            );
            $enabledFlag = (
                1 === (int) ($row['enabled'] ?? 0)
            );
            $pendingState = $pendingStateByHash[$hash] ?? [
                'pending_total' => 0,
                'available_now' => 0,
            ];
            $pendingTotal = max(
                0,
                (int) $pendingState['pending_total']
            );
            $availableNow = min(
                $pendingTotal,
                max(0, (int) $pendingState['available_now'])
            );
            $lastAttemptStartedAt = $this->parseUtcTimestamp(
                $row['last_attempt_started_at'] ?? null
            );
            $nextEligibleAt = $this->parseUtcTimestamp(
                $row['next_eligible_at'] ?? null
            );
            $pausedUntil = $this->parseUtcTimestamp(
                $row['paused_until'] ?? null
            );
            $pauseActive = (
                null !== $pausedUntil
                && $pausedUntil > $nowUtc
            );
            $cooldownActive = (
                null !== $nextEligibleAt
                && $nextEligibleAt > $nowUtc
            );
            $cooldownRemainingSeconds = $cooldownActive
                ? max(
                    0,
                    $nextEligibleAt->getTimestamp()
                    - $nowUtc->getTimestamp()
                )
                : 0;

            if (!$enabledFlag) {
                $sendingState = 'disabled';
            } elseif ($pauseActive) {
                $sendingState = 'paused';
            } elseif (0 === $pendingTotal) {
                $sendingState = 'no_work';
            } elseif (0 === $remainingToday) {
                $sendingState = 'quota_reached';
            } elseif (0 === $availableNow) {
                $sendingState = 'waiting_queue';
            } elseif ($cooldownActive) {
                $sendingState = 'cooldown';
            } else {
                $sendingState = 'ready';
            }

            $accounts[] = [
                'id' => max(
                    0,
                    (int) ($row['id'] ?? 0)
                ),
                'label' => $this->safeLabel(
                    (string) ($row['label'] ?? '')
                ),
                'account_hash' => $hash,
                'phone_display' => '' === $phoneDigits
                    ? '—'
                    : '••••'.substr($phoneDigits, -4),
                'enabled' => $enabledFlag,
                'dispatch_order' => max(
                    0,
                    (int) ($row['dispatch_order'] ?? 0)
                ),
                'daily_limit' => $calculatedLimit,
                'daily_limit_override' => $override,
                'effective_daily_limit' => $effectiveLimit,
                'quota_source' => null !== $override
                    ? 'override'
                    : 'calculated_default',
                'pending' => $pendingTotal,
                'available_now' => $availableNow,
                'dispatched_today' => $today,
                'remaining_today' => $remainingToday,
                'last_attempt_started_at' => null !== $lastAttemptStartedAt
                    ? $lastAttemptStartedAt->format('Y-m-d H:i:s').' UTC'
                    : null,
                'next_eligible_at' => null !== $nextEligibleAt
                    ? $nextEligibleAt->format('Y-m-d H:i:s').' UTC'
                    : null,
                'pause_active' => $pauseActive,
                'paused_until' => null !== $pausedUntil
                    ? $pausedUntil->format('Y-m-d H:i:s').' UTC'
                    : null,
                'paused_until_local' => null !== $pausedUntil
                    ? $pausedUntil
                        ->setTimezone($displayTimezone)
                        ->format('Y-m-d\TH:i')
                    : '',
                'cooldown_active' => $cooldownActive,
                'cooldown_remaining_seconds' => (
                    $cooldownRemainingSeconds
                ),
                'sending_state' => $sendingState,
                'configured_phone' => $configuredPhone,
                'live_found' => null !== $liveAccount,
                'live_match_basis' => $matchBasis,
                'live_phone' => null !== $liveAccount
                    ? (string) ($liveAccount['phone'] ?? '')
                    : null,
                'live_unique' => null !== $liveAccount
                    ? (string) ($liveAccount['unique'] ?? '')
                    : null,
                'live_status' => null !== $liveAccount
                    ? (string) (
                        $liveAccount['status'] ?? 'unknown'
                    )
                    : 'not_found',
                'live_created_at_utc' => null !== $liveAccount
                    ? ($liveAccount['created_at_utc'] ?? null)
                    : null,
            ];
        }

        $unmanagedAccounts = [];

        foreach (
            (array) ($liveSnapshot['accounts'] ?? [])
            as $index => $liveAccount
        ) {
            if (
                !is_array($liveAccount)
                || isset($matchedLiveIndexes[$index])
            ) {
                continue;
            }

            $unmanagedAccounts[] = [
                'phone' => (string) (
                    $liveAccount['phone'] ?? ''
                ),
                'unique' => (string) (
                    $liveAccount['unique'] ?? ''
                ),
                'status' => (string) (
                    $liveAccount['status'] ?? 'unknown'
                ),
                'created_at_utc' => (
                    $liveAccount['created_at_utc'] ?? null
                ),
            ];
        }

        $dispatchedToday = max(
            0,
            $this->queueRepository->countDispatchedBetween(
                $startUtc,
                $endUtc
            )
        );
        $globalLimit = max(
            0,
            (int) $config['global_daily_limit']
        );
        $queue['total'] = array_sum($queue);
        $sending = $this->buildSendingOverview(
            $accounts,
            $config,
            $nowUtc
        );

        return [
            'marker' => (
                'ZENDER_PHONE_ACCOUNT_ADMIN_READ_ONLY_OK'
            ),
            'generated_at_utc' => (
                $nowUtc->format('Y-m-d H:i:s').' UTC'
            ),
            'config' => $config,
            'sending' => $sending,
            'accounts_enabled' => (
                $this->accountRepository->countEnabled()
            ),
            'accounts_total' => count($accounts),
            'accounts' => $accounts,
            'queue' => $queue,
            'today' => [
                'dispatched' => $dispatchedToday,
                'global_limit' => $globalLimit,
                'remaining_global' => max(
                    0,
                    $globalLimit - $dispatchedToday
                ),
            ],
            'live' => [
                'available' => (bool) (
                    $liveSnapshot['available'] ?? false
                ),
                'checked_at_utc' => (
                    $liveSnapshot['checked_at_utc'] ?? null
                ),
                'endpoint' => (
                    $liveSnapshot['endpoint'] ?? null
                ),
                'api_host' => (
                    $liveSnapshot['api_host'] ?? null
                ),
                'http_status' => max(
                    0,
                    (int) (
                        $liveSnapshot['http_status'] ?? 0
                    )
                ),
                'api_status' => (
                    $liveSnapshot['api_status'] ?? null
                ),
                'api_message' => (
                    $liveSnapshot['api_message'] ?? null
                ),
                'token_fingerprint' => (
                    $liveSnapshot['token_fingerprint'] ?? null
                ),
                'api_account_count' => max(
                    0,
                    (int) (
                        $liveSnapshot['account_count'] ?? 0
                    )
                ),
                'managed_matched' => $managedMatched,
                'managed_connected' => $managedConnected,
                'managed_disconnected' => max(
                    0,
                    $managedMatched - $managedConnected
                ),
                'unmanaged_count' => count(
                    $unmanagedAccounts
                ),
                'unmanaged_accounts' => $unmanagedAccounts,
                'error_code' => (
                    $liveSnapshot['error_code'] ?? null
                ),
            ],
            'safety' => [
                'read_only' => true,
                'database_writes' => 0,
                'provider_calls' => max(
                    0,
                    (int) (
                        $liveSnapshot[
                            'provider_read_requests'
                        ] ?? 0
                    )
                ),
                'provider_mutations' => 0,
                'messages_sent' => 0,
                'queue_mutations' => 0,
                'account_mutations' => 0,
            ],
        ];
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function getWhatsAppCampaignStatusSummaries(): array
    {
        return $this->queueRepository
            ->getWhatsAppCampaignStatusSummaries();
    }

    /**
     * PHASE_1B_QUEUE_HISTORY_READ_MODEL_V1
     *
     * Expose the protected unfiltered repository contract through the
     * control read model without invoking dashboard provider diagnostics.
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
    public function getQueueHistoryPage(
        int $limit = 25,
        int $offset = 0
    ): array {
        return $this->getFilteredQueueHistoryPage(
            [],
            $limit,
            $offset
        );
    }

    /**
     * PHASE_1F2_READ_MODEL_FILTER_DELEGATION_V1
     *
     * Delegate the protected Queue and History filter contract to the
     * repository without invoking dashboard provider diagnostics.
     *
     * Supported repository filters:
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
    public function getFilteredQueueHistoryPage(
        array $filters = [],
        int $limit = 25,
        int $offset = 0
    ): array {
        return $this->queueRepository->getFilteredHistoryPage(
            $filters,
            $limit,
            $offset
        );
    }


    // SENDING_SPEED_VISUAL_3A5V1_BEGIN

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     summary: array<string, int>,
     *     page: int,
     *     page_size: int,
     *     page_count: int,
     *     previous_page: ?int,
     *     next_page: ?int,
     *     from_record: int,
     *     to_record: int
     * }
     */
    public function getReceivedChatPage(
        int $requestedPage,
        int $pageSize = 25
    ): array {
        $pageSize = max(1, min(100, $pageSize));
        $summary = $this->receivedChatRepository
            ->getSummary();
        $total = max(
            0,
            (int) ($summary['total'] ?? 0)
        );
        $pageCount = max(
            1,
            (int) ceil($total / $pageSize)
        );
        $page = min(
            max(1, $requestedPage),
            $pageCount
        );
        $offset = ($page - 1) * $pageSize;
        $items = [];

        foreach (
            $this->receivedChatRepository->getPage(
                $pageSize,
                $offset
            )
            as $row
        ) {
            $contactId = null !== ($row['contact_id'] ?? null)
                ? (int) $row['contact_id']
                : null;
            $name = trim(
                trim((string) ($row['firstname'] ?? '')).
                ' '.
                trim((string) ($row['lastname'] ?? ''))
            );
            $email = trim(
                (string) ($row['email'] ?? '')
            );
            $contactLabel = $name;

            if ('' === $contactLabel && '' !== $email) {
                $contactLabel = $email;
            }

            if (
                '' === $contactLabel
                && null !== $contactId
            ) {
                $contactLabel = '#'.$contactId;
            }

            $senderDigits = $this->normalizePhone(
                (string) (
                    $row['sender_phone_normalized']
                    ?? ''
                )
            );
            $accountIdentifier = trim(
                (string) (
                    $row['account_identifier']
                    ?? ''
                )
            );

            $items[] = [
                'id' => max(
                    0,
                    (int) ($row['id'] ?? 0)
                ),
                'provider_received_id' => trim(
                    (string) (
                        $row['provider_received_id']
                        ?? ''
                    )
                ),
                'sender_display' => '' === $senderDigits
                    ? '—'
                    : '••••'.substr(
                        $senderDigits,
                        -4
                    ),
                'contact_id' => $contactId,
                'contact_label' => '' !== $contactLabel
                    ? $contactLabel
                    : '—',
                'contact_email' => $email,
                'contact_match_state' => trim(
                    (string) (
                        $row['contact_match_state']
                        ?? 'unmatched_contact'
                    )
                ),
                'provider_created_at' => trim(
                    (string) (
                        $row['provider_created_at']
                        ?? ''
                    )
                ),
                'message_type' => trim(
                    (string) (
                        $row['message_type']
                        ?? 'text'
                    )
                ),
                'message_body' => $this->decodeReceivedMessageForDisplay(
                    (string) (
                        $row['message_body']
                        ?? ''
                    )
                ),
                'has_attachment' => (
                    1 === (int) (
                        $row['has_attachment']
                        ?? 0
                    )
                ),
                'account_hash' => '' === $accountIdentifier
                    ? ''
                    : $this->accountHash(
                        $accountIdentifier
                    ),
            ];
        }

        return [
            'items' => $items,
            'summary' => [
                'total' => $total,
                'unique' => max(
                    0,
                    (int) ($summary['unique'] ?? 0)
                ),
                'ambiguous' => max(
                    0,
                    (int) ($summary['ambiguous'] ?? 0)
                ),
                'unmatched' => max(
                    0,
                    (int) ($summary['unmatched'] ?? 0)
                ),
                'unmatchable' => max(
                    0,
                    (int) ($summary['unmatchable'] ?? 0)
                ),
            ],
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'previous_page' => $page > 1
                ? $page - 1
                : null,
            'next_page' => $page < $pageCount
                ? $page + 1
                : null,
            'from_record' => [] === $items
                ? 0
                : $offset + 1,
            'to_record' => [] === $items
                ? 0
                : $offset + count($items),
        ];
    }


    private function decodeReceivedMessageForDisplay(
        string $message
    ): string {
        return html_entity_decode(
            $message,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    private function buildSendingOverview(
        array $accounts,
        array $config,
        DateTimeImmutable $nowUtc
    ): array {
        $states = [
            'ready' => 0,
            'cooldown' => 0,
            'waiting_queue' => 0,
            'quota_reached' => 0,
            'no_work' => 0,
            'disabled' => 0,
        ];
        $liveWorkIds = 0;
        $nextEligibleAt = null;

        foreach ($accounts as $account) {
            $state = (string) (
                $account['sending_state'] ?? 'disabled'
            );

            if (array_key_exists($state, $states)) {
                ++$states[$state];
            }

            if (
                (bool) ($account['enabled'] ?? false)
                && 0 < (int) ($account['pending'] ?? 0)
            ) {
                ++$liveWorkIds;
            }

            if ('cooldown' !== $state) {
                continue;
            }

            $candidate = $this->parseUtcTimestamp(
                $account['next_eligible_at'] ?? null
            );

            if (
                null !== $candidate
                && (
                    null === $nextEligibleAt
                    || $candidate < $nextEligibleAt
                )
            ) {
                $nextEligibleAt = $candidate;
            }
        }

        return [
            'marker' => 'ZENDER_SENDING_SPEED_READ_ONLY_3A5V1',
            'generated_at_utc' => (
                $nowUtc->format('Y-m-d H:i:s').' UTC'
            ),
            'dispatcher_enabled' => (bool) (
                $config['enabled'] ?? false
            ),
            'interval_seconds' => max(
                0,
                (int) (
                    $config['dispatch_interval_seconds'] ?? 0
                )
            ),
            'window_start' => (string) (
                $config['window_start'] ?? ''
            ),
            'window_end' => (string) (
                $config['window_end'] ?? ''
            ),
            'timezone' => (string) (
                $config['timezone'] ?? 'UTC'
            ),
            'live_work_ids' => $liveWorkIds,
            'ready_ids' => $states['ready'],
            'cooldown_ids' => $states['cooldown'],
            'waiting_queue_ids' => $states['waiting_queue'],
            'quota_reached_ids' => $states['quota_reached'],
            'no_work_ids' => $states['no_work'],
            'disabled_ids' => $states['disabled'],
            'next_eligible_at' => null !== $nextEligibleAt
                ? $nextEligibleAt->format('Y-m-d H:i:s').' UTC'
                : null,
            'next_eligible_in_seconds' => null !== $nextEligibleAt
                ? max(
                    0,
                    $nextEligibleAt->getTimestamp()
                    - $nowUtc->getTimestamp()
                )
                : null,
        ];
    }

    private function parseUtcTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $value = trim($value);
        if (str_ends_with($value, ' UTC')) {
            $value = substr($value, 0, -4);
        }

        try {
            return new DateTimeImmutable(
                $value,
                new DateTimeZone('UTC')
            );
        } catch (Throwable) {
            return null;
        }
    }
    // SENDING_SPEED_VISUAL_3A5V1_END
    private function normalizeConfig(array $config): array
    {
        return [
            'enabled' => (bool) (
                $config['enabled'] ?? false
            ),
            'timezone' => (string) (
                $config['timezone'] ?? 'America/Lima'
            ),
            'window_start' => (string) (
                $config['window_start'] ?? '08:00'
            ),
            'window_end' => (string) (
                $config['window_end'] ?? '21:00'
            ),
            'global_daily_limit' => max(
                0,
                (int) (
                    $config['global_daily_limit'] ?? 0
                )
            ),
            'per_account_daily_limit' => max(
                0,
                (int) (
                    $config[
                        'per_account_daily_limit'
                    ] ?? 0
                )
            ),
            'batch_size' => max(
                0,
                (int) ($config['batch_size'] ?? 0)
            ),
            'dispatch_interval_seconds' => max(
                0,
                (int) (
                    $config[
                        'dispatch_interval_seconds'
                    ] ?? 0
                )
            ),
            'max_attempts' => max(
                0,
                (int) ($config['max_attempts'] ?? 0)
            ),
            'retry_delay_seconds' => max(
                0,
                (int) (
                    $config[
                        'retry_delay_seconds'
                    ] ?? 0
                )
            ),
        ];
    }

    private function todayUtcBounds(
        string $timezoneName
    ): array {
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezone = new DateTimeZone('UTC');
        }

        $start = (
            new DateTimeImmutable('now', $timezone)
        )->setTime(0, 0);
        $utc = new DateTimeZone('UTC');

        return [
            $start->setTimezone($utc),
            $start->modify('+1 day')->setTimezone($utc),
        ];
    }

    private function accountHash(string $accountId): string
    {
        return substr(
            hash('sha256', $accountId),
            0,
            12
        );
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function safeLabel(string $label): string
    {
        $label = trim($label);

        return '' === $label
            ? 'Cuenta Zender'
            : mb_substr($label, 0, 120);
    }
}
