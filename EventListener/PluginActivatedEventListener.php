<?php

namespace MauticPlugin\MauticZenderBundle\EventListener;

use Mautic\PluginBundle\PluginEvents;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mautic\LeadBundle\Model\FieldModel;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;

class PluginActivatedEventListener implements EventSubscriberInterface
{
    private $fieldModel;
    private $logger;
    private $entityManager;

    public function __construct(FieldModel $fieldModel, LoggerInterface $logger, EntityManagerInterface $entityManager)
    {
        $this->fieldModel = $fieldModel;
        $this->logger = $logger;
        $this->entityManager = $entityManager;
    }

    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstallOrUpdate', 0],
            PluginEvents::ON_PLUGIN_UPDATE  => ['onPluginInstallOrUpdate', 0],
        ];
    }

    public function onPluginInstallOrUpdate($event)
    {
        if ($event->getPlugin()->getName() === 'Zender') {
            $this->logger->info('Zender plugin installation/update detected.');

            $this->createOrUpdateField('id_whatsapp_in_zender', 'ID WhatsApp in Zender');
            $this->createOrUpdateField('last_sent_message_date', 'Last Sent Message Date');
            $this->createOrUpdateField('last_sent_message_status', 'Last Sent Message Status');
            $this->createOrUpdateField('last_sent_message_content', 'Last Sent Message Content');
            $this->createOrUpdateField('last_received_message_date', 'Last Received Message Date');
            $this->createOrUpdateField('last_received_message_content', 'Last Received Message Content');
            $this->createOrUpdateField('last_received_message_status', 'Last Received Message Status');

            $this->createControlTable();
        }
    }

    private function createOrUpdateField($alias, $name)
    {
        $existingField = $this->fieldModel->getRepository()->findOneByAlias($alias);
        if (!$existingField) {
            $field = new \Mautic\LeadBundle\Entity\LeadField();
            $field->setName($name);
            $field->setAlias($alias);
            $field->setType('text');
            $field->setGroup('core');
            $field->setObject('lead');
            $field->setIsPublished(true);

            $this->fieldModel->saveEntity($field);
            $this->logger->info("Custom field '{$name}' created.");
        } else {
            $this->logger->info("Custom field '{$name}' already exists.");
        }
    }

    private function createControlTable()
    {
        $this->logger->info('Starting control table creation process.');

        try {
            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->entityManager);
            $this->logger->info('SchemaTool instantiated.');

            $classes = [$this->entityManager->getClassMetadata(\MauticPlugin\MauticZenderBundle\Entity\ZenderApiRequestLog::class)];
            $this->logger->info('Class metadata loaded.', ['classes' => $classes]);

            $this->logger->info('Beginning schema update.');
            $schemaTool->updateSchema($classes, true);
            $this->logger->info('Control table "zender_api_request_log" created successfully.');
        } catch (\Exception $e) {
            $this->logger->error('Error creating control table "zender_api_request_log": ' . $e->getMessage());
        }
    }
}
