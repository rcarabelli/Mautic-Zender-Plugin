<?php

namespace MauticPlugin\MauticZenderBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Class ZenderIntegration.
 */
class ZenderIntegration extends AbstractIntegration
{
    const AUTH_TYPE_NONE = 'none';  // Definir la constante aquí

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Zender';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'plugins/MauticZenderBundle/Assets/img/7cats-isotipo-red-200x200.png';
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getSecretKeys(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getRequiredKeyFields(): array
    {
        return [
            'zender_api_key' => 'mautic.plugin.zender.api_key',
            'zender_api_url' => 'mautic.plugin.zender.api_url',
            'shortener_url'  => 'mautic.plugin.zender.shortener_url',
            'webhook_secret' => 'mautic.plugin.zender.webhook_secret',
        ];
    }

    /**
     * @return array
     */
    public function getFormSettings(): array
    {
        return [
            'requires_callback'      => false,
            'requires_authorization' => false,
        ];
    }

    public function appendToForm(&$builder, $data, $formArea): void
    {
        // Obtener el valor actual del webhook_secret
        $currentSecret = isset($data['webhook_secret']) ? $data['webhook_secret'] : $this->generateSecret();

        $builder->add(
            'webhook_secret',
            TextType::class,
            [
                'label'      => 'mautic.plugin.zender.webhook_secret',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class' => 'form-control',
                    'id'    => 'webhook_secret',
                    'readonly' => true,
                ],
                'data' => $currentSecret,
                'constraints' => [
                    new NotBlank(
                        ['message' => 'mautic.core.value.required']
                    ),
                ],
            ]
        );
    }

    private function generateSecret($length = 40): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    public function getAuthenticationType(): string
    {
        return self::AUTH_TYPE_NONE;
    }
}
