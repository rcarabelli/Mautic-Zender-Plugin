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
    'name'        => 'Zenderv2',
    'description' => 'Zenderv2 integration',
    'author'      => 'renato.carabelli@7catstudio.com',
    'version'     => '0.0.1',
    'services' => [
        'events'  => [],
        'forms'   => [
        ],
        'helpers' => [],
        'other'   => [
            'mautic.sms.transport.zenderv2' => [
                'class'     => \MauticPlugin\MauticZenderv2Bundle\Transport\Zenderv2Transport::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'monolog.logger.mautic',
                    'mautic.http.client',
                ],
                'alias'        => 'mautic.sms.config.zenderv2.transport',
                'tag'          => 'mautic.sms_transport',
                'tagArguments' => [
                    'integrationAlias' => 'Zenderv2',
                ],
            ],
        ],
        'models'       => [],
        'integrations' => [
            'mautic.integration.zenderv2' => [
                'class' => \MauticPlugin\MauticZenderv2Bundle\Integration\Zenderv2Integration::class,
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
                'mautic.zenderv2.smses' => [  // Unique key for Zenderv2
                    'route'    => 'mautic_sms_index',  // Update this if you have a custom route for Zenderv2
                    'access'   => ['sms:smses:viewown', 'sms:smses:viewother'],
                    'parent'   => 'mautic.core.channels',
                    'checks'   => [
                        'integration' => [
                            'Zenderv2' => [
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
