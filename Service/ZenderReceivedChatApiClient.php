<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use GuzzleHttp\Client;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ZenderReceivedChatApiClient
{
    private const INTEGRATION_ALIAS = 'Zender';
    private const RECEIVED_PATH = '/api/get/wa.received';

    public function __construct(
        private readonly IntegrationHelper $integrationHelper,
        private readonly Client $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetchReceived(int $limit = 50, int $page = 1): array
    {
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        try {
            $integration = $this->integrationHelper
                ->getIntegrationObject(self::INTEGRATION_ALIAS);

            if (!is_object($integration)) {
                throw new RuntimeException(
                    'zender_integration_not_found'
                );
            }

            $settings = $integration->getIntegrationSettings();

            if (
                !is_object($settings)
                || !method_exists($settings, 'getIsPublished')
                || !$settings->getIsPublished()
            ) {
                throw new RuntimeException(
                    'zender_integration_not_published'
                );
            }

            $keys = $integration->getDecryptedApiKeys();

            if (!is_array($keys)) {
                throw new RuntimeException(
                    'zender_integration_keys_unavailable'
                );
            }

            $secret = trim(
                (string) ($keys['zender_api_key'] ?? '')
            );
            $configuredUrl = trim(
                (string) ($keys['zender_api_url'] ?? '')
            );

            if ('' === $secret) {
                throw new RuntimeException(
                    'zender_api_secret_missing'
                );
            }

            $origin = $this->apiOrigin($configuredUrl);

            $response = $this->client->request(
                'GET',
                $origin.self::RECEIVED_PATH,
                [
                    'query' => [
                        'secret' => $secret,
                        'limit' => $limit,
                        'page' => $page,
                    ],
                    'timeout' => 30,
                    'connect_timeout' => 10,
                    'allow_redirects' => false,
                    'verify' => true,
                    'http_errors' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => (
                            'MauticZenderBundle-ReceivedImporter/4B1C'
                        ),
                    ],
                ]
            );

            $httpStatus = (int) $response->getStatusCode();
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            if (
                200 !== $httpStatus
                || !is_array($decoded)
                || 200 !== (int) ($decoded['status'] ?? 0)
            ) {
                throw new RuntimeException(
                    'zender_received_api_contract_failed'
                );
            }

            $data = $decoded['data'] ?? [];

            if (false === $data || null === $data) {
                $data = [];
            }

            if (!is_array($data)) {
                throw new RuntimeException(
                    'zender_received_data_not_array'
                );
            }

            $rows = [];

            foreach ($data as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            return [
                'rows' => $rows,
                'provider_read_requests' => 1,
                'http_status' => $httpStatus,
                'provider_status' => 200,
                'body_sha256' => hash('sha256', $body),
            ];
        } catch (Throwable $exception) {
            $this->logger->error(
                'Zender received-chat API read failed.',
                [
                    'error_class' => $exception::class,
                    'error_fingerprint' => hash(
                        'sha256',
                        $exception->getMessage()
                    ),
                    'provider_mutation' => false,
                    'message_submission' => false,
                ]
            );

            throw $exception;
        }
    }

    private function apiOrigin(string $configuredUrl): string
    {
        $parts = parse_url(trim($configuredUrl));

        if (
            !is_array($parts)
            || empty($parts['host'])
        ) {
            throw new RuntimeException(
                'zender_api_origin_invalid'
            );
        }

        $scheme = strtolower(
            (string) ($parts['scheme'] ?? 'https')
        );

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(
                'zender_api_scheme_invalid'
            );
        }

        $origin = $scheme.'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.(int) $parts['port'];
        }

        return rtrim($origin, '/');
    }
}
