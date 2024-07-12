<?php

return [
    'name'         => 'Zender',
    'description'  => 'This plugin replaces the SMS channel and allows you to send messages to WhatsApp using a Zender account. Intended for >= Mautic 5.1.0',
    'author'       => 'renato.carabelli@7catstudio.com',
    'version'      => '1.1.0',
    'release_date' => '2024-06-22',
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
    'last_updated' => '2024-06-22',
    'services' => [
        'events' => [
            'mautic.zender.plugin_activate.subscriber' => [
                'class' => 'MauticPlugin\MauticZenderBundle\EventListener\PluginActivatedEventListener',
                'arguments' => [
                    'mautic.lead.model.field',
                ],
            ],
        ],
        'forms'   => [],
        'helpers' => [],
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
    'routes'     => [],
    'menu'       => [
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