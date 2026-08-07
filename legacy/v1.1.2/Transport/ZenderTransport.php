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

    public function __construct(
        IntegrationHelper $integrationHelper,
        LoggerInterface $logger,
        Client $client,
        EntityManager $entityManager
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->logger            = $logger;
        $this->client            = $client;
        $this->entityManager     = $entityManager;
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

    public function sendSms(Lead $contact, $content)
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
    
        // 7) Envía
        return $this->send($e164, $content, $accountIdInZender, [
            'contactId' => $contact->getId(),
            'zenderUrl' => $this->zenderApiUrl,
        ]);
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
        // Limpia %3D colgantes (textos con tokens de Mautic)
        $content = preg_replace('/(%3D)(?=[^a-zA-Z0-9]|$)/', '', $content);
    
        // Payload base
        $payload = [
            'secret'    => $this->zender_api_key,
            'account'   => $accountIdInZender,
            'recipient' => $e164Number, // Zender acepta E.164 con '+'
            'type'      => self::ZENDER_TYPE,
            'message'   => $content,
        ];
    
        // Si hay media, ajusta payload
        $content = $this->CheckIfMessageHaveMediaLinks($content);
        $this->prepareMediaPayload($content, $payload);
    
        // Shortener opcional
        $urlPattern = '#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#';
        if (preg_match_all($urlPattern, $content, $urls)) {
            foreach ($urls[0] as $url) {
                $short = $this->shortenUrl($url);
                $content = str_replace($url, $short, $content);
            }
        }
        $payload['message'] = $content;
    
        // Log de salida (enmascara secret)
        /*
        $this->logger->info('[ZENDER] Preparando POST', [
            'to'      => $e164Number,
            'account' => $accountIdInZender,
            'type'    => $payload['type'],
            'url'     => $ctx['zenderUrl'] ?? $this->zenderApiUrl,
            'payload' => array_merge($payload, ['secret' => $this->mask($payload['secret'])]),
        ]);
        */
    
        try {
            // Igual que tu curl: application/x-www-form-urlencoded
            $response = $this->client->request('POST', rtrim($this->zenderApiUrl, '/'), [
                'form_params' => $payload,
                'timeout'     => 20,
            ]);
    
            $status = $response->getStatusCode();
            $body   = (string) $response->getBody();
    
            /*
            $this->logger->info('[ZENDER] Respuesta', [
                'status' => $status,
                'body'   => $body,
            ]);
            */
    
            if ($status < 200 || $status >= 300) {
                // $this->logger->error('[ZENDER] HTTP no-2xx', ['status' => $status, 'body' => $body]);
                return false;
            }
    
            $data = json_decode($body, true);
            if (!is_array($data)) {
                // $this->logger->error('[ZENDER] JSON inválido', ['body' => $body]);
                return false;
            }
    
            // Éxito si status === 200 o 'success'
            if ((isset($data['status']) && $data['status'] === 200)
                || (isset($data['status']) && $data['status'] === 'success')) {
                return true;
            }
    
            // $this->logger->error('[ZENDER] API reportó error', ['data' => $data]);
            return false;
        } catch (\Throwable $e) {
            // $this->logger->error('[ZENDER] Excepción al llamar API', [
            //     'error' => $e->getMessage(),
            // ]);
            return false;
        }
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
