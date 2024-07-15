<?php

namespace MauticPlugin\MauticZenderBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use MauticPlugin\MauticZenderBundle\Entity\ZenderApiRequestLog;
use MauticPlugin\MauticZenderBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class SyncMessagesCommand extends Command
{
    protected static $defaultName = 'mautic:zender:sync-messages';

    private $entityManager;
    private $integrationHelper;
    private $logger;
    private $zenderApiKey;
    private $zenderApiBaseUrl;
    private $fetchQuantity;
    private $fetchUnit;
    private $batchSize;

    public function __construct(EntityManagerInterface $entityManager, IntegrationHelper $integrationHelper, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->integrationHelper = $integrationHelper;
        $this->logger = $logger;

        $integration = $this->integrationHelper->getIntegrationObject('Zender');
        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $keys = $integration->getDecryptedApiKeys();
            $this->zenderApiKey = $keys['zender_api_key'] ?? '';
            $this->zenderApiBaseUrl = isset($keys['zender_api_url']) ? rtrim($keys['zender_api_url'], '/') . '/' : '';
            $this->fetchQuantity = $keys['fetch_quantity'] ?? '7';
            $this->fetchUnit = $keys['fetch_unit'] ?? 'days';
            $this->batchSize = $keys['batch_size'] ?? '50';
        }

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Sync messages from Zender');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger->info('Starting sync messages command.');
        $output->writeln('Starting sync messages command.');

        try {
            $this->fetchAndProcessMessages('wa.pending', $output);
            $this->fetchAndProcessMessages('wa.received', $output);
            $this->fetchAndProcessMessages('wa.sent', $output);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching and processing messages: ' . $e->getMessage());
            $output->writeln('Error fetching and processing messages: ' . $e->getMessage());
        }

        $this->logger->info('Calling processUnprocessedMessages after fetching messages.');
        $output->writeln('Calling processUnprocessedMessages after fetching messages.');

        try {
            $this->processUnprocessedMessages($output);
        } catch (\Exception $e) {
            $this->logger->error('Error processing unprocessed messages: ' . $e->getMessage());
            $output->writeln('Error processing unprocessed messages: ' . $e->getMessage());
        }

        $this->logger->info('Finished sync messages command.');
        $output->writeln('Finished sync messages command.');

        return Command::SUCCESS;
    }

    private function fetchAndProcessMessages(string $type, OutputInterface $output)
    {
        $this->logger->info("Starting to fetch and process messages of type: $type");

        $limit = $this->batchSize;
        $page = 1;
        $continueFetching = true;
        $lastProcessedAt = $this->getLastProcessedAt($type);

        // Use antiquity setting if no messages have been processed yet
        if ($lastProcessedAt) {
            $this->logger->info("Last processed at: " . $lastProcessedAt->format('Y-m-d H:i:s'));
            $maxAgeInSeconds = time() - $lastProcessedAt->getTimestamp();
        } else {
            $this->logger->info("No last processed timestamp found, using fetch quantity and unit.");
            $maxAgeInSeconds = $this->convertToSeconds($this->fetchQuantity, $this->fetchUnit);
        }

        while ($continueFetching) {
            $url = "{$this->zenderApiBaseUrl}get/{$type}?secret={$this->zenderApiKey}&limit={$limit}&page={$page}";

            $this->logger->info("Fetching messages from URL: $url");

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, $url);
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response === false) {
                $output->writeln("Failed to fetch {$type} messages");
                $this->logger->error("Failed to fetch {$type} messages");
                return;
            }

            $result = json_decode($response, true);

            if ($result['status'] !== 200) {
                $output->writeln("Error fetching {$type} messages: " . $result['message']);
                $this->logger->error("Error fetching {$type} messages: " . $result['message']);
                return;
            }

            foreach ($result['data'] as $message) {
                $messageCreatedAt = new \DateTime('@' . $message['created']);
                if (!$lastProcessedAt || $messageCreatedAt->getTimestamp() > $lastProcessedAt->getTimestamp()) {
                    if ((time() - $messageCreatedAt->getTimestamp()) <= $maxAgeInSeconds) {
                        $output->writeln("Processing {$type} message: " . json_encode($message));
                        $this->logger->info("Processing {$type} message: " . json_encode($message));
                        $this->logApiRequest($type, $messageCreatedAt, $messageCreatedAt, $result['data'], $result['status']);
                    } else {
                        $continueFetching = false;
                        break;
                    }
                } else {
                    $continueFetching = false;
                    break;
                }
            }

            $page++;
            if (count($result['data']) < $limit) {
                $continueFetching = false;
            }
        }

        $this->logger->info("Finished fetching and processing messages of type: $type");
    }

    private function logApiRequest(string $type, ?\DateTime $firstMessageAt, ?\DateTime $lastMessageAt, array $responseData, int $status)
    {
        $this->logger->info("Logging API request for type: $type");
        $this->logger->info("First message at: " . ($firstMessageAt ? $firstMessageAt->format('Y-m-d H:i:s') : 'NULL'));
        $this->logger->info("Last message at: " . ($lastMessageAt ? $lastMessageAt->format('Y-m-d H:i:s') : 'NULL'));
        $this->logger->info("Response data: " . json_encode($responseData));
        $this->logger->info("Status: $status");

        $log = new ZenderApiRequestLog();
        $log->setRequestedAt(new \DateTime());
        $log->setFirstMessageAt($firstMessageAt);
        $log->setLastMessageAt($lastMessageAt);
        $log->setResponseData(json_encode($responseData));
        $log->setStatus((string)$status);
        $log->setMessageType($type);
        $log->setProcessedAt(null);  // Ensure this is set to null

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function processUnprocessedMessages(OutputInterface $output)
    {
        $this->logger->info("Starting to process unprocessed messages.");
        $logs = $this->entityManager->getRepository(ZenderApiRequestLog::class)->findBy(['processedAt' => null]);

        foreach ($logs as $log) {
            $responseData = json_decode($log->getResponseData(), true);
            foreach ($responseData as $message) {
                $lead = $this->entityManager->getRepository(Lead::class)->findOneBy(['phone' => $message['recipient']]);
                if ($lead) {
                    $this->logger->info("Updating lead with message: " . json_encode($message));
                    $this->updateLeadWithMessage($lead, $message, $log->getMessageType());
                }
            }

            $log->setProcessedAt(new \DateTime());
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        }

        $this->logger->info("Finished processing unprocessed messages.");
    }

    private function updateLeadWithMessage($lead, $message, $messageType)
    {
        if ($messageType === 'wa.received') {
            $lead->setLastReceivedMessageDate(new \DateTime('@' . $message['created']));
            $lead->setLastReceivedMessageContent(substr($message['message'], 0, 150)); // Truncate content to 150 characters
            $lead->setLastReceivedMessageStatus('success');
        } elseif ($messageType === 'wa.sent') {
            $lead->setLastSentMessageDate(new \DateTime('@' . $message['created']));
            $lead->setLastSentMessageStatus($message['status']);
            $lead->setLastSentMessageContent(substr($message['message'], 0, 150)); // Truncate content to 150 characters
        }

        $this->entityManager->persist($lead);
        $this->entityManager->flush();
    }

    private function getLastProcessedAt($type): ?\DateTime
    {
        $lastLog = $this->entityManager->getRepository(ZenderApiRequestLog::class)->findOneBy(
            ['messageType' => $type],
            ['processedAt' => 'DESC']
        );

        return $lastLog ? $lastLog->getProcessedAt() : null;
    }

    private function convertToSeconds($quantity, $unit)
    {
        switch ($unit) {
            case 'minutes':
                return $quantity * 60;
            case 'hours':
                return $quantity * 3600;
            case 'days':
                return $quantity * 86400;
            case 'weeks':
                return $quantity * 604800;
            case 'months':
                return $quantity * 2592000;
            case 'years':
                return $quantity * 31536000;
            default:
                return $quantity * 86400;
        }
    }
}
