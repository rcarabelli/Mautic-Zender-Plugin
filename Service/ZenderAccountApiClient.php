<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use GuzzleHttp\Client;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;
use Throwable;

final class ZenderAccountApiClient
{
    private const INTEGRATION_ALIAS = 'Zender';
    private const ACCOUNTS_PATH = '/api/get/wa.accounts';

    private ?array $snapshot = null;

    public function __construct(
        private readonly IntegrationHelper $integrationHelper,
        private readonly Client $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getAccountSnapshot(): array
    {
        if (null === $this->snapshot) {
            $this->snapshot = $this->requestAccountSnapshot();
        }

        return $this->snapshot;
    }

    private function requestAccountSnapshot(): array
    {
        $checkedAt = gmdate('Y-m-d H:i:s').' UTC';
        $providerReadRequests = 0;

        try {
            $integration = $this->integrationHelper
                ->getIntegrationObject(self::INTEGRATION_ALIAS);

            if (!is_object($integration)) {
                return $this->failure(
                    $checkedAt,
                    'integration_not_found',
                    $providerReadRequests
                );
            }

            $settings = $integration->getIntegrationSettings();

            if (
                !is_object($settings)
                || !method_exists($settings, 'getIsPublished')
                || !$settings->getIsPublished()
            ) {
                return $this->failure(
                    $checkedAt,
                    'integration_not_published',
                    $providerReadRequests
                );
            }

            $keys = $integration->getDecryptedApiKeys();

            if (!is_array($keys)) {
                return $this->failure(
                    $checkedAt,
                    'integration_keys_unavailable',
                    $providerReadRequests
                );
            }

            $secret = trim((string) ($keys['zender_api_key'] ?? ''));
            $configuredSendUrl = trim(
                (string) ($keys['zender_api_url'] ?? '')
            );

            if ('' === $secret) {
                return $this->failure(
                    $checkedAt,
                    'api_secret_missing',
                    $providerReadRequests
                );
            }

            $origin = $this->apiOrigin($configuredSendUrl);

            if (null === $origin) {
                return $this->failure(
                    $checkedAt,
                    'api_origin_invalid',
                    $providerReadRequests
                );
            }

            $providerReadRequests = 1;

            $response = $this->client->request(
                'GET',
                $origin.self::ACCOUNTS_PATH,
                [
                    'query' => [
                        'secret' => $secret,
                        'limit' => 100,
                        'page' => 1,
                    ],
                    'timeout' => 20,
                    'connect_timeout' => 8,
                    'allow_redirects' => false,
                    'verify' => true,
                    'http_errors' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => (
                            'MauticZenderBundle-AccountAdmin/1.8.2B.2'
                        ),
                    ],
                ]
            );

            $httpStatus = $response->getStatusCode();
            $decoded = json_decode(
                (string) $response->getBody(),
                true
            );

            if (
                200 !== $httpStatus
                || !is_array($decoded)
                || 200 !== (int) ($decoded['status'] ?? 0)
                || !is_array($decoded['data'] ?? null)
            ) {
                return $this->failure(
                    $checkedAt,
                    'api_response_invalid',
                    $providerReadRequests,
                    $httpStatus,
                    is_array($decoded)
                        ? (int) ($decoded['status'] ?? 0)
                        : null
                );
            }

            $accounts = [];

            foreach ($decoded['data'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $account = $this->normalizeAccount($row);

                if (null !== $account) {
                    $accounts[] = $account;
                }
            }

            return [
                'available' => true,
                'checked_at_utc' => $checkedAt,
                'endpoint' => self::ACCOUNTS_PATH,
                'api_host' => (string) parse_url(
                    $origin,
                    PHP_URL_HOST
                ),
                'http_status' => $httpStatus,
                'api_status' => (int) $decoded['status'],
                'api_message' => mb_substr(
                    trim((string) ($decoded['message'] ?? '')),
                    0,
                    160
                ),
                'token_fingerprint' => substr(
                    hash('sha256', $secret),
                    0,
                    16
                ),
                'account_count' => count($accounts),
                'accounts' => $accounts,
                'error_code' => null,
                'provider_read_requests' => $providerReadRequests,
                'provider_mutations' => 0,
                'messages_sent' => 0,
            ];
        } catch (Throwable $throwable) {
            $this->logger->warning(
                '[ZENDER] Read-only account inventory failed',
                [
                    'exception_class' => get_class($throwable),
                    'endpoint' => self::ACCOUNTS_PATH,
                    'provider_mutations' => 0,
                    'messages_sent' => 0,
                ]
            );

            return $this->failure(
                $checkedAt,
                'transport_error',
                $providerReadRequests
            );
        }
    }

    private function normalizeAccount(array $row): ?array
    {
        $phone = trim((string) ($row['phone'] ?? ''));
        $unique = trim((string) ($row['unique'] ?? ''));

        if ('' === $phone && '' === $unique) {
            return null;
        }

        $status = strtolower(
            trim((string) ($row['status'] ?? 'unknown'))
        );

        if ('' === $status) {
            $status = 'unknown';
        }

        $created = is_numeric($row['created'] ?? null)
            ? max(0, (int) $row['created'])
            : 0;

        return [
            'id' => max(0, (int) ($row['id'] ?? 0)),
            'phone' => mb_substr($phone, 0, 80),
            'unique' => mb_substr($unique, 0, 191),
            'status' => mb_substr($status, 0, 80),
            'created_at_utc' => $created > 0
                ? gmdate('Y-m-d H:i:s', $created).' UTC'
                : null,
        ];
    }

    private function apiOrigin(string $configuredSendUrl): ?string
    {
        $parts = parse_url($configuredSendUrl);

        if (
            !is_array($parts)
            || 'https' !== strtolower(
                (string) ($parts['scheme'] ?? '')
            )
            || '' === trim((string) ($parts['host'] ?? ''))
        ) {
            return null;
        }

        $origin = 'https://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.(int) $parts['port'];
        }

        return $origin;
    }

    private function failure(
        string $checkedAt,
        string $errorCode,
        int $providerReadRequests,
        int $httpStatus = 0,
        ?int $apiStatus = null,
    ): array {
        return [
            'available' => false,
            'checked_at_utc' => $checkedAt,
            'endpoint' => self::ACCOUNTS_PATH,
            'api_host' => null,
            'http_status' => $httpStatus,
            'api_status' => $apiStatus,
            'api_message' => null,
            'token_fingerprint' => null,
            'account_count' => 0,
            'accounts' => [],
            'error_code' => $errorCode,
            'provider_read_requests' => $providerReadRequests,
            'provider_mutations' => 0,
            'messages_sent' => 0,
        ];
    }
}
