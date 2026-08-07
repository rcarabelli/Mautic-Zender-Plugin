<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\LookupType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<mixed>
 */
final class WhatsAppDemoSendType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator
    ) {
    }

    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder->add(
            'contact',
            LookupType::class,
            [
                'required' => false,
                'label' => false,
                'attr' => [
                    'class' => 'form-control',
                    'data-callback' => (
                        'activateWhatsAppDemoContactLookupField'
                    ),
                    'data-toggle' => 'field-lookup',
                    'data-lookup-callback' => (
                        'updateWhatsAppDemoContactLookupListFilter'
                    ),
                    'data-chosen-lookup' => (
                        'plugin:zender:whatsAppDemoContactList'
                    ),
                    'placeholder' => $this->translator->trans(
                        'mautic.zender.whatsapp.messages.'
                        .'demo.placeholder'
                    ),
                    'data-no-record-message' => (
                        $this->translator->trans(
                            'mautic.core.form.nomatches'
                        )
                    ),
                    'autocomplete' => 'off',
                ],
            ]
        );

        $builder->add(
            'contact_id',
            HiddenType::class
        );
    }

    public function getBlockPrefix(): string
    {
        return 'zender_whatsapp_demo';
    }
}
