<?php

namespace MauticPlugin\MauticZenderBundle\Transport;

use Doctrine\ORM\EntityManager;
use GuzzleHttp\Client;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PageBundle\Entity\Redirect;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Api\AbstractSmsApi;
use Mautic\SmsBundle\Entity\Stat;
use MauticPlugin\MauticZenderBundle\Service\DispatchAccountRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchConfigRepository;
use MauticPlugin\MauticZenderBundle\Service\DispatchQueueRepository;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppProviderEnvelopeBuilder;
use Psr\Log\LoggerInterface;

class ZenderTransport extends AbstractSmsApi
{
    private const ZENDER_TYPE = 'text';

    private $shortenerUrl;
    private $zenderApiUrl;
    protected $logger;
    protected $integrationHelper;
    protected $client;
    private $zender_api_key;
    private $sender_id;
    protected $connected;
    private $entityManager;
    private array $lastProviderDiagnostic = [];
    private DispatchAccountRepository $dispatchAccountRepository;
    private DispatchConfigRepository $dispatchConfigRepository;
    private DispatchQueueRepository $dispatchQueueRepository;
    private ?WhatsAppProviderEnvelopeBuilder $providerEnvelopeBuilder;

    public function __construct(
        IntegrationHelper $integrationHelper,
        LoggerInterface $logger,
        Client $client,
        EntityManager $entityManager,
        DispatchAccountRepository $dispatchAccountRepository,
        DispatchConfigRepository $dispatchConfigRepository,
        DispatchQueueRepository $dispatchQueueRepository,
        ?WhatsAppProviderEnvelopeBuilder $providerEnvelopeBuilder = null
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->logger            = $logger;
        $this->client            = $client;
        $this->entityManager     = $entityManager;
        $this->dispatchAccountRepository = $dispatchAccountRepository;
        $this->dispatchConfigRepository = $dispatchConfigRepository;
        $this->dispatchQueueRepository  = $dispatchQueueRepository;
        $this->providerEnvelopeBuilder = $providerEnvelopeBuilder;
        $this->connected         = false;

        $integration = $this->integrationHelper->getIntegrationObject('Zender');

        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $keys                 = $integration->getDecryptedApiKeys();
            $this->zenderApiUrl   = $keys['zender_api_url']   ?? '';
            $this->shortenerUrl   = $keys['shortener_url']    ?? '';
            $this->zender_api_key = $keys['zender_api_key']   ?? '';
        }
    }

    protected function findMauticUrls($message)
    {
        $pattern = '#https?://[a-zA-Z0-9.-]+/r/[a-zA-Z0-9]+?\?ct=[a-zA-Z0-9=+:;,_\-]+(?:%3D)?([^a-zA-Z0-9]|$)#';
        preg_match_all($pattern, $message, $matches, PREG_SET_ORDER);

        foreach ($matches as &$match) {
            $match[1] = rtrim($match[1], '%3D');
        }

        return $matches;
    }

    protected function CheckIfMessageHaveMediaLinks($content)
    {
        $urls = $this->findMauticUrls($content);

        foreach ($urls as $url) {
            if (isset($url[0])) {
                $fullUrl = $url[0];
                $startPos = strrpos($fullUrl, '/r/') + 3;
                $endPos   = strpos($fullUrl, '?');

                if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
                    $length     = $endPos - $startPos;
                    $redirectId = substr($fullUrl, $startPos, $length);

                    $repository = $this->entityManager->getRepository(Redirect::class);
                    $redirect   = $repository->findOneBy(['redirectId' => $redirectId]);

                    if ($redirect) {
                        $originalUrl = $redirect->getUrl();

                        if (preg_match('/\.(jpg|png|gif|mp4)$/', $originalUrl)) {
                            $content = str_replace($fullUrl, $originalUrl, $content);
                        }
                    }
                }
            }
        }

        return $content;
    }

    public function sendSms(Lead $contact, $content, Stat $stat = null)
    {
        // 1) Reemplaza URLs de /r/ por su destino real si son media
        $content = $this->CheckIfMessageHaveMediaLinks($content);
    
        // 2) Número
        $rawNumber = $contact->getLeadPhoneNumber();
        if (empty($rawNumber)) {
            // $this->logger->warning('[ZENDER] Contacto sin teléfono', ['contactId' => $contact->getId()]);
            return false;
        }
    
        // 3) ID de cuenta en Zender
        $accountIdInZender = $contact->getFieldValue('id_whatsapp_in_zender');
        if (empty($accountIdInZender)) {
            // $this->logger->warning('[ZENDER] Contacto sin id_whatsapp_in_zender', ['contactId' => $contact->getId()]);
            return false;
        }
    
        // 4) Normaliza a E.164 (no fijar región por defecto)
        try {
            $e164 = $this->sanitizeNumber($rawNumber); // => +51956031565
        } catch (NumberParseException $e) {
            // $this->logger->error('[ZENDER] Número inválido', [
            //     'contactId' => $contact->getId(),
            //     'raw'       => $rawNumber,
            //     'error'     => $e->getMessage(),
            // ]);
            return false;
        }
    
        // 5) Credenciales
        if (!$this->connected && !$this->configureConnection()) {
            // $this->logger->error('[ZENDER] Integración no configurada correctamente');
            return false;
        }
        if (empty($this->zenderApiUrl)) {
            // $this->logger->error('[ZENDER] zender_api_url vacío en las credenciales');
            return false;
        }
    
        // 6) Personaliza contenido
        $content = $this->sanitizeContent($content, $contact);
        if (empty($content)) {
            // $this->logger->warning('[ZENDER] Contenido vacío tras sanitizar', ['contactId' => $contact->getId()]);
            return false;
        }
    
        // 7) Controlled queue branch. Disabled by default.
        if ($this->dispatchConfigRepository->isEnabled()) {
            $statId = $stat && method_exists($stat, 'getId') ? $stat->getId() : null;
            $trackingHash = $stat && method_exists($stat, 'getTrackingHash')
                ? $stat->getTrackingHash()
                : null;
            $source = $stat && method_exists($stat, 'getSource') ? $stat->getSource() : null;
            $sourceId = $stat && method_exists($stat, 'getSourceId') ? $stat->getSourceId() : null;
            $sms = $stat && method_exists($stat, 'getSms') ? $stat->getSms() : null;
            $smsId = $sms && method_exists($sms, 'getId') ? $sms->getId() : null;

            if ($statId) {
                $dedupeSeed = 'stat_id:'.$statId;
            } elseif ($trackingHash) {
                $dedupeSeed = 'tracking:'.$trackingHash;
            } else {
                $dedupeSeed = 'untracked:'.bin2hex(random_bytes(16));
            }

            $queueStatus = $this->dispatchAccountRepository->isEnabledAccount(
                (string) $accountIdInZender
            ) ? 'pending' : 'blocked_account';

            $queued = $this->dispatchQueueRepository->enqueue([
                'dedupe_key'         => hash('sha256', $dedupeSeed),
                'contact_id'         => (int) $contact->getId(),
                'sms_id'             => $smsId,
                'stat_tracking_hash' => $trackingHash,
                'source'             => $source,
                'source_id'          => $sourceId,
                'recipient'          => $e164,
                'account_id'         => (string) $accountIdInZender,
                'content'            => (string) $content,
                'status'             => $queueStatus,
                'priority'           => 2,
            ]);

            if ($queued) {
                $this->logger->info('[ZENDER] Message accepted into controlled queue', [
                    'contact_id'   => $contact->getId(),
                    'sms_id'       => $smsId,
                    'queue_status' => $queueStatus,
                    'account_hash' => hash('sha256', (string) $accountIdInZender),
                ]);

                return true;
            }

            $this->logger->error('[ZENDER] Controlled queue rejected message', [
                'contact_id' => $contact->getId(),
                'sms_id'     => $smsId,
            ]);

            return false;
        }

        // Direct path preserved while controlled dispatch is disabled.
        return $this->send($e164, $content, $accountIdInZender, [
            'contactId' => $contact->getId(),
            'zenderUrl' => $this->zenderApiUrl,
        ]);
    }

    public function dispatchQueuedMessage(
        string $e164Number,
        string $content,
        string $accountIdInZender,
        array $context = []
    ) {
        // ATTEMPT_RECORDING_3B2_DIAGNOSTIC_RESET_BEGIN
        $this->lastProviderDiagnostic = [];

        if (!$this->connected && !$this->configureConnection()) {
            $this->lastProviderDiagnostic = [
                'classification' => 'integration_not_configured',
                'success' => false,
                'provider_request_started' => false,
                'provider_message_id' => null,
            ];

            return false;
        }

        if (empty($this->zenderApiUrl)) {
            $this->lastProviderDiagnostic = [
                'classification' => 'provider_url_missing',
                'success' => false,
                'provider_request_started' => false,
                'provider_message_id' => null,
            ];

            return false;
        }

        // ATTEMPT_RECORDING_3B2_DIAGNOSTIC_RESET_END

        return $this->send(
            $e164Number,
            $content,
            $accountIdInZender,
            array_merge(
                $context,
                [
                    'zenderUrl' => $this->zenderApiUrl,
                    'enforceAccountCooldown' => true,
                ]
            )
        );
    }

    protected function shortenUrl($longUrl)
    {
        if (empty($this->shortenerUrl)) {
            return $longUrl;
        }

        $apiUrl = $this->shortenerUrl . '&action=shorturl&url=' . urlencode($longUrl) . '&format=simple';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);

        if (is_string($response) && filter_var($response, FILTER_VALIDATE_URL)) {
            return $response;
        }

        return $longUrl;
    }

    protected function prepareMediaPayload($content, array &$payload)
    {
        $mediaPattern = '#\bhttps?://[^\s()<>]+(?:\.(jpg|jpeg|gif|png|mp4))#';
        if (preg_match($mediaPattern, $content, $media)) {
            $payload['type']      = 'media';
            $payload['media_url'] = $media[0];

            switch (strtolower($media[1])) {
                case 'jpg':
                case 'jpeg':
                    $payload['media_file'] = 'jpg';
                    $payload['media_type'] = 'image';
                    break;
                case 'gif':
                    $payload['media_file'] = 'gif';
                    $payload['media_type'] = 'image';
                    break;
                case 'png':
                    $payload['media_file'] = 'png';
                    $payload['media_type'] = 'image';
                    break;
                case 'mp4':
                    $payload['media_file'] = 'mp4';
                    $payload['media_type'] = 'video';
                    break;
            }
        }
    }

    protected function send($e164Number, $content, $accountIdInZender, array $ctx = [])
    {
        $content = preg_replace(
            '/(%3D)(?=[^a-zA-Z0-9]|$)/',
            '',
            (string) $content
        ) ?? (string) $content;

        $whatsappMessageId = null;
        if (
            isset($ctx['whatsappMessageId'])
            && null !== $ctx['whatsappMessageId']
            && (int) $ctx['whatsappMessageId'] > 0
        ) {
            $whatsappMessageId = (int) $ctx['whatsappMessageId'];
        }

        $assetId = null;
        if (
            isset($ctx['assetId'])
            && null !== $ctx['assetId']
            && (int) $ctx['assetId'] > 0
        ) {
            $assetId = (int) $ctx['assetId'];
        }

        $envelope = null;

        if (
            null !== $whatsappMessageId
            || null !== $assetId
        ) {
            if (!$this->providerEnvelopeBuilder instanceof WhatsAppProviderEnvelopeBuilder) {
                $this->lastProviderDiagnostic = [
                    'classification' => 'transport_exception',
                    'success' => false,
                    'provider_request_started' => false,
                    'provider_message_id' => null,
                    'exception_class' => \RuntimeException::class,
                    'exception' => 'provider_envelope_builder_unavailable',
                ];

                return false;
            }

            try {
                $envelope = $this->providerEnvelopeBuilder->build(
                    $whatsappMessageId,
                    $content,
                    $assetId
                );
            } catch (\Throwable $e) {
                $this->lastProviderDiagnostic = [
                    'classification' => 'transport_exception',
                    'success' => false,
                    'provider_request_started' => false,
                    'provider_message_id' => null,
                    'exception_class' => get_class($e),
                    'exception' => mb_substr($e->getMessage(), 0, 500),
                ];

                $this->logger->error(
                    '[ZENDER] Provider envelope resolution failed',
                    $this->lastProviderDiagnostic
                );

                return false;
            }
        }

        $payload = [
            'secret' => $this->zender_api_key,
            'account' => $accountIdInZender,
            'recipient' => $e164Number,
            'type' => self::ZENDER_TYPE,
            'message' => $content,
        ];

        $source = trim((string) ($ctx['source'] ?? ''));
        if (in_array(
            $source,
            [
                'whatsapp.demo',
                'whatsapp.contact.profile',
            ],
            true
        )) {
            $payload['priority'] = 1;
        }

        if (null === $envelope) {
            $content = $this->CheckIfMessageHaveMediaLinks($content);
            $this->prepareMediaPayload($content, $payload);
        }

        $urlPattern = '#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#';
        if (preg_match_all($urlPattern, $content, $urls)) {
            foreach ($urls[0] as $url) {
                $short = $this->shortenUrl($url);
                $content = str_replace($url, $short, $content);
            }
        }

        $payload['message'] = $content;

        if (null !== $envelope) {
            $envelope['message'] = $content;
            $payload['type'] = (string) $envelope['type'];

            if ('media' === $envelope['type']) {
                $payload['media_type'] = (string) $envelope['media_type'];
            } elseif ('document' === $envelope['type']) {
                $payload['document_name'] = (string) $envelope['document_name'];
                $payload['document_type'] = (string) $envelope['document_type'];
            }
        }

        $cooldown = null;
        if (true === ($ctx['enforceAccountCooldown'] ?? false)) {
            $config = $this->dispatchConfigRepository->get();
            $cooldown = $this->dispatchAccountRepository
                ->beginProviderAttemptCooldown(
                    (string) $accountIdInZender,
                    (int) ($config['dispatch_interval_seconds'] ?? 165)
                );

            if (null === $cooldown) {
                $this->lastProviderDiagnostic = [
                    'classification' => 'cooldown_not_eligible',
                    'success' => false,
                    'provider_request_started' => false,
                    'provider_message_id' => null,
                ];

                $this->logger->info(
                    '[ZENDER] Provider request skipped by account cooldown',
                    [
                        'classification' => 'cooldown_not_eligible',
                        'provider_request_started' => false,
                        'account_hash' => hash('sha256', (string) $accountIdInZender),
                    ]
                );

                return false;
            }
        }

        $openedStream = null;

        try {
            $request = $this->buildMultipartRequest($payload, $envelope);
            $openedStream = $request['stream'];

            $response = $this->client->request(
                'POST',
                rtrim($this->zenderApiUrl, '/'),
                [
                    'multipart' => $request['multipart'],
                    'timeout' => 20,
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]
            );

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $contentType = $response->getHeaderLine('Content-Type');

            $this->lastProviderDiagnostic = $this->buildProviderDiagnostic(
                $status,
                $body,
                $contentType,
                (string) $accountIdInZender,
                (string) $e164Number
            );
            $this->lastProviderDiagnostic['send_source'] = $source;
            $this->lastProviderDiagnostic['provider_priority'] = (
                array_key_exists('priority', $payload)
                    ? (int) $payload['priority']
                    : null
            );
            $this->lastProviderDiagnostic['provider_request_started'] = true;
            $this->lastProviderDiagnostic['cooldown_started_at'] = (
                $cooldown['last_attempt_started_at'] ?? null
            );
            $this->lastProviderDiagnostic['next_eligible_at'] = (
                $cooldown['next_eligible_at'] ?? null
            );

            $this->logger->info(
                '[ZENDER] Provider response diagnostic',
                $this->lastProviderDiagnostic
            );

            return true === ($this->lastProviderDiagnostic['success'] ?? false);
        } catch (\Throwable $e) {
            $this->lastProviderDiagnostic = [
                'classification' => 'transport_exception',
                'success' => false,
                'provider_request_started' => true,
                'provider_message_id' => null,
                'cooldown_started_at' => (
                    $cooldown['last_attempt_started_at'] ?? null
                ),
                'next_eligible_at' => (
                    $cooldown['next_eligible_at'] ?? null
                ),
                'exception_class' => get_class($e),
                'exception' => mb_substr($e->getMessage(), 0, 500),
            ];

            $this->logger->error(
                '[ZENDER] Provider transport exception',
                $this->lastProviderDiagnostic
            );

            return false;
        } finally {
            if (is_resource($openedStream)) {
                fclose($openedStream);
            }
        }
    }

    /**
     * @return array{multipart:array<int, array<string, mixed>>, stream:mixed}
     */
    private function buildMultipartRequest(
        array $payload,
        ?array $envelope
    ): array {
        $multipart = [];

        foreach ($payload as $name => $value) {
            if (null === $value || '' === (string) $value) {
                continue;
            }

            if (
                null !== $envelope
                && in_array($name, ['media_file', 'document_file'], true)
            ) {
                continue;
            }

            if (
                null === $envelope
                && 'media_file' === $name
                && isset($payload['media_url'])
            ) {
                continue;
            }

            $multipart[] = [
                'name' => (string) $name,
                'contents' => (string) $value,
            ];
        }

        if (
            null === $envelope
            || !in_array($envelope['type'] ?? null, ['media', 'document'], true)
        ) {
            return [
                'multipart' => $multipart,
                'stream' => null,
            ];
        }

        $filePath = (string) ($envelope['file_path'] ?? '');
        $stream = @fopen($filePath, 'rb');

        if (!is_resource($stream)) {
            throw new \RuntimeException(
                'provider_envelope_file_stream_open_failed'
            );
        }

        $fieldName = 'media' === $envelope['type']
            ? 'media_file'
            : 'document_file';

        $filePart = [
            'name' => $fieldName,
            'contents' => $stream,
            'filename' => (string) (
                $envelope['file_name'] ?? basename($filePath)
            ),
        ];

        $mime = trim((string) ($envelope['mime'] ?? ''));
        if ('' !== $mime) {
            $filePart['headers'] = [
                'Content-Type' => $mime,
            ];
        }

        $multipart[] = $filePart;

        return [
            'multipart' => $multipart,
            'stream' => $stream,
        ];
    }

    public function getLastProviderDiagnostic(): array
    {
        return $this->lastProviderDiagnostic;
    }

    public function diagnoseProviderResponseForTest(
        int $status,
        string $body,
        string $contentType = 'application/json'
    ): array {
        return $this->buildProviderDiagnostic(
            $status,
            $body,
            $contentType,
            'synthetic-account',
            '+000000000000'
        );
    }

    private function buildProviderDiagnostic(
        int $status,
        string $body,
        string $contentType,
        string $accountId,
        string $recipient
    ): array {
        $decoded = json_decode($body, true);
        $jsonValid = is_array($decoded);
        $statusValue = $jsonValid && array_key_exists('status', $decoded)
            ? $decoded['status']
            : null;
        $messageValue = $jsonValid
            && array_key_exists('message', $decoded)
            && is_scalar($decoded['message'])
                ? trim((string) $decoded['message'])
                : '';
        $normalizedMessage = strtolower($messageValue);
        $normalizedMessage = preg_replace(
            '/\s+/',
            ' ',
            $normalizedMessage
        ) ?? $normalizedMessage;
        $normalizedMessage = rtrim(
            trim($normalizedMessage),
            " \t\n\r\0\x0B!."
        );
        $accountDisconnected = $jsonValid
            && in_array($statusValue, [500, '500'], true)
            && false === ($decoded['data'] ?? null)
            && 'whatsapp account is disconnected' === $normalizedMessage;

        $providerMessageId = null;
        if (
            $jsonValid
            && isset($decoded['data'])
            && is_array($decoded['data'])
            && array_key_exists('messageId', $decoded['data'])
            && is_scalar($decoded['data']['messageId'])
        ) {
            $candidate = trim((string) $decoded['data']['messageId']);
            if ('' !== $candidate) {
                $providerMessageId = mb_substr($candidate, 0, 191);
            }
        }

        $success = $status >= 200
            && $status < 300
            && (200 === $statusValue || 'success' === $statusValue);

        if ($status < 200 || $status >= 300) {
            $classification = 'http_non_2xx';
        } elseif (!$jsonValid) {
            $classification = 'http_2xx_invalid_json';
        } elseif ($accountDisconnected) {
            $classification = 'account_disconnected';
        } elseif (200 === $statusValue) {
            $classification = 'legacy_status_200';
        } elseif ('success' === $statusValue) {
            $classification = 'legacy_status_success';
        } else {
            $classification = 'http_2xx_unrecognized_json';
        }

        $jsonKeys = [];
        if ($jsonValid) {
            $jsonKeys = array_slice(array_map('strval', array_keys($decoded)), 0, 30);
            sort($jsonKeys);
        }

        return [
            'classification'    => $classification,
            'success'           => $success,
            'http_status'       => $status,
            'content_type'      => mb_substr($contentType, 0, 200),
            'body_bytes'        => strlen($body),
            'body_sha256'       => hash('sha256', $body),
            'body_preview'      => $this->sanitizeProviderBody($body, $accountId, $recipient),
            'json_valid'        => $jsonValid,
            'json_keys'         => $jsonKeys,
            'json_status_type'  => null === $statusValue ? 'missing' : gettype($statusValue),
            'json_status_value'   => is_scalar($statusValue)
                ? mb_substr((string) $statusValue, 0, 200)
                : (null === $statusValue ? 'missing' : '[non-scalar]'),
            'json_message_value'  => '' !== $messageValue
                ? mb_substr($messageValue, 0, 300)
                : 'missing',
            'provider_message_id' => $providerMessageId,
        ];
    }

    private function sanitizeProviderBody(string $body, string $accountId, string $recipient): string
    {
        $safe = str_replace(
            array_filter([(string) $this->zender_api_key, $accountId, $recipient]),
            '[REDACTED]',
            $body
        );

        $safe = preg_replace(
            '/([A-Za-z0-9._%+\\-])[A-Za-z0-9._%+\\-]*(@[A-Za-z0-9.\\-]+)/',
            '$1***$2',
            $safe
        ) ?? $safe;

        $safe = preg_replace_callback(
            '/(?<![A-Za-z0-9])\\+?\\d[\\d .()\\-]{7,}\\d/',
            static function (array $matches): string {
                $digits = preg_replace('/\\D+/', '', $matches[0]) ?? '';
                return strlen($digits) >= 8 ? '***'.substr($digits, -4) : $matches[0];
            },
            $safe
        ) ?? $safe;

        return mb_substr($safe, 0, 1000);
    }

    // Enmascara secretos al loguear
    private function mask(string $s): string
    {
        if ($s === '') {
            return '';
        }
        return substr($s, 0, 3) . str_repeat('*', max(0, strlen($s) - 6)) . substr($s, -3);
    }

    protected function configureConnection()
    {
        $integration = $this->integrationHelper->getIntegrationObject('Zender');
        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $keys = $integration->getDecryptedApiKeys();

            if (!empty($keys['zender_api_key'])) {
                $this->zender_api_key = $keys['zender_api_key'];
            }
            if (!empty($keys['zender_api_url'])) {
                $this->zenderApiUrl = $keys['zender_api_url'];
            }
            if (!empty($keys['shortener_url'])) {
                $this->shortenerUrl = $keys['shortener_url'];
            }

            $this->connected = !empty($this->zender_api_key);
        }

        return $this->connected;
    }

    protected function sanitizeContent(string $content, Lead $contact)
    {
        return strtr($content, [
            '{contact_title}'                   => $contact->getTitle(),
            '{contact_firstname}'               => $contact->getFirstname(),
            '{contact_lastname}'                => $contact->getLastname(),
            '{contact_name}'                    => $contact->getName(),
            '{contact_company}'                 => $contact->getCompany(),
            '{contact_email}'                   => $contact->getEmail(),
            '{contact_address1}'                => $contact->getAddress1(),
            '{contact_address2}'                => $contact->getAddress2(),
            '{contact_city}'                    => $contact->getCity(),
            '{contact_state}'                   => $contact->getState(),
            '{contact_country}'                 => $contact->getCountry(),
            '{contact_zipcode}'                 => $contact->getZipcode(),
            '{contact_location}'                => $contact->getLocation(),
            '{contact_phone}'                   => ltrim($contact->getLeadPhoneNumber(), '+'),
            '{contact_id_whatsapp_in_zender}'   => $contact->getFieldValue('id_whatsapp_in_zender'),
        ]);
    }

    // Normaliza a E.164 sin fijar región por defecto
    protected function sanitizeNumber($number)
    {
        $util = PhoneNumberUtil::getInstance();

        if (is_string($number) && strlen($number) > 0 && $number[0] === '+') {
            $parsed = $util->parse($number, null);
        } else {
            $parsed = $util->parse($number, null);
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }
}
