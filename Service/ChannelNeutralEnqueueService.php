<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use InvalidArgumentException;

final class ChannelNeutralEnqueueService
{
    public function __construct(
        private DispatchAccountRepository $accountRepository,
        private DispatchQueueRepository $queueRepository
    ) {
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{accepted:bool,queue_id:int|null,status:string}
     */
    public function enqueue(array $request): array
    {
        $channel = strtolower(trim((string) ($request['channel'] ?? '')));
        $dedupeKey = strtolower(trim((string) ($request['dedupe_key'] ?? '')));
        $recipient = trim((string) ($request['recipient'] ?? ''));
        $accountId = trim((string) ($request['account_id'] ?? ''));
        $content = trim((string) ($request['content'] ?? ''));

        if (1 !== preg_match('/^[a-z][a-z0-9_.-]{1,31}$/', $channel)) {
            throw new InvalidArgumentException('Invalid dispatch channel.');
        }

        if (1 !== preg_match('/^[a-f0-9]{64}$/', $dedupeKey)) {
            throw new InvalidArgumentException('Invalid dispatch dedupe key.');
        }

        if (
            '' === $recipient
            || '' === $accountId
            || '' === $content
            || (int) ($request['contact_id'] ?? 0) < 1
        ) {
            throw new InvalidArgumentException(
                'Incomplete channel-neutral dispatch request.'
            );
        }

        $status = $this->accountRepository->isEnabledAccount($accountId)
            ? 'pending'
            : 'blocked_account';

        $accepted = $this->queueRepository->enqueue([
            'dedupe_key' => $dedupeKey,
            'contact_id' => (int) $request['contact_id'],
            'channel' => $channel,
            'sms_id' => $request['sms_id'] ?? null,
            'whatsapp_message_id' => $request['whatsapp_message_id'] ?? null,
            'asset_id' => $request['asset_id'] ?? null,
            'campaign_id' => $request['campaign_id'] ?? null,
            'campaign_event_id' => $request['campaign_event_id'] ?? null,
            'campaign_event_log_id' => $request['campaign_event_log_id'] ?? null,
            'stat_tracking_hash' => $request['stat_tracking_hash'] ?? null,
            'source' => $request['source'] ?? null,
            'source_id' => $request['source_id'] ?? null,
            'recipient' => $recipient,
            'account_id' => $accountId,
            'content' => $content,
            'status' => $status,
            'priority' => (int) ($request['priority'] ?? 2),
            'available_at' => $request['available_at'] ?? null,
            'expires_at' => $request['expires_at'] ?? null,
        ]);

        if (!$accepted) {
            return [
                'accepted' => false,
                'queue_id' => null,
                'status' => 'rejected',
            ];
        }

        return [
            'accepted' => true,
            'queue_id' => $this->queueRepository->findIdByDedupeKey($dedupeKey),
            'status' => $status,
        ];
    }
}
