<?php

namespace MauticPlugin\MauticZenderBundle\Helper;

use Doctrine\ORM\EntityManagerInterface;

class SyncMessagesHelper
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getLastExecution()
    {
        $connection = $this->entityManager->getConnection();
        $sql = "SELECT value FROM maugittest_plugin_integration_settings WHERE name = 'zender_last_sync'";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        return $result ? $result['value'] : null;
    }

    public function updateLastExecution($timestamp)
    {
        $connection = $this->entityManager->getConnection();
        $sql = "INSERT INTO maugittest_plugin_integration_settings (name, value) VALUES ('zender_last_sync', :value)
                ON DUPLICATE KEY UPDATE value = :value";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('value', $timestamp);
        $stmt->execute();
    }

    public function getDefaultStartDate()
    {
        // Return the timestamp of 7 days ago
        return time() - (7 * 24 * 60 * 60);
    }
}
