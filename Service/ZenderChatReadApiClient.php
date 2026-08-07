<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use GuzzleHttp\Client;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;
use Throwable;

final class ZenderChatReadApiClient
{
    private const INTEGRATION_ALIAS = 'Zender';
    private const SENT_PATH = '/api/get/wa.sent';
    private const PENDING_PATH = '/api/get/wa.pending';

    public function __construct(
        private readonly IntegrationHelper $integrationHelper,
        private readonly Client $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     checked_at_utc: string,
     *     sent: array<string, array<string, int|string>>,
     *     pending: array<string, array<string, int|string>>,
     *     provider_read_requests: int,
     *     error_code: string|null
     * }
     */
    public function getStatusSnapshot(
        int $pageSize = 100,
        int $maxPages = 20
    ): array {
        $checkedAt = gmdate('Y-m-d H:i:s').' UTC';
        $pageSize = max(1, min(100, $pageSize));
        $maxPages = max(1, min(100, $maxPages));
        $providerReadRequests = 0;

        try {
            $credentials = $this->credentials();

            if (null === $credentials) {
                return $this->failure(
                    $checkedAt,
                    'credentials_unavailable',
                    $providerReadRequests
                );
            }

            $sent = $this->readPaged(
                $credentials['origin'],
                $credentials['secret'],
                self::SENT_PATH,
                $pageSize,
                $maxPages,
                $providerReadRequests
            );
            if (!$sent['available']) {
                return $this->failure(
                    $checkedAt,
                    'sent_endpoint_unavailable',
                    $providerReadRequests
                );
            }

            $pending = $this->readPaged(
                $credentials['origin'],
                $credentials['secret'],
                self::PENDING_PATH,
                $pageSize,
                $maxPages,
                $providerReadRequests
            );
            if (!$pending['available']) {
                return $this->failure(
                    $checkedAt,
                    'pending_endpoint_unavailable',
                    $providerReadRequests
                );
            }

            return [
                'available' => true,
                'checked_at_utc' => $checkedAt,
                'sent' => $sent['items'],
                'pending' => $pending['items'],
                'provider_read_requests' => $providerReadRequests,
                'error_code' => null,
            ];
        } catch (Throwable $throwable) {
            $this->logger->warning(
                '[ZENDER] Provider status read snapshot failed',
                [
                    'exception_class' => get_class($throwable),
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

    /**
     * @param int $providerReadRequests
     *
     * @return array{
     *     available: bool,
     *     items: array<string, array<string, int|string>>
     * }
     */
    private function readPaged(
        string $origin,
        string $secret,
        string $path,
        int $pageSize,
        int $maxPages,
        int &$providerReadRequests
    ): array {
        $items = [];

        for ($page = 1; $page <= $maxPages; ++$page) {
            ++$providerReadRequests;

            $response = $this->client->request(
                'GET',
                $origin.$path,
                [
                    'query' => [
                        'secret' => $secret,
                        'limit' => $pageSize,
                        'page' => $page,
                    ],
                    'timeout' => 20,
                    'connect_timeout' => 8,
                    'allow_redirects' => false,
                    'verify' => true,
                    'http_errors' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => (
                            'MauticZenderBundle-ProviderStatusSync/4A2'
                        ),
                    ],
                ]
            );

            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            if (
                200 !== $response->getStatusCode()
                || !is_array($decoded)
                || 200 !== (int) ($decoded['status'] ?? 0)
                || !is_array($decoded['data'] ?? null)
            ) {
                return [
                    'available' => false,
                    'items' => [],
                ];
            }

            $pageRows = $decoded['data'];

            foreach ($pageRows as $row) {
                if (!is_array($row) || !is_numeric($row['id'] ?? null)) {
                    continue;
                }

                $id = (string) max(0, (int) $row['id']);
                if ('0' === $id) {
                    continue;
                }

                $status = strtolower(trim((string) ($row['status'] ?? '')));
                $created = is_numeric($row['created'] ?? null)
                    ? max(0, (int) $row['created'])
                    : 0;

                $canonical = [
                    'id' => $id,
                    'status' => mb_substr($status, 0, 80),
                    'created' => $created,
                    'account_fingerprint' => substr(
                        hash('sha256', (string) ($row['account'] ?? '')),
                        0,
                        16
                    ),
                ];

                $items[$id] = [
                    ...$canonical,
                    'item_sha256' => hash(
                        'sha256',
                        json_encode(
                            $canonical,
                            JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                            | JSON_THROW_ON_ERROR
                        )
                    ),
                ];
            }

            if (count($pageRows) < $pageSize) {
                break;
            }
        }

        return [
            'available' => true,
            'items' => $items,
        ];
    }

    /**
     * @return array{origin: string, secret: string}|null
     */
    private function credentials(): ?array
    {
        $integration = $this->integrationHelper
            ->getIntegrationObject(self::INTEGRATION_ALIAS);

        if (!is_object($integration)) {
            return null;
        }

        $settings = $integration->getIntegrationSettings();

        if (
            !is_object($settings)
            || !method_exists($settings, 'getIsPublished')
            || !$settings->getIsPublished()
        ) {
            return null;
        }

        $keys = $integration->getDecryptedApiKeys();

        if (!is_array($keys)) {
            return null;
        }

        $secret = trim((string) ($keys['zender_api_key'] ?? ''));
        $configuredSendUrl = trim(
            (string) ($keys['zender_api_url'] ?? '')
        );
        $origin = $this->apiOrigin($configuredSendUrl);

        if ('' === $secret || null === $origin) {
            return null;
        }

        return [
            'origin' => $origin,
            'secret' => $secret,
        ];
    }

    private function apiOrigin(string $configuredSendUrl): ?string
    {
        $parts = parse_url($configuredSendUrl);

        if (
            !is_array($parts)
            || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))
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

    /**
     * @return array{
     *     available: false,
     *     checked_at_utc: string,
     *     sent: array{},
     *     pending: array{},
     *     provider_read_requests: int,
     *     error_code: string
     * }
     */
    private function failure(
        string $checkedAt,
        string $errorCode,
        int $providerReadRequests
    ): array {
        return [
            'available' => false,
            'checked_at_utc' => $checkedAt,
            'sent' => [],
            'pending' => [],
            'provider_read_requests' => $providerReadRequests,
            'error_code' => $errorCode,
        ];
    }
}
