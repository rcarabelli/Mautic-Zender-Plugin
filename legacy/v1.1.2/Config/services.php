<?php
declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use MauticPlugin\MauticZenderBundle\Integration\ZenderIntegration;
use MauticPlugin\MauticZenderBundle\Transport\ZenderTransport;

return static function (ContainerConfigurator $c): void {
    $s = $c->services()->defaults()->autowire()->autoconfigure();

    // Integration (shows in /s/plugins, alias "Zender")
    $s->set(ZenderIntegration::class)
      ->tag('mautic.basic_integration')
      ->tag('mautic.integration', ['alias' => 'Zender']);

    // SMS transport (used by the SMS channel)
    $s->set('mautic.sms.transport.zender', ZenderTransport::class)
      ->arg('$integrationHelper', service('mautic.helper.integration'))
      ->arg('$logger',            service('monolog.logger.mautic'))
      ->arg('$client',            service('mautic.http.client'))
      ->arg('$entityManager',     service('doctrine.orm.entity_manager'))
      ->tag('mautic.sms_transport', ['integrationAlias' => 'Zender'])
      ->alias('mautic.sms.config.zender.transport', 'mautic.sms.transport.zender');
};
