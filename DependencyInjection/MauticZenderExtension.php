<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\DependencyInjection;

use MauticPlugin\MauticZenderBundle\Controller\AjaxController;
use MauticPlugin\MauticZenderBundle\Controller\CampaignWhatsAppStatusController;
use MauticPlugin\MauticZenderBundle\Controller\ZenderControlController;
use MauticPlugin\MauticZenderBundle\Controller\WhatsAppMessageController;
use MauticPlugin\MauticZenderBundle\Controller\WhatsAppContactActionController;
use MauticPlugin\MauticZenderBundle\EventListener\WhatsAppCampaignSubscriber;
use MauticPlugin\MauticZenderBundle\EventListener\WhatsAppTimelineSubscriber;
use MauticPlugin\MauticZenderBundle\EventListener\WhatsAppContactPageButtonSubscriber;
use MauticPlugin\MauticZenderBundle\Form\Type\WhatsAppCampaignSendType;
use MauticPlugin\MauticZenderBundle\Form\Type\WhatsAppDemoSendType;
use MauticPlugin\MauticZenderBundle\Form\Type\WhatsAppContactSendType;
use MauticPlugin\MauticZenderBundle\Command\ZenderProviderStatusSyncCommand;
use MauticPlugin\MauticZenderBundle\Service\DispatchCapacityCalculator;
use MauticPlugin\MauticZenderBundle\Service\DispatchQuotaResolver;
use MauticPlugin\MauticZenderBundle\Service\ChannelNeutralEnqueueService;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppAssetUploadService;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppCampaignEnqueuer;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppSegmentEligibilityService;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppMessageRepository;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppProviderEnvelopeBuilder;
use MauticPlugin\MauticZenderBundle\Service\DispatchSettingsWriter;
use MauticPlugin\MauticZenderBundle\Service\Ai\AiSettingsRepository;
use MauticPlugin\MauticZenderBundle\Service\Ai\AiSettingsWriter;
use MauticPlugin\MauticZenderBundle\Service\Ai\AnthropicProviderClient;
use MauticPlugin\MauticZenderBundle\Service\Ai\GrokProviderClient;
use MauticPlugin\MauticZenderBundle\Service\Ai\OpenAiProviderClient;
use MauticPlugin\MauticZenderBundle\Service\ZenderAccountApiClient;
use MauticPlugin\MauticZenderBundle\Service\ZenderControlReadModel;
use MauticPlugin\MauticZenderBundle\Service\ZenderChatReadApiClient;
use MauticPlugin\MauticZenderBundle\Service\ZenderProviderStatusRepository;
use MauticPlugin\MauticZenderBundle\Service\ZenderProviderStatusSyncService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Mautic\LeadBundle\Segment\ContactSegmentService;
use Symfony\Component\DependencyInjection\Reference;

final class MauticZenderExtension extends Extension implements PrependExtensionInterface
{
    public function load(
        array $configs,
        ContainerBuilder $container
    ): void {
        $container
            ->register(
                'mautic.zender.ai.settings_repository',
                AiSettingsRepository::class
            )
            ->setArguments([
                new Reference('doctrine.dbal.default_connection'),
                new Reference('mautic.helper.encryption'),
            ])
            ->setPublic(true);

            $container->register(\MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxTokenProtector::class, \MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxTokenProtector::class)->setPublic(true);
            $container->register(\MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxPromptBuilder::class, \MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxPromptBuilder::class)->setPublic(true);
            $container->register(\MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxValidator::class, \MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxValidator::class)
                ->setArgument(0, new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxTokenProtector::class))
                ->setPublic(true);

