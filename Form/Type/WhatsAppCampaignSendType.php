<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Form\Type;

use MauticPlugin\MauticZenderBundle\Service\WhatsAppMessageRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class WhatsAppCampaignSendType extends AbstractType
{
    public function __construct(
        private WhatsAppMessageRepository $messageRepository
    ) {
    }

    /**
     * @param FormBuilderInterface<array<string, mixed>|null> $builder
     * @param array<string, mixed>                            $options
     */
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $choices = [];

        foreach ($this->messageRepository->findPublishedChoices() as $message) {
            $choices[$message['name'].' (#'.$message['id'].')'] = $message['id'];
        }

        $builder->add(
            'whatsapp_message',
            ChoiceType::class,
            [
                'label' => 'mautic.zender.whatsapp.campaign.select',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.zender.whatsapp.campaign.select.help',
                ],
                'choices' => $choices,
                'placeholder' => 'mautic.zender.whatsapp.campaign.placeholder',
                'required' => true,
                'constraints' => [
                    new NotBlank([
                        'message' => 'mautic.zender.whatsapp.campaign.required',
                    ]),
                ],
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'zender_whatsapp_send';
    }
}
