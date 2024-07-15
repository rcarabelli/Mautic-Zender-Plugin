<?php

namespace MauticPlugin\MauticZenderBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ZenderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if ($options['formArea'] === 'auth') {
            $builder->add(
                'zender_api_key',
                TextType::class,
                [
                    'label' => 'mautic.plugin.zender.api_key',
                    'required' => true,
                    'attr' => [
                        'class' => 'form-control',
                    ],
                ]
            );

            $builder->add(
                'zender_api_url',
                TextType::class,
                [
                    'label' => 'mautic.plugin.zender.api_url',
                    'required' => true,
                    'attr' => [
                        'class' => 'form-control',
                    ],
                ]
            );

            $builder->add(
                'shortener_url',
                TextType::class,
                [
                    'label' => 'mautic.plugin.zender.shortener_url',
                    'required' => true,
                    'attr' => [
                        'class' => 'form-control',
                    ],
                ]
            );
        }

        if ($options['formArea'] === 'features') {
            $builder->add(
                'fetch_quantity',
                TextType::class,
                [
                    'label' => 'mautic.plugin.zender.fetch_quantity',
                    'required' => true,
                    'attr' => [
                        'class' => 'form-control',
                    ],
                    'help' => 'mautic.plugin.zender.fetch_quantity.description',
                ]
            );

            $builder->add(
                'fetch_unit',
                ChoiceType::class,
                [
                    'choices' => [
                        'Minutes' => 'minutes',
                        'Hours' => 'hours',
                        'Days' => 'days',
                        'Weeks' => 'weeks',
                        'Months' => 'months',
                        'Years' => 'years',
                    ],
                    'label' => 'mautic.plugin.zender.fetch_unit',
                    'required' => true,
                    'attr' => [
                        'class' => 'form-control',
                    ],
                    'help' => 'mautic.plugin.zender.fetch_unit.description',
                ]
            );

            $builder->add(
                'batch_size',
                TextType::class,
                [
                    'label' => 'mautic.plugin.zender.batch_size',
                    'required' => true,
                    'attr' => [
                        'class' => 'form-control',
                    ],
                    'help' => 'mautic.plugin.zender.batch_size.description',
                ]
            );
        }

        if ($options['formArea'] === 'instructions') {
            $builder->add(
                'instructions',
                TextType::class,
                [
                    'label' => 'mautic.plugin.zender.instructions',
                    'attr' => [
                        'class' => 'form-control',
                        'readonly' => true,
                    ],
                    'data' => 'This tab contains detailed instructions and information about how to configure and use the Zender plugin.',
                ]
            );
        }
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'formArea' => 'auth',
        ]);
    }
}
