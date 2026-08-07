<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;
use MauticPlugin\MauticZenderBundle\DependencyInjection\MauticZenderExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class MauticZenderBundle extends PluginBundleBase
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new MauticZenderExtension();
    }
}
