<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\EventListener;

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticZenderBundle\Service\DispatchQueueRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WhatsAppTimelineSubscriber implements EventSubscriberInterface
{
    private const EVENT_TYPE = 'whatsapp.message';

    public function __construct(
        private DispatchQueueRepository $queueRepository,
        private TranslatorInterface $translator,
        private RouterInterface $router
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::TIMELINE_ON_GENERATE => ['onTimelineGenerate', 0],
        ];
    }

    public function onTimelineGenerate(LeadTimelineEvent $event): void
    {
        $eventTypeName = $this->translator->trans(
            'mautic.zender.whatsapp.timeline.type'
        );
        $event->addEventType(self::EVENT_TYPE, $eventTypeName);

        if (!$event->isApplicable(self::EVENT_TYPE)) {
            return;
        }

        $leadId = (int) $event->getLeadId();
        if ($leadId < 1) {
            return;
        }

        $queryOptions = $event->getQueryOptions();
        $count = $this->queueRepository->countWhatsAppTimeline(
            $leadId,
            $queryOptions
        );
        $event->addToCounter(self::EVENT_TYPE, $count);

        if ($event->isEngagementCount()) {
            return;
        }

        $rows = $this->queueRepository->getWhatsAppTimelinePage(
            $leadId,
            $queryOptions
        );

        foreach ($rows as $row) {
            $messageName = '' !== $row['message_name']
                ? $row['message_name']
                : $this->translator->trans(
                    'mautic.zender.whatsapp.timeline.unnamed'
                );
            $statusKey = $this->resolveVisibleStatusKey($row);
            $statusLabel = $this->translator->trans($statusKey);
            $sourceLabel = $this->buildSourceLabel($row);
            $visibleLabel = $this->translator->trans(
                'mautic.zender.whatsapp.timeline.visible_label',
                [
                    '%message%' => $messageName,
                    '%status%' => $statusLabel,
                    '%source%' => $sourceLabel,
                ]
            );

            $messageId = null !== $row['whatsapp_message_id']
                ? (int) $row['whatsapp_message_id']
                : null;

            $event->addEvent([
                'event' => self::EVENT_TYPE,
                'eventId' => self::EVENT_TYPE.'.'.$row['id'],
                'eventLabel' => [
                    'label' => $visibleLabel,
                    'href' => $this->router->generate(
                        'mautic_zender_whatsapp_messages',
                        null !== $messageId
                            ? ['edit' => $messageId]
                            : []
                    ),
                ],
                'eventType' => $eventTypeName,
                'timestamp' => $row['timeline_at'],
                'contactId' => $leadId,
                'icon' => 'ri-whatsapp-line',
                'extra' => [
                    'queue_id' => $row['id'],
                    'message_id' => $messageId,
                    'status' => $row['status'],
                    'provider_status' => $row['provider_status'],
                    'visible_status_label' => $statusLabel,
                    'source_label' => $sourceLabel,
                    'technical_success_label' => (
                        $this->isSentByZender(
                            $row['provider_status'] ?? null
                        )
                            ? $this->translator->trans(
                                'mautic.zender.whatsapp.sent_by_zender'
                            )
                            : null
                    ),
                    'campaign_id' => $row['campaign_id'],
                    'campaign_event_id' => $row['campaign_event_id'],
                    'campaign_event_log_id' => $row[
                        'campaign_event_log_id'
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveVisibleStatusKey(array $row): string
    {
        if ($this->isSentByZender($row['provider_status'] ?? null)) {
            return 'mautic.zender.whatsapp.timeline.status.sent_by_zender';
        }

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $attempts = max(0, (int) ($row['attempts'] ?? 0));

        return match (true) {
            'blocked_account' === $status => (
                'mautic.zender.whatsapp.timeline.status.blocked_account'
            ),
            'failed' === $status => (
                'mautic.zender.whatsapp.timeline.status.failed'
            ),
            'pending' === $status && $attempts > 0 => (
                'mautic.zender.whatsapp.timeline.status.retry_pending'
            ),
            in_array($status, ['pending', 'queued', 'dispatching'], true) => (
                'mautic.zender.whatsapp.timeline.status.queued'
            ),
            default => (
                'mautic.zender.whatsapp.timeline.status.unknown'
            ),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildSourceLabel(array $row): string
    {
        $campaignId = (int) ($row['campaign_id'] ?? 0);
        $campaignName = trim((string) ($row['campaign_name'] ?? ''));

        if ($campaignId > 0) {
            if ('' !== $campaignName) {
                return $this->translator->trans(
                    'mautic.zender.whatsapp.timeline.source.campaign',
                    ['%campaign%' => $campaignName]
                );
            }

            return $this->translator->trans(
                'mautic.zender.whatsapp.timeline.source.campaign_id',
                ['%campaign%' => (string) $campaignId]
            );
        }

        if ('whatsapp.contact.profile' === ($row['source'] ?? null)) {
            return $this->translator->trans(
                'mautic.zender.whatsapp.timeline.source.contact_profile'
            );
        }

        return $this->translator->trans(
            'mautic.zender.whatsapp.timeline.source.whatsapp'
        );
    }

    private function isSentByZender(?string $providerStatus): bool
    {
        return in_array(
            strtolower(trim((string) $providerStatus)),
            ['zender_sent', 'sent'],
            true
        );
    }
}
