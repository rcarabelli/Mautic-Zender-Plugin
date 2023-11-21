<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

return [
    'name'        => 'Zender',
    'description' => 'Zender integration',
    'author'      => 'renato.carabelli@7catstudio.com',
    'version'     => '0.0.1',
    'routes' => [
        'public' => [
            'mautic_zender_receive_webhook' => [
                'path'       => '/zender/receive/{key}/{phone}/{message}/{time}/{datetime}',
                'controller' => 'mautic.zender.controller.webhook:receiveAction',
                'method'     => 'GET',
            ],
        ],
    ],

    
    'services' => [
        'events' => [
            'mautic.zender.plugin_activate.subscriber' => [
                'class' => 'MauticPlugin\MauticZenderBundle\EventListener\PluginActivatedEventListener',
                'arguments' => [
                    'mautic.lead.model.field',
                ],
            ],
        ],
        'controllers' => [
            'mautic.zender.controller.webhook' => [
                'class'     => 'MauticPlugin\MauticZenderBundle\Controller\ZenderWebhookController',
                'arguments' => [
                    '@monolog.logger.mautic', // Correct service for LoggerInterface
                ],
                'public'    => true,
            ],
        ],
        'forms'   => [
        ],
        'helpers' => [],
        'other'   => [
            'mautic.sms.transport.zender' => [
                'class'     => \MauticPlugin\MauticZenderBundle\Transport\ZenderTransport::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'monolog.logger.mautic',
                    'mautic.http.client',
                    'doctrine.orm.entity_manager'  // <-- Add this line
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

    'menu'       => [
        'main' => [
            'items' => [
                'mautic.zender.smses' => [  // Unique key for Zender
                    'route'    => 'mautic_sms_index',  // Update this if you have a custom route for Zender
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