        $container->register(\MauticPlugin\MauticZenderBundle\Service\Ai\AiTextProviderRouter::class, \MauticPlugin\MauticZenderBundle\Service\Ai\AiTextProviderRouter::class)
            ->setArguments([
                new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\OpenAiProviderClient::class),
                new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\AnthropicProviderClient::class),
                new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\GrokProviderClient::class),
            ])
            ->setPublic(true);

        $container->register(\MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxGenerationService::class, \MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxGenerationService::class)
            ->setArguments([
                new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\AiSettingsRepository::class),
                new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\AiTextProviderRouter::class),
                new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxTokenProtector::class),
                new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxPromptBuilder::class),
                new Reference(\MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxValidator::class),
            ])
            ->setPublic(true);

        $container
            ->setAlias(
                AiSettingsRepository::class,
                'mautic.zender.ai.settings_repository'
            )
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.ai.settings_writer',
                AiSettingsWriter::class
            )
            ->setArguments([
                new Reference('doctrine.dbal.default_connection'),
                new Reference('mautic.zender.ai.settings_repository'),
            ])
            ->setPublic(true);

        $container
            ->setAlias(
                AiSettingsWriter::class,
                'mautic.zender.ai.settings_writer'
            )
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.ai.anthropic_provider_client',
                AnthropicProviderClient::class
            )
            ->setArguments([
                new Reference('mautic.http.client'),
                new Reference('monolog.logger.mautic'),
            ])
            ->setPublic(true);

        $container
            ->setAlias(
                AnthropicProviderClient::class,
                'mautic.zender.ai.anthropic_provider_client'
            )
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.ai.grok_provider_client',
                GrokProviderClient::class
            )
            ->setArguments([
                new Reference('mautic.http.client'),
                new Reference('monolog.logger.mautic'),
            ])
            ->setPublic(true);

        $container
            ->setAlias(
                GrokProviderClient::class,
                'mautic.zender.ai.grok_provider_client'
            )
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.ai.openai_provider_client',
                OpenAiProviderClient::class
            )
            ->setArguments([
                new Reference('mautic.http.client'),
                new Reference('monolog.logger.mautic'),
            ])
            ->setPublic(true);

        $container
            ->setAlias(
                OpenAiProviderClient::class,
                'mautic.zender.ai.openai_provider_client'
            )
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.account_api_client',
                ZenderAccountApiClient::class
            )
            ->setArguments([
                new Reference('mautic.helper.integration'),
                new Reference('mautic.http.client'),
                new Reference('monolog.logger.mautic'),
            ])
            ->setPublic(true);

        $container
            ->setAlias(
                ZenderAccountApiClient::class,
                'mautic.zender.account_api_client'
            )
            ->setPublic(true);

        $container
            ->register(
                DispatchCapacityCalculator::class,
                DispatchCapacityCalculator::class
            )
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container
            ->register(
                DispatchQuotaResolver::class,
                DispatchQuotaResolver::class
            )
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container
            ->register(
                DispatchSettingsWriter::class,
                DispatchSettingsWriter::class
            )
            ->setArguments([
                new Reference('doctrine.dbal.default_connection'),
                new Reference(
                    'mautic.zender.dispatch_config_repository'
                ),
                new Reference(
                    'mautic.zender.dispatch_account_repository'
                ),
                new Reference(
                    DispatchCapacityCalculator::class
                ),
            ])
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container
            ->register(
                ZenderControlReadModel::class,
                ZenderControlReadModel::class
            )
            ->setArguments([
                new Reference(
                    'mautic.zender.dispatch_config_repository'
                ),
                new Reference(
                    'mautic.zender.dispatch_account_repository'
                ),
                new Reference(
                    'mautic.zender.dispatch_queue_repository'
                ),
                new Reference(
                    'mautic.zender.received_chat_repository'
                ),
                new Reference(
                    ZenderAccountApiClient::class
                ),
            ])
            ->setAutoconfigured(true)
            ->setPublic(true);

        // PHASE_4A2_PROVIDER_STATUS_EXTENSION_REGISTRATION_BEGIN
        $container
            ->register(
                'mautic.zender.chat_read_api_client',
                ZenderChatReadApiClient::class
            )
            ->setArguments([
                new Reference('mautic.helper.integration'),
                new Reference('mautic.http.client'),
                new Reference('monolog.logger.mautic'),
            ])
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.provider_status_repository',
                ZenderProviderStatusRepository::class
            )
            ->setArguments([
                new Reference('doctrine.dbal.default_connection'),
            ])
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.provider_status_sync_service',
                ZenderProviderStatusSyncService::class
            )
            ->setArguments([
                new Reference('mautic.zender.chat_read_api_client'),
                new Reference('mautic.zender.provider_status_repository'),
            ])
            ->setPublic(true);

        $container
            ->register(
                ZenderProviderStatusSyncCommand::class,
                ZenderProviderStatusSyncCommand::class
            )
            ->setArguments([
                new Reference(
                    'mautic.zender.provider_status_sync_service'
                ),
            ])
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('console.command');
        // PHASE_4A2_PROVIDER_STATUS_EXTENSION_REGISTRATION_END

        $container
            ->register(
                WhatsAppMessageRepository::class,
                WhatsAppMessageRepository::class
            )
            ->setArguments([
                new Reference('doctrine.dbal.default_connection'),
            ])
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.whatsapp_provider_envelope_builder',
                WhatsAppProviderEnvelopeBuilder::class
            )
            ->setArguments([
                new Reference(WhatsAppMessageRepository::class),
                new Reference('mautic.asset.model.asset'),
                new Reference('mautic.helper.core_parameters'),
            ])
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container
            ->setAlias(
                WhatsAppProviderEnvelopeBuilder::class,
                'mautic.zender.whatsapp_provider_envelope_builder'
            )
            ->setPublic(true);

        $container
            ->register(
                'mautic.zender.whatsapp_asset_upload_service',
                WhatsAppAssetUploadService::class
            )
            ->setArguments([
                new Reference('mautic.asset.model.asset'),
                new Reference('mautic.helper.core_parameters'),
            ])
            ->setPublic(true);

        $container
            ->setAlias(
                WhatsAppAssetUploadService::class,
                'mautic.zender.whatsapp_asset_upload_service'
            )
            ->setPublic(true);

        $container
            ->register(
                ChannelNeutralEnqueueService::class,
                ChannelNeutralEnqueueService::class
            )
            ->setArguments([
                new Reference(
                    'mautic.zender.dispatch_account_repository'
                ),
                new Reference(
                    'mautic.zender.dispatch_queue_repository'
                ),
            ])
            ->setPublic(true);

        $container
            ->register(
                WhatsAppCampaignEnqueuer::class,
                WhatsAppCampaignEnqueuer::class
            )
            ->setArguments([
                new Reference(ChannelNeutralEnqueueService::class),
                new Reference('mautic.lead.model.dnc'),
            ])
            ->setPublic(true);

        $container
            ->register(
                WhatsAppSegmentEligibilityService::class,
                WhatsAppSegmentEligibilityService::class
            )
            ->setArguments([
                new Reference(
                    'doctrine.dbal.default_connection'
                ),
                new Reference('mautic.lead.model.lead'),
                new Reference(WhatsAppCampaignEnqueuer::class),
            ])
            ->setPublic(true);

        $container
            ->register(
                WhatsAppCampaignSendType::class,
                WhatsAppCampaignSendType::class
            )
            ->setArguments([
                new Reference(WhatsAppMessageRepository::class),
            ])
            ->setPublic(true)
            ->addTag('form.type');

        $container
            ->register(
                WhatsAppDemoSendType::class,
                WhatsAppDemoSendType::class
            )
            ->setArguments([
                new Reference('translator'),
            ])
            ->setPublic(true)
            ->addTag('form.type');

        $container
            ->register(
                WhatsAppCampaignSubscriber::class,
                WhatsAppCampaignSubscriber::class
            )
            ->setArguments([
                new Reference(WhatsAppMessageRepository::class),
                new Reference(WhatsAppCampaignEnqueuer::class),
                new Reference('translator'),
            ])
            ->setPublic(true)
            ->addTag('kernel.event_subscriber');

        $container
            ->register(
                WhatsAppTimelineSubscriber::class,
                WhatsAppTimelineSubscriber::class
            )
            ->setArguments([
                new Reference(
                    'mautic.zender.dispatch_queue_repository'
                ),
                new Reference('translator'),
                new Reference('router'),
            ])
            ->setPublic(true)
            ->addTag('kernel.event_subscriber');

        $container
            ->register(
                WhatsAppContactPageButtonSubscriber::class,
                WhatsAppContactPageButtonSubscriber::class
            )
            ->setArgument(
                '$router',
                new Reference('router')
            )
            ->setArgument(
                '$translator',
                new Reference('translator')
            )
            ->setPublic(true)
            ->addTag('kernel.event_subscriber');

        $container
            ->register(
                WhatsAppContactSendType::class,
                WhatsAppContactSendType::class
            )
            ->setPublic(true)
            ->addTag('form.type');

        $container
            ->register(
                WhatsAppContactActionController::class,
                WhatsAppContactActionController::class
            )
            ->setArgument(
                '$leadModel',
                new Reference('mautic.lead.model.lead')
            )
            ->setArgument(
                '$whatsAppEnqueuer',
                new Reference(WhatsAppCampaignEnqueuer::class)
            )
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments');

        $container
            ->register(
                WhatsAppMessageController::class,
                WhatsAppMessageController::class
            )
            ->setArgument(
                '$whatsAppEnqueuer',
                new Reference(WhatsAppCampaignEnqueuer::class)
            )
            ->setArgument(
                '$leadModel',
                new Reference('mautic.lead.model.lead')
            )
            ->setArgument(
                '$listModel',
                new Reference('mautic.lead.model.list')
            )
            ->setArgument(
                '$contactSegmentService',
                new Reference(ContactSegmentService::class)
            )
            ->setArgument(
                '$segmentEligibilityService',
                new Reference(
                    WhatsAppSegmentEligibilityService::class
                )
            )
            ->setArgument(
                '$spintaxGenerationService',
                new Reference(
                    \MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxGenerationService::class
                )
            )
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments');

        $container
            ->register(
                AjaxController::class,
                AjaxController::class
            )
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments');

        $container
            ->register(
                ZenderControlController::class,
                ZenderControlController::class
            )
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments');
        $container
            ->register(
                CampaignWhatsAppStatusController::class,
                CampaignWhatsAppStatusController::class
            )
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->setArgument(
                '$queueRepository',
                new Reference('mautic.zender.dispatch_queue_repository')
            )
            ->addTag('controller.service_arguments');
    }

    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('twig')) {
            $container->prependExtensionConfig('twig', [
                'paths' => [
                    dirname(__DIR__).'/Resources/views'
                        => 'MauticZender',
                ],
            ]);
        }
    }
}
