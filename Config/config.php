<?php

return [
    'name'         => 'Zender',
    'description'  => 'This plugin replaces the SMS channel and allows you to send messages to WhatsApp using a Zender account. Intended for >= Mautic 5.1.0',
    'author'       => 'renato.carabelli@7catstudio.com',
    'version'      => '1.1.14',
    'release_date' => '2024-07-14',
    'license'      => 'GNU/GPLv3',
    'homepage'     => 'https://github.com/rcarabelli/Mautic-Zender-Plugin',
    'support'      => 'https://www.7catstudio.com or requests@7catstudio.com',
    'requirements' => [
        'mautic' => '>=5.1.0',
        'php'    => '>=8.2',
        'dependencies' => [
            'zender' => 'https://codecanyon.net/item/zender-android-mobile-devices-as-sms-gateway-saas-platform/26594230'
        ]
    ],
    'last_updated' => '2024-07-13',
    'services' => [
        'config' => [
            'resource' => 'plugins/MauticZenderBundle/Config/services.yml',
        ],
        'command' => [
            'mautic.zender.command.sync_messages' => [
                'class'     => \MauticPlugin\MauticZenderBundle\Command\SyncMessagesCommand::class,
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'mautic.helper.integration',
                    'monolog.logger.mautic'
                ],
                'tag'       => 'console.command',
            ],
        ],
        'events' => [
            'mautic.zender.plugin_activate.subscriber' => [
                'class' => 'MauticPlugin\MauticZenderBundle\EventListener\PluginInstallUpdateEventListener',
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'monolog.logger.mautic',
                    'mautic.lead.model.field',
                ],
            ],
        ],
        'forms'   => [],
        'helpers' => [
            'mautic.zender.helper.sync_messages' => [
                'class' => \MauticPlugin\MauticZenderBundle\Helper\SyncMessagesHelper::class,
                'arguments' => ['doctrine.orm.entity_manager'],
            ],
        ],
        'other'   => [
            'mautic.sms.transport.zender' => [
                'class'     => \MauticPlugin\MauticZenderBundle\Transport\ZenderTransport::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'monolog.logger.mautic',
                    'mautic.http.client',
                    'doctrine.orm.entity_manager',
                ],
                'alias'        => 'mautic.sms.config.zender.transport',
                'tag'          => 'mautic.sms_transport',
                'tagArguments' => [
                    'integrationAlias' => 'Zender',
                ],
            ],
        ],
        'models'       => [],
        'integrations' => [
            'mautic.integration.zender' => [
                'class' => \MauticPlugin\MauticZenderBundle\Integration\ZenderIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
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
                ],
            ],
        ],
    ],
    'routes' => [
        'api' => [
            'mautic_zender_message_status' => [
                'path' => '/zender/message-status',
                'controller' => 'MauticPlugin\MauticZenderBundle\Controller\ApiController::zenderMessageStatus',
                'method' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'],
                'defaults' => [],
            ],
        ],
    ],
    'menu' => [
        'main' => [
            'items' => [
                'mautic.zender.smses' => [
                    'route'    => 'mautic_sms_index',
                    'access'   => ['sms:smses:viewown', 'sms:smses:viewother'],
                    'parent'   => 'mautic.core.channels',
                    'checks'   => [
                        'integration' => [
                            'Zender' => [
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'priority' => 70,
                ],
            ],
        ],
    ],
    'parameters' => [],
];
