<?php
/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 * @author      Jan Kozak <galvani78@gmail.com>
 */

namespace MauticPlugin\MauticZenderBundle\Transport;

use Doctrine\ORM\EntityManager;  // <-- Add this import
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Api\AbstractSmsApi;
use Monolog\Logger;
use GuzzleHttp\Client;

class ZenderTransport extends AbstractSmsApi
{
    private const ZENDER_TYPE = "text";
    
    /**
     * @var string
     */

    /**
     * @var string
     */
    private $shortenerUrl;

    private $zenderApiUrl;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var IntegrationHelper
     */
    protected $integrationHelper;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    private $zender_api_key;

    /**
     * @var string
     */
    private $sender_id;

    /**
     * @var bool
     */
    protected $connected;
    
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $entityManager;

    /**
     * @param IntegrationHelper $integrationHelper
     * @param Logger            $logger
     * @param Client            $client
     */
     
    public function __construct(
        IntegrationHelper $integrationHelper, 
        Logger $logger, 
        Client $client,
        EntityManager $entityManager  // <-- Add this parameter
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->logger = $logger;
        $this->client = $client;
        $this->entityManager = $entityManager;  // <-- Set the property here
        $this->connected = false;
    
        //$this->logger->addInfo("[INIT] Constructor invoked.");
    
        // Fetch the integration object for Zender
        $integration = $this->integrationHelper->getIntegrationObject('Zender'); // Ensure the name matches your integration name
    
        if ($integration) {
            //$this->logger->addInfo("[INIT] Found Zender integration object.");
            
            if ($integration->getIntegrationSettings()->getIsPublished()) {
                //$this->logger->addInfo("[INIT] Zender Integration is published.");
    
                // Fetch the decrypted API keys (it includes all settings that are encrypted)
                $keys = $integration->getDecryptedApiKeys();
                //$this->logger->addInfo("[INIT] Decrypted API keys fetched.", ['keys' => array_keys($keys)]); // only log the keys, not the values, for security reasons
    
                // Fetch the Zender API URL
                $this->zenderApiUrl = isset($keys['zender_api_url']) ? $keys['zender_api_url'] : '';
                if (!$this->zenderApiUrl) {
                    //$this->logger->addWarning("[INIT] Zender API URL not set.");
                }
    
                // Fetch the Shortener URL from keys
                $this->shortenerUrl = isset($keys['shortener_url']) ? $keys['shortener_url'] : '';
                if (!$this->shortenerUrl) {
                    //$this->logger->addWarning("[INIT] Shortener URL not set.");
                }
    
            } else {
                //$this->logger->addWarning("[INIT] Zender Integration is not published.");
            }
        } else {
            //$this->logger->addError("[INIT] Could not find Zender Integration object.");
        }
    }



    protected function findMauticUrls($message) {
        // Updated RegEx to find URLs that match the described pattern
        $pattern = '/(https:\/\/[a-zA-Z0-9.]+\/r\/[a-zA-Z0-9]+?\?ct=[a-zA-Z0-9=+:;,_\-]+)(?:%3D)?([^a-zA-Z0-9]|$)/i';
        preg_match_all($pattern, $message, $matches, PREG_SET_ORDER);
        
        // Remove trailing %3D from the matched URLs (this may now be redundant but is kept for consistency)
        foreach ($matches as &$match) {
            $match[1] = rtrim($match[1], '%3D');
        }
        
        return $matches;
    }
    
    protected function CheckIfMessageHaveMediaLinks($content) {
        $urls = $this->findMauticUrls($content);
        
        foreach ($urls as $url) {
            $redirectId = substr($url[1], strrpos($url[1], "/r/") + 3, strpos($url[1], "?") - (strrpos($url[1], "/r/") + 3)); // Extract the redirect_id part
                
            // Query the database to get the original URL based on the redirect ID
            // Assuming $this->entityManager is an instance of Doctrine's EntityManager
            $repository = $this->entityManager->getRepository('MauticPageBundle:Redirect');
            $redirect = $repository->findOneBy(['redirectId' => $redirectId]);
                
            if ($redirect) {
                $originalUrl = $redirect->getUrl();
        
                if (preg_match('/\.(jpg|png|gif|mp4)$/', $originalUrl)) {
                    $content = str_replace($url[1], $originalUrl, $content); // Replace the tracked URL with the original URL
                }
            }
        }
        
        return $content;
    }



