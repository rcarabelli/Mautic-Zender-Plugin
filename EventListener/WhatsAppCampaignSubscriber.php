<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\MauticZenderBundle\Form\Type\WhatsAppCampaignSendType;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppCampaignEnqueuer;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppMessageRepository;
use MauticPlugin\MauticZenderBundle\WhatsApp\WhatsAppEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final class WhatsAppCampaignSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private WhatsAppMessageRepository $messageRepository,
        private WhatsAppCampaignEnqueuer $campaignEnqueuer,
        private TranslatorInterface $translator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            WhatsAppEvents::CAMPAIGN_BATCH_SEND => ['onCampaignBatchSend', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(
            'zender.send_whatsapp',
            [
                'label' => 'mautic.zender.whatsapp.campaign.action',
                'description' => 'mautic.zender.whatsapp.campaign.action.help',
                'batchEventName' => WhatsAppEvents::CAMPAIGN_BATCH_SEND,
                'formType' => WhatsAppCampaignSendType::class,
                'channel' => 'whatsapp',
                'channelIdField' => 'whatsapp_message',
            ]
        );
    }

    public function onCampaignBatchSend(PendingEvent $event): void
    {
        $properties = $event->getEvent()->getProperties();
        $messageId = (int) ($properties['whatsapp_message'] ?? 0);
        $message = $this->messageRepository->findPublished($messageId);

        $event->setChannel('whatsapp', $messageId > 0 ? $messageId : null);

        if (null === $message) {
            $event->failAll(
                $this->translator->trans(
                    'mautic.zender.whatsapp.campaign.failure.missing'
                )
            );

            return;
        }

        foreach ($event->getPending() as $log) {
            try {
                $result = $this->campaignEnqueuer->enqueue(
                    $log->getLead(),
                    $message,
                    $log
                );

                if ($result['accepted']) {
                    $log->appendToMetadata([
                        'whatsapp' => [
                            'message_id' => $messageId,
                            'queue_id' => $result['queue_id'],
                            'queue_status' => $result['queue_status'],
                            'technical_state' => 'accepted_into_zender_queue',
                        ],
                    ]);
                    $event->pass($log);

                    continue;
                }

                if ($result['permanent']) {
                    $event->passWithError(
                        $log,
                        $this->translator->trans(
                            'mautic.zender.whatsapp.campaign.failure.permanent',
                            ['%reason%' => $result['reason']]
                        )
                    );

                    continue;
                }

                $event->fail(
                    $log,
                    $this->translator->trans(
                        'mautic.zender.whatsapp.campaign.failure.queue',
                        ['%reason%' => $result['reason']]
                    )
                );
            } catch (Throwable $exception) {
                $event->fail(
                    $log,
                    $this->translator->trans(
                        'mautic.zender.whatsapp.campaign.failure.exception',
                        [
                            '%reason%' => mb_substr(
                                $exception->getMessage(),
                                0,
                                300
                            ),
                        ]
                    )
                );
            }
        }
    }
}
