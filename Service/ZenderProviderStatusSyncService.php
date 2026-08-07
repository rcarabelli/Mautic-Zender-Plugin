<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ZenderProviderStatusSyncService
{
    private const STUCK_AFTER_SECONDS = 600;

    public function __construct(
        private readonly ZenderChatReadApiClient $apiClient,
        private readonly ZenderProviderStatusRepository $repository,
    ) {
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function sync(
        bool $execute,
        int $limit = 2000,
        int $maxPages = 20
    ): array {
        if (!$this->repository->acquireLock()) {
            return [
                'accepted' => true,
                'executed' => $execute,
                'lock_busy' => true,
                'provider_available' => false,
                'provider_read_requests' => 0,
                'candidate_rows' => 0,
                'sent_matches' => 0,
                'pending_matches' => 0,
                'stuck_detected' => 0,
                'unknown_detected' => 0,
                'planned_updates' => 0,
                'updated_rows' => 0,
                'provider_mutations' => 0,
                'messages_sent' => 0,
                'error_code' => null,
            ];
        }

        try {
            $rows = $this->repository->fetchCandidates($limit);
            $snapshot = $this->apiClient->getStatusSnapshot(100, $maxPages);

            if (!(bool) ($snapshot['available'] ?? false)) {
                return [
                    'accepted' => false,
                    'executed' => $execute,
                    'lock_busy' => false,
                    'provider_available' => false,
                    'provider_read_requests' => max(
                        0,
                        (int) ($snapshot['provider_read_requests'] ?? 0)
                    ),
                    'candidate_rows' => count($rows),
                    'sent_matches' => 0,
                    'pending_matches' => 0,
                    'stuck_detected' => 0,
                    'unknown_detected' => 0,
                    'planned_updates' => 0,
                    'updated_rows' => 0,
                    'provider_mutations' => 0,
                    'messages_sent' => 0,
                    'error_code' => (
                        (string) ($snapshot['error_code'] ?? 'provider_unavailable')
                    ),
                ];
            }

            $sent = (array) ($snapshot['sent'] ?? []);
            $pending = (array) ($snapshot['pending'] ?? []);
            $observedAt = gmdate('Y-m-d H:i:s');
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $observations = [];
            $sentMatches = 0;
            $pendingMatches = 0;
            $stuckDetected = 0;
            $unknownDetected = 0;

            foreach ($rows as $row) {
                $providerId = trim(
                    (string) ($row['provider_message_id'] ?? '')
                );
                if ('' === $providerId) {
                    continue;
                }

                $status = null;
                $hash = null;

                if (isset($pending[$providerId]) && is_array($pending[$providerId])) {
                    ++$pendingMatches;
                    $age = $this->acceptedAgeSeconds(
                        $row['provider_accepted_at'] ?? null,
                        $now
                    );

                    if (null !== $age && $age >= self::STUCK_AFTER_SECONDS) {
                        $status = 'zender_stuck_timeout';
                        ++$stuckDetected;
                    } else {
                        $status = 'zender_queued';
                    }

                    $hash = (string) (
                        $pending[$providerId]['item_sha256'] ?? ''
                    );
                } elseif (
                    isset($sent[$providerId])
                    && is_array($sent[$providerId])
                ) {
                    ++$sentMatches;
                    $status = $this->mapSentStatus(
                        (string) ($sent[$providerId]['status'] ?? '')
                    );
                    $hash = (string) (
                        $sent[$providerId]['item_sha256'] ?? ''
                    );
                } else {
                    $age = $this->acceptedAgeSeconds(
                        $row['provider_accepted_at'] ?? null,
                        $now
                    );

                    if (null === $age || $age < self::STUCK_AFTER_SECONDS) {
                        continue;
                    }

                    $status = 'zender_status_unknown';
                    ++$unknownDetected;
                    $hash = hash(
                        'sha256',
                        implode('|', [
                            'not_present_in_sent_or_pending',
                            $providerId,
                            (string) ($snapshot['checked_at_utc'] ?? ''),
                        ])
                    );
                }

                if ('' === $hash) {
                    $hash = hash(
                        'sha256',
                        $providerId.'|'.$status.'|'.$observedAt
                    );
                }

                $observations[] = [
                    'queue_id' => max(0, (int) ($row['id'] ?? 0)),
                    'provider_message_id' => $providerId,
                    'provider_status' => $status,
                    'observed_at' => $observedAt,
                    'response_sha256' => $hash,
                ];
            }

            $updated = $execute
                ? $this->repository->persistObservations($observations)
                : 0;

            return [
                'accepted' => true,
                'executed' => $execute,
                'lock_busy' => false,
                'provider_available' => true,
                'provider_read_requests' => max(
                    0,
                    (int) ($snapshot['provider_read_requests'] ?? 0)
                ),
                'candidate_rows' => count($rows),
                'sent_matches' => $sentMatches,
                'pending_matches' => $pendingMatches,
                'stuck_detected' => $stuckDetected,
                'unknown_detected' => $unknownDetected,
                'planned_updates' => count($observations),
                'updated_rows' => $updated,
                'provider_mutations' => 0,
                'messages_sent' => 0,
                'error_code' => null,
            ];
        } catch (Throwable $throwable) {
            return [
                'accepted' => false,
                'executed' => $execute,
                'lock_busy' => false,
                'provider_available' => false,
                'provider_read_requests' => 0,
                'candidate_rows' => 0,
                'sent_matches' => 0,
                'pending_matches' => 0,
                'stuck_detected' => 0,
                'unknown_detected' => 0,
                'planned_updates' => 0,
                'updated_rows' => 0,
                'provider_mutations' => 0,
                'messages_sent' => 0,
                'error_code' => get_class($throwable),
            ];
        } finally {
            $this->repository->safeReleaseLock();
        }
    }

    private function mapSentStatus(string $rawStatus): string
    {
        $status = strtolower(trim($rawStatus));

        if ('sent' === $status) {
            return 'zender_sent';
        }

        if (in_array($status, ['registered', 'accepted'], true)) {
            return 'zender_registered';
        }

        if (in_array($status, ['failed', 'error', 'rejected'], true)) {
            return 'zender_failed';
        }

        if (in_array($status, ['pending', 'queued', 'queue'], true)) {
            return 'zender_queued';
        }

        return 'zender_status_unknown';
    }

    private function acceptedAgeSeconds(
        mixed $value,
        DateTimeImmutable $now
    ): ?int {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            $accepted = new DateTimeImmutable(
                trim($value),
                new DateTimeZone('UTC')
            );

            return max(0, $now->getTimestamp() - $accepted->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }
}