    /**
     * @param Lead   $contact
     * @param string $content
     *
     * @return bool|string
     */
    public function sendSms(Lead $contact, $content)
    {
        $content = $this->CheckIfMessageHaveMediaLinks($content);
    
        $number = $contact->getLeadPhoneNumber();
        if (empty($number)) {
            return false;
        }
    
        // Fetch the custom field value for "id_whatsapp_in_zender"
        $accountIdInZender = $contact->getFieldValue('id_whatsapp_in_zender');
    
        // If the accountIdInZender is empty, return.
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
    
            // Use the fetched accountIdInZender for the "account" key in the $chat array.
            $chat = [
                "secret" => $this->zender_api_key,
                "account" => $accountIdInZender,
                "recipient" => '+' . $number,
                "type" => self::ZENDER_TYPE,
                "message" => $content
            ];
    
            $response = $this->send($number, $content, $accountIdInZender); // <-- Pass it here
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


    
    
    /**
     * @param integer   $number
     * @param string    $content
     * 
     * @return array
     * 
     * @throws \Exception
     */
    protected function send($number, $content, $accountIdInZender) {
        // Trim the %3D from the message
        $pattern = '/(%3D)(?=[^a-zA-Z0-9]|$)/';
        $content = preg_replace($pattern, '', $content);
    
        //$this->logger->addInfo("[SEND FUNC] Preparing payload for sending message.");
    
        $payload = [
            "secret" => $this->zender_api_key,
            "account" => $accountIdInZender,
            "recipient" => '+'.$number,
            "type" => self::ZENDER_TYPE,
            "message" => $content
        ];
    
        //$this->logger->addInfo("[SEND FUNC] Before calling prepareMediaPayload.");
        
        // Modify the payload if media links are detected
        $this->prepareMediaPayload($content, $payload);
    
        //$this->logger->addInfo("[SEND FUNC] After calling prepareMediaPayload.");
        
        // Shorten all URLs in the content
        $urlPattern = '#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#';
        if(preg_match_all($urlPattern, $content, $urls)) {
            //$this->logger->addInfo("[SEND FUNC] Detected URLs: " . json_encode($urls[0]));
            foreach($urls[0] as $url) {
                $shortened = $this->shortenUrl($url);
                $content = str_replace($url, $shortened, $content);
            }
        } else {
            //$this->logger->addWarning("[SEND FUNC] No URLs detected in content.");
        }
        
        // Update the message in the payload with shortened URLs
        $payload["message"] = $content;
    
        // Log the final payload just before sending the request
        //$this->logger->addInfo("[SEND FUNC] Final payload for sending message: " . json_encode($payload));
    
        // Send the request using cURL
        $cURL = curl_init($this->zenderApiUrl);
        curl_setopt($cURL, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cURL, CURLOPT_POST, true);
        curl_setopt($cURL, CURLOPT_POSTFIELDS, $payload);  
        $response = curl_exec($cURL);
    
        if ($response === false) {
            //$this->logger->addError('Curl error: ', ['error' => curl_error($cURL)]);
            return false;
        }
    
        curl_close($cURL);
    
        $result = $response ? json_decode($response, true) : [];

        return $result;
    }









    /**
     * @param string $number
     *
     * @return string
     *
     * @throws NumberParseException
     */
    protected function sanitizeNumber($number)
    {
        $util = PhoneNumberUtil::getInstance();
        $parsed = $util->parse($number, 'IN');

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * @return bool
     */
    protected function configureConnection()
    {
        $integration = $this->integrationHelper->getIntegrationObject('Zender');
        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $keys = $integration->getDecryptedApiKeys();
            if (empty($keys['zender_api_key'])) {
                return false;
            }
            $this->zender_api_key = $keys['zender_api_key'];
            $this->connected = true;
        }
        return $this->connected;
    }

    /**
     * @param string $content
     * @param Lead   $contact
     *
     * @return string
     */
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
            '{contact_id_whatsapp_in_zender}' => $contact->getFieldValue('id_whatsapp_in_zender'), // Added this line
        ));
    }
}