<?php

namespace MauticPlugin\MauticZenderBundle\EventListener;

use Mautic\PluginBundle\PluginEvents;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\LeadBundle\Model\FieldModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginActivatedEventListener implements EventSubscriberInterface
{
    /**
     * @var FieldModel
     */
    private $fieldModel;

    public function __construct(FieldModel $fieldModel)
    {
        $this->fieldModel = $fieldModel;
    }

    /**
     * Returns an array of events this subscriber wants to listen to.
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstall', 0],
        ];
    }

    public function onPluginInstall(PluginInstallEvent $event)
    {
        if ($event->getPlugin()->getName() === 'Zender') {
            // Check if the custom field already exists
            $existingField = $this->fieldModel->getRepository()->findOneByAlias('id_whatsapp_in_zender');
            if (!$existingField) {
                // Create the custom field
                $field = new \Mautic\LeadBundle\Entity\LeadField();
                $field->setName('ID WhatsApp in Zender');
                $field->setAlias('id_whatsapp_in_zender');
                $field->setType('text');
                $field->setGroup('core');
                $field->setObject('lead');
                $field->setIsPublished(true);

                // Save the custom field
                $this->fieldModel->saveEntity($field);
            }
        }
    }
}
