<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticZenderv2Bundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Class Zenderv2Integration.
 */
class Zenderv2Integration extends AbstractIntegration
{
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getName()
    {
        return 'Zenderv2';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    
    public function getIcon()
    {
        return 'plugins/MauticZenderv2Bundle/Assets/img/7cats-isotipo-red-200x200.png';
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getSecretKeys()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getRequiredKeyFields()
    {
        return [
            'zenderv2_api_key' => 'mautic.plugin.zenderv2.api_key',
            'zenderv2_api_url' => 'mautic.plugin.zenderv2.api_url',
            'webhookv2_url'    => 'mautic.plugin.zenderv2.webhookv2_url',
        ];
    }

    /**
     * @return array
     */
    public function getFormSettings()
    {
        return [
            'requires_callback'      => false,
            'requires_authorization' => false,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getAuthenticationType()
    {
        return 'none';
    }
}
