<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractType<mixed>
 */
final class WhatsAppContactSendType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder->add(
            'content',
            TextareaType::class,
            [
                'label' => (
                    'mautic.zender.whatsapp.contact.send.message'
                ),
                'required' => true,
                'attr' => [
                    'rows' => 8,
                    'maxlength' => 4096,
                    'placeholder' => (
                        'mautic.zender.whatsapp.contact.'
                        .'send.placeholder'
                    ),
                    'autocomplete' => 'off',
                ],
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 4096),
                ],
            ]
        );

        $builder->add(
            'multimedia_file',
            FileType::class,
            [
                'label' => (
                    'mautic.zender.whatsapp.contact.'
                    .'send.attachment'
                ),
                'help' => (
                    'mautic.zender.whatsapp.contact.'
                    .'send.attachment_help'
                ),
                'mapped' => false,
                'required' => false,
            ]
        );

        $builder->add(
            'request_id',
            HiddenType::class,
            [
                'constraints' => [
                    new Regex('/^[a-f0-9]{32}$/'),
                ],
            ]
        );

        $builder->add(
            'buttons',
            FormButtonsType::class,
            [
                'apply_text' => false,
                'save_text' => (
                    'mautic.zender.whatsapp.contact.send.submit'
                ),
                'save_icon' => 'ri-whatsapp-line',
                'cancel_text' => 'mautic.core.form.cancel',
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'zender_whatsapp_contact_send';
    }
}
