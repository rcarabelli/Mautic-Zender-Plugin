<?php

namespace MauticPlugin\MauticZenderBundle\Transport;

use Doctrine\ORM\EntityManager;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Api\AbstractSmsApi;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use Mautic\PageBundle\Entity\Redirect;

class ZenderTransport extends AbstractSmsApi
{
    private const ZENDER_TYPE = "text";
    
    private $shortenerUrl;
    private $zenderApiBaseUrl;
    protected $logger;
    protected $integrationHelper;
    protected $client;
    private $zenderApiKey;
    private $senderId;
    protected $connected;
    private $entityManager;

    public function __construct(
        IntegrationHelper $integrationHelper, 
        LoggerInterface $logger, 
        Client $client,
        EntityManager $entityManager
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->client = $client;
        $this->entityManager = $entityManager;
        $this->connected = false;

        $integration = $this->integrationHelper->getIntegrationObject('Zender');
    
        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $keys = $integration->getDecryptedApiKeys();

            $this->zenderApiBaseUrl = isset($keys['zender_api_url']) ? rtrim($keys['zender_api_url'], '/') . '/' : '';
            $this->shortenerUrl = isset($keys['shortener_url']) ? $keys['shortener_url'] : '';
        }
    }

    protected function findMauticUrls($message) {
        $pattern = '#https?://[a-zA-Z0-9.-]+/r/[a-zA-Z0-9]+?\?ct=[a-zA-Z0-9=+:;,_\-]+(?:%3D)?([^a-zA-Z0-9]|$)#';
        preg_match_all($pattern, $message, $matches, PREG_SET_ORDER);

        foreach ($matches as &$match) {
            $match[1] = rtrim($match[1], '%3D');
        }
        
        return $matches;
    }

    protected function CheckIfMessageHaveMediaLinks($content) {
        $urls = $this->findMauticUrls($content);

        foreach ($urls as $url) {
            if (isset($url[0])) {
                $fullUrl = $url[0];
                $startPos = strrpos($fullUrl, "/r/") + 3;
                $endPos = strpos($fullUrl, "?");

                if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
                    $length = $endPos - $startPos;
                    $redirectId = substr($fullUrl, $startPos, $length);

                    $repository = $this->entityManager->getRepository(\Mautic\PageBundle\Entity\Redirect::class);
                    $redirect = $repository->findOneBy(['redirectId' => $redirectId]);

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

    public function sendSms(Lead $contact, $content) {
        $content = $this->CheckIfMessageHaveMediaLinks($content);

        $number = $contact->getLeadPhoneNumber();
        if (empty($number)) {
            return false;
        }

        $accountIdInZender = $contact->getFieldValue('id_whatsapp_in_zender');
        if (empty($accountIdInZender)) {
            return false;
        }

        try {
            $number = substr($this->sanitizeNumber($number), 1);
        } catch (NumberParseException $e) {
            return $e->getMessage();
        }

        try {
            if (!$this->connected && !$this->configureConnection()) {
                throw new \Exception("Zender MSG is not configured properly.");
            }

            $content = $this->sanitizeContent($content, $contact);
            if (empty($content)) {
                throw new \Exception('Message content is Empty.');
            }

            $chat = [
                "secret" => $this->zenderApiKey,
                "account" => $accountIdInZender,
                "recipient" => '+' . $number,
                "type" => self::ZENDER_TYPE,
                "message" => $content
            ];

            $response = $this->send($number, $content, $accountIdInZender);

            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    protected function shortenUrl($longUrl) {
        $apiUrl = $this->shortenerUrl . '&action=shorturl&url=' . urlencode($longUrl) . '&format=simple';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);

        if (filter_var($response, FILTER_VALIDATE_URL)) {
            return $response;
        } else {
            return $longUrl;
        }
    }

    protected function prepareMediaPayload($content, &$payload) {
        $mediaPattern = '#\bhttps?://[^\s()<>]+(?:\.(jpg|jpeg|gif|png|mp4))#';
        if (preg_match($mediaPattern, $content, $media)) {
            $payload["type"] = "media";
            $payload["media_url"] = $media[0];

            switch ($media[1]) {
                case 'jpg':
                case 'jpeg':
                    $payload["media_file"] = "jpg";
                    $payload["media_type"] = "image";
                    break;
                case 'gif':
                    $payload["media_file"] = "gif";
                    $payload["media_type"] = "image";
                    break;
                case 'png':
                    $payload["media_file"] = "png";
                    $payload["media_type"] = "image";
                    break;
                case 'mp4':
                    $payload["media_file"] = "mp4";
                    $payload["media_type"] = "video";
                    break;
            }
        }
    }

    protected function send($number, $content, $accountIdInZender) {
        $content = preg_replace('/(%3D)(?=[^a-zA-Z0-9]|$)/', '', $content);

        $payload = [
            "secret" => $this->zenderApiKey,
            "account" => $accountIdInZender,
            "recipient" => '+'.$number,
            "type" => self::ZENDER_TYPE,
            "message" => $content
        ];

        $content = $this->CheckIfMessageHaveMediaLinks($content);
        $this->prepareMediaPayload($content, $payload);

        $urlPattern = '#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#';
        if (preg_match_all($urlPattern, $content, $urls)) {
            foreach ($urls[0] as $url) {
                $shortened = $this->shortenUrl($url);
                $content = str_replace($url, $shortened, $content);
            }
        }

        $payload["message"] = $content;

        $cURL = curl_init($this->zenderApiBaseUrl . 'send/whatsapp');
        curl_setopt($cURL, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cURL, CURLOPT_POST, true);
        curl_setopt($cURL, CURLOPT_POSTFIELDS, $payload);  
        $response = curl_exec($cURL);

        if ($response === false) {
            return false;
        }

        curl_close($cURL);

        $result = $response ? json_decode($response, true) : [];

        return $result;
    }

    protected function sanitizeNumber($number) {
        $util = PhoneNumberUtil::getInstance();
        $parsed = $util->parse($number, 'IN');
    
        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    protected function configureConnection() {
        $integration = $this->integrationHelper->getIntegrationObject('Zender');
        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $keys = $integration->getDecryptedApiKeys();
            if (empty($keys['zender_api_key'])) {
                return false;
            }
            $this->zenderApiKey = $keys['zender_api_key'];
            $this->connected = true;
        }
        return $this->connected;
    }

    protected function sanitizeContent(string $content, Lead $contact) {
        return strtr($content, array(
            '{contact_title}' => $contact->getTitle(),
            '{contact_firstname}' => $contact->getFirstname(),
            '{contact_lastname}' => $contact->getLastname(),
            '{contact_lastname}' => $contact->getName(),
            '{contact_company}' => $contact->getCompany(),
            '{contact_email}' => $contact->getEmail(),
            '{contact_address1}' => $contact->getAddress1(),
            '{contact_address2}' => $contact->getAddress2(),
            '{contact_city}' => $contact->getCity(),
            '{contact_state}' => $contact->getState(),
            '{contact_country}' => $contact->getCountry(),
            '{contact_zipcode}' => $contact->getZipcode(),
            '{contact_location}' => $contact->getLocation(),
            '{contact_phone}' => ltrim($contact->getLeadPhoneNumber(), '+'),
            '{contact_id_whatsapp_in_zender}' => $contact->getFieldValue('id_whatsapp_in_zender'),
        ));
    }
}
