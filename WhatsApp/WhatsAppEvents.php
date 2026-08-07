<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\WhatsApp;

final class WhatsAppEvents
{
    public const CAMPAIGN_BATCH_SEND = 'mautic.zender.whatsapp.campaign.batch_send';

    private function __construct()
    {
    }
}
