<?php

declare(strict_types=1);

use MauticPlugin\MauticZenderBundle\Command\ZenderActivationCommand;
use MauticPlugin\MauticZenderBundle\Command\ZenderConfigCommand;
use MauticPlugin\MauticZenderBundle\Command\ZenderDispatchCommand;
use MauticPlugin\MauticZenderBundle\Command\ZenderReceivedChatImportCommand;
use MauticPlugin\MauticZenderBundle\Command\ZenderStatusCommand;
use MauticPlugin\MauticZenderBundle\Integration\ZenderIntegration;
use MauticPlugin\MauticZenderBundle\Service\DispatchAccountRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchAttemptRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchConfigRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchQueueRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchQuotaResolver;
use MauticPlugin\MauticZenderBundle\Service\GuardedRetryScheduler;
use MauticPlugin\MauticZenderBundle\Service\RetrySafetyClassifier;
use MauticPlugin\MauticZenderBundle\Service\RoundRobinPlanner;
use MauticPlugin\MauticZenderBundle\Service\ZenderReceivedChatApiClient;
use MauticPlugin\MauticZenderBundle\Service\ZenderReceivedChatImporter;
use MauticPlugin\MauticZenderBundle\Service\ZenderReceivedChatRepository;
use MauticPlugin\MauticZenderBundle\Transport\ZenderTransport;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(ZenderIntegration::class)
        ->tag('mautic.basic_integration')
        ->tag('mautic.integration', ['alias' => 'Zender']);

    $services->set('mautic.zender.dispatch_account_repository', DispatchAccountRepository::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'));

    $services->set('mautic.zender.dispatch_attempt_repository', DispatchAttemptRepository::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'));

    $services->set('mautic.zender.dispatch_config_repository', DispatchConfigRepository::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'));


    $services->set('mautic.zender.retry_safety_classifier', RetrySafetyClassifier::class)
        ->public();

    $services->set('mautic.zender.guarded_retry_scheduler', GuardedRetryScheduler::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'))
        ->arg('$attemptRepository', service('mautic.zender.dispatch_attempt_repository'))
        ->public();

    $services->set('mautic.zender.round_robin_planner', RoundRobinPlanner::class);

    $services->set('mautic.zender.dispatch_queue_repository', DispatchQueueRepository::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'))
        ->arg('$planner', service('mautic.zender.round_robin_planner'));

    $services->set('mautic.sms.transport.zender', ZenderTransport::class)
        ->arg('$integrationHelper', service('mautic.helper.integration'))
        ->arg('$logger', service('monolog.logger.mautic'))
        ->arg('$client', service('mautic.http.client'))
        ->arg('$entityManager', service('doctrine.orm.default_entity_manager'))
        ->arg('$dispatchAccountRepository', service('mautic.zender.dispatch_account_repository'))
        ->arg('$dispatchConfigRepository', service('mautic.zender.dispatch_config_repository'))
        ->arg('$dispatchQueueRepository', service('mautic.zender.dispatch_queue_repository'))
        ->public();


    $services->set(ZenderActivationCommand::class)
        ->arg('$configRepository', service('mautic.zender.dispatch_config_repository'))
        ->arg('$accountRepository', service('mautic.zender.dispatch_account_repository'))
        ->tag('console.command');







    $services->set(ZenderStatusCommand::class)
        ->arg('$configRepository', service('mautic.zender.dispatch_config_repository'))
        ->arg('$queueRepository', service('mautic.zender.dispatch_queue_repository'))
        ->arg('$accountRepository', service('mautic.zender.dispatch_account_repository'))
        ->arg('$attemptRepository', service('mautic.zender.dispatch_attempt_repository'))
        ->tag('console.command');

    $services->set(ZenderConfigCommand::class)
        ->arg('$configRepository', service('mautic.zender.dispatch_config_repository'))
        ->tag('console.command');


    $services->set(ZenderDispatchCommand::class)
        ->arg('$configRepository', service('mautic.zender.dispatch_config_repository'))
        ->arg('$accountRepository', service('mautic.zender.dispatch_account_repository'))
        ->arg('$quotaResolver', service(DispatchQuotaResolver::class))
        ->arg('$queueRepository', service('mautic.zender.dispatch_queue_repository'))
        ->arg('$attemptRepository', service('mautic.zender.dispatch_attempt_repository'))
        ->arg('$retrySafetyClassifier', service('mautic.zender.retry_safety_classifier'))
        ->arg('$retryScheduler', service('mautic.zender.guarded_retry_scheduler'))
        ->arg('$planner', service('mautic.zender.round_robin_planner'))
        ->arg(
            '$accountApiClient',
            service('mautic.zender.account_api_client')
        )
        ->arg('$transport', service('mautic.sms.transport.zender'))
        ->arg('$logger', service('monolog.logger.mautic'))
        ->tag('console.command');

    $services->set(
        'mautic.zender.received_chat_repository',
        ZenderReceivedChatRepository::class
    )
        ->arg(
            '$connection',
            service('doctrine.dbal.default_connection')
        );

    $services->set(
        'mautic.zender.received_chat_api_client',
        ZenderReceivedChatApiClient::class
    )
        ->arg('$integrationHelper', service('mautic.helper.integration'))
        ->arg('$client', service('mautic.http.client'))
        ->arg('$logger', service('monolog.logger.mautic'));

    $services->set(
        'mautic.zender.received_chat_importer',
        ZenderReceivedChatImporter::class
    )
        ->arg(
            '$apiClient',
            service('mautic.zender.received_chat_api_client')
        )
        ->arg('$connection', service('doctrine.dbal.default_connection'))
        ->arg('$logger', service('monolog.logger.mautic'));

    $services->set(ZenderReceivedChatImportCommand::class)
        ->arg(
            '$importer',
            service('mautic.zender.received_chat_importer')
        )
        ->arg('$logger', service('monolog.logger.mautic'))
        ->tag('console.command');





};