<?php

use MauticPlugin\MauticZenderBundle\Command\ZenderActivationCommand;
use MauticPlugin\MauticZenderBundle\Command\ZenderConfigCommand;
use MauticPlugin\MauticZenderBundle\Command\ZenderDispatchCommand;
use MauticPlugin\MauticZenderBundle\Command\ZenderReceivedChatImportCommand;
use MauticPlugin\MauticZenderBundle\Command\ZenderStatusCommand;
use MauticPlugin\MauticZenderBundle\Controller\ZenderControlController;
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

return [
    'name'         => 'Zender',
    'description'  => 'WhatsApp transport through Zender with 7 Cats controlled dispatch.',
    'author'       => 'renato.carabelli@7catstudio.com',
    'version'      => '2.2.2',
    'release_date' => '2026-08-02',
    'license'      => 'GNU/GPLv3',
    'homepage'     => 'https://github.com/rcarabelli/Mautic-Zender-Plugin',
    'support'      => 'https://www.7catstudio.com or requests@7catstudio.com',
    'requirements' => ['mautic' => '>=6.0.5', 'php' => '>=8.2'],
    'services' => [
        'events' => [
            'mautic.zender.plugin_activate.subscriber' => [
                'class' => 'MauticPlugin\MauticZenderBundle\EventListener\PluginActivatedEventListener',
                'arguments' => ['mautic.lead.model.field'],
            ],
        ],
        'other' => [

            'mautic.zender.dispatch_account_repository' => [
                'class' => DispatchAccountRepository::class,
                'arguments' => ['doctrine.dbal.default_connection'],
            ],
            'mautic.zender.dispatch_attempt_repository' => [
                'class' => DispatchAttemptRepository::class,
                'arguments' => ['doctrine.dbal.default_connection'],
            ],
            'mautic.zender.dispatch_config_repository' => [
                'class' => DispatchConfigRepository::class,
                'arguments' => ['doctrine.dbal.default_connection'],
            ],
            'mautic.zender.dispatch_quota_resolver' => [
                'class' => DispatchQuotaResolver::class,
            ],
            'mautic.zender.retry_safety_classifier' => [
                'class' => RetrySafetyClassifier::class,
            ],
            'mautic.zender.guarded_retry_scheduler' => [
                'class' => GuardedRetryScheduler::class,
                'arguments' => [
                    'doctrine.dbal.default_connection',
                    'mautic.zender.dispatch_attempt_repository',
                ],
            ],
            'mautic.zender.round_robin_planner' => [
                'class' => RoundRobinPlanner::class,
            ],
            'mautic.zender.dispatch_queue_repository' => [
                'class' => DispatchQueueRepository::class,
                'arguments' => [
                    'doctrine.dbal.default_connection',
                    'mautic.zender.round_robin_planner',
                ],
            ],
            'mautic.sms.transport.zender' => [
                'class' => ZenderTransport::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'monolog.logger.mautic',
                    'mautic.http.client',
                    'doctrine.orm.entity_manager',
                    'mautic.zender.dispatch_account_repository',
                    'mautic.zender.dispatch_config_repository',
                    'mautic.zender.dispatch_queue_repository',
                    'mautic.zender.whatsapp_provider_envelope_builder',
                ],
            ],
            'mautic.zender.command.activation' => [
                'class' => ZenderActivationCommand::class,
                'arguments' => [
                    'mautic.zender.dispatch_config_repository',
                    'mautic.zender.dispatch_account_repository',
                ],
                'tag' => 'console.command',
            ],
            'mautic.zender.command.status' => [
                'class' => ZenderStatusCommand::class,
                'arguments' => [
                    'mautic.zender.dispatch_config_repository',
                    'mautic.zender.dispatch_queue_repository',
                    'mautic.zender.dispatch_account_repository',
                    'mautic.zender.dispatch_attempt_repository',
                ],
                'tag' => 'console.command',
            ],
            'mautic.zender.command.config' => [
                'class' => ZenderConfigCommand::class,
                'arguments' => ['mautic.zender.dispatch_config_repository'],
                'tag' => 'console.command',
            ],
            'mautic.zender.command.dispatch' => [
                'class' => ZenderDispatchCommand::class,
                'arguments' => [
                    'mautic.zender.dispatch_config_repository',
                    'mautic.zender.dispatch_account_repository',
                    'mautic.zender.dispatch_quota_resolver',
                    'mautic.zender.dispatch_queue_repository',
                    'mautic.zender.dispatch_attempt_repository',
                    'mautic.zender.retry_safety_classifier',
                    'mautic.zender.guarded_retry_scheduler',
                    'mautic.zender.round_robin_planner',
                    'mautic.zender.account_api_client',
                    'mautic.sms.transport.zender',
                    'monolog.logger.mautic',
                ],
                'tag' => 'console.command',
            ],
            'mautic.zender.received_chat_repository' => [
                'class' => ZenderReceivedChatRepository::class,
                'arguments' => [
                    'doctrine.dbal.default_connection',
                ],
            ],
            'mautic.zender.received_chat_api_client' => [
                'class' => ZenderReceivedChatApiClient::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'mautic.http.client',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.zender.received_chat_importer' => [
                'class' => ZenderReceivedChatImporter::class,
                'arguments' => [
                    'mautic.zender.received_chat_api_client',
                    'doctrine.dbal.default_connection',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.zender.command.received_chat_import' => [
                'class' => ZenderReceivedChatImportCommand::class,
                'arguments' => [
                    'mautic.zender.received_chat_importer',
                    'monolog.logger.mautic',
                ],
                'tag' => 'console.command',
            ],
        ],
        'integrations' => [
            'mautic.integration.zender' => [
                'class' => ZenderIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'logger',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
    ],
    'routes' => [
        'main' => [
            'mautic_zender_control_index' => [
                'path' => '/zender-control',
                'controller' => 'MauticPlugin\\MauticZenderBundle\\Controller\\ZenderControlController::indexAction',
            ],
            'mautic_zender_ai_models' => [
                'path' => '/zender-control/ai/models',
                'controller' => 'MauticPlugin\\MauticZenderBundle\\Controller\\ZenderControlController::aiModelsAction',
                'methods' => ['POST'],
            ],
            'mautic_zender_whatsapp_messages' => [
                'path' => '/zender-whatsapp-messages',
                'controller' => 'MauticPlugin\\MauticZenderBundle\\Controller\\WhatsAppMessageController::indexAction',
            ],
            'mautic_zender_whatsapp_segment_enqueue' => [
                'path' => '/zender-whatsapp-messages/segment-enqueue',
                'controller' => 'MauticPlugin\\MauticZenderBundle\\Controller\\WhatsAppMessageController::enqueueSegmentBatchAction',
                'methods' => ['POST'],
            ],
            'mautic_zender_whatsapp_segment_cancel' => [
                'path' => '/zender-whatsapp-messages/segment-cancel',
                'controller' => 'MauticPlugin\\MauticZenderBundle\\Controller\\WhatsAppMessageController::cancelSegmentQueueAction',
                'methods' => ['POST'],
            ],
            'mautic_zender_whatsapp_spintax_generate' => [
                'path' => '/zender-whatsapp-messages/spintax-generate',
                'controller' => 'MauticPlugin\\MauticZenderBundle\\Controller\\WhatsAppMessageController::generateSpintaxAction',
                'methods' => ['POST'],
            ],
            'mautic_zender_whatsapp_contact_send' => [
                'path' => '/zender-whatsapp/contact/{contactId}/send',
                'controller' => 'MauticPlugin\\MauticZenderBundle\\Controller\\WhatsAppContactActionController::sendAction',
            ],
            'mautic_zender_campaign_whatsapp_status' => [
                'path' => '/zender-whatsapp/campaign/{campaignId}/statuses',
                'controller' => 'MauticPlugin\\MauticZenderBundle\\Controller\\CampaignWhatsAppStatusController::indexAction',
                'requirements' => ['campaignId' => '\\d+'],
                'methods' => ['GET'],
            ],
        ],
    ],
    'menu' => [
        'main' => [
            'items' => [
                'mautic.zender.whatsapp.messages' => [
                    'route' => 'mautic_zender_whatsapp_messages',
                    'access' => [
                        'campaign:campaigns:viewown',
                        'campaign:campaigns:viewother',
                    ],
                    'parent' => 'mautic.core.channels',
                    'checks' => [
                        'integration' => [
                            'Zender' => ['enabled' => true],
                        ],
                    ],
                    'priority' => 68,
                ],
                'mautic.zender.control_center' => [
                    'route' => 'mautic_zender_control_index',
                    'access' => ['sms:smses:viewown', 'sms:smses:viewother'],
                    'parent' => 'mautic.core.channels',
                    'checks' => ['integration' => ['Zender' => ['enabled' => true]]],
                    'priority' => 69,
                ],
            ],
        ],
    ],
    'parameters' => [],
];