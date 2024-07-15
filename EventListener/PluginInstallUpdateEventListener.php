<?php

namespace MauticPlugin\MauticZenderBundle\EventListener;

use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Mautic\LeadBundle\Model\FieldModel;

class PluginInstallUpdateEventListener implements EventSubscriberInterface
{
    private $entityManager;
    private $logger;
    private $fieldModel;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger, FieldModel $fieldModel)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->fieldModel = $fieldModel;
    }

    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstallOrUpdate', 0],
            PluginEvents::ON_PLUGIN_UPDATE => ['onPluginInstallOrUpdate', 0],
        ];
    }

    public function onPluginInstallOrUpdate($event)
    {
        if ($event->getPlugin()->getName() === 'Zender') {
            $this->logger->info('Zender plugin installation/update detected.');

            $this->createOrUpdateField('id_whatsapp_in_zender', 'ID WhatsApp in Zender');
            $this->createOrUpdateField('pruebas_de_zender_mautic', 'Pruebas de Zender Mautic');
            $this->createOrUpdateField('nuevo_campo', 'Nuevo Campo');

            $this->createControlTable();

            if ($event instanceof PluginUpdateEvent) {
                $this->removeFields();
            }
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

    private function removeFields()
    {
        $this->logger->info('Removing custom fields "nuevo_campo" and "pruebas_de_zender_mautic".');

        try {
            $fieldAliases = ['nuevo_campo', 'pruebas_de_zender_mautic'];
            foreach ($fieldAliases as $alias) {
                $field = $this->fieldModel->getRepository()->findOneByAlias($alias);
                if ($field) {
                    $this->fieldModel->deleteEntity($field);
                    $this->logger->info("Custom field '{$alias}' removed successfully.");
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error removing custom fields: ' . $e->getMessage());
        }
    }
}
