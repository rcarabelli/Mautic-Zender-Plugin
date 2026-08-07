<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WhatsAppContactPageButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly TranslatorInterface $translator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => [
                'onInjectCustomButtons',
                0,
            ],
        ];
    }

    public function onInjectCustomButtons(
        CustomButtonEvent $event
    ): void {
        if (
            ButtonHelper::LOCATION_PAGE_ACTIONS
            !== $event->getLocation()
        ) {
            return;
        }

        $contact = $event->getItem();
        if (
            !$contact instanceof Lead
            || (int) $contact->getId() < 1
        ) {
            return;
        }

        if (
            '' === trim(
                (string) $contact->getFieldValue(
                    'id_whatsapp_in_zender'
                )
            )
        ) {
            return;
        }

        $event->addButton(
            [
                'attr' => [
                    'id' => 'sendWhatsAppButton',
                    'href' => $this->router->generate(
                        'mautic_zender_whatsapp_contact_send',
                        [
                            'contactId' => (int) $contact->getId(),
                        ]
                    ),
                    'data-toggle' => 'ajaxmodal',
                    'data-target' => '#MauticSharedModal',
                    'data-header' => $this->translator->trans(
                        'mautic.zender.whatsapp.contact.send.button'
                    ),
                    'data-whatsapp-stage' => 'button-modal-linked',
                    'title' => $this->translator->trans(
                        'mautic.zender.whatsapp.contact.send.button'
                    ),
                ],
                'btnText' => (
                    'mautic.zender.whatsapp.contact.send.button'
                ),
                'iconClass' => 'ri-whatsapp-line',
                'primary' => true,
                'priority' => 300,
            ],
            ButtonHelper::LOCATION_PAGE_ACTIONS,
            [
                'mautic_contact_action',
                ['objectAction' => 'view'],
            ]
        );
    }
}
