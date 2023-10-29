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

namespace MauticPlugin\MauticZenderv2Bundle\Transport;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Api\AbstractSmsApi;
use Monolog\Logger;
use GuzzleHttp\Client;

class Zenderv2Transport extends AbstractSmsApi
{
    private const ZENDERv2_TYPE = "text";
    
    /**
     * @var string
     */

    /**
     * @var string
     */
    private $webhookv2Url;

    private $zenderv2ApiUrl;

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
    private $zenderv2_api_key;

    /**
     * @var string
     */
    private $sender_id;

    /**
     * @var bool
     */
    protected $connected;

    /**
     * @param IntegrationHelper $integrationHelper
     * @param Logger            $logger
     * @param Client            $client
     */
    public function __construct(IntegrationHelper $integrationHelper, Logger $logger, Client $client)
    {
        $this->integrationHelper = $integrationHelper;
        $this->logger = $logger;
        $this->client = $client;
        $this->connected = false;
    
        $this->logger->addInfo("[INIT] Constructor invoked.");
    
        // Fetch the integration object for Zenderv2
        $integration = $this->integrationHelper->getIntegrationObject('Zenderv2'); // Ensure the name matches your integration name
    
        if ($integration) {
            $this->logger->addInfo("[INIT] Found Zenderv2 integration object.");
            
            if ($integration->getIntegrationSettings()->getIsPublished()) {
                $this->logger->addInfo("[INIT] Zenderv2 Integration is published.");
    
                // Fetch the decrypted API keys (it includes all settings that are encrypted)
                $keys = $integration->getDecryptedApiKeys();
                $this->logger->addInfo("[INIT] Decrypted API keys fetched.", ['keys' => array_keys($keys)]); // only log the keys, not the values, for security reasons
    
                // Fetch the Zenderv2 API URL
                $this->zenderv2ApiUrl = isset($keys['zenderv2_api_url']) ? $keys['zenderv2_api_url'] : '';
                if (!$this->zenderv2ApiUrl) {
                    $this->logger->addWarning("[INIT] Zenderv2 API URL not set.");
                }
    
                // Fetch the Webhookv2 URL from keys
                $this->webhookv2Url = isset($keys['webhookv2_url']) ? $keys['webhookv2_url'] : '';
                if (!$this->webhookv2Url) {
                    $this->logger->addWarning("[INIT] Webhookv2 URL not set.");
                }
    
            } else {
                $this->logger->addWarning("[INIT] Zenderv2 Integration is not published.");
            }
        } else {
            $this->logger->addError("[INIT] Could not find Zenderv2 Integration object.");
        }
    }


    /**
     * @param Lead   $contact
     * @param string $content
     *
     * @return bool|string
     */
    public function sendSms(Lead $contact, $content)
    {
        // Log when the function is called
        $this->logger->addInfo("sendSms function called for contact ID: " . $contact->getId());
        
        $number = $contact->getLeadPhoneNumber();
        if (empty($number)) {
            return false;
        }
    
        // Fetch the custom field value for "id_whatsapp_en_zender"
        $accountIdInZenderv2 = $contact->getFieldValue('id_whatsapp_en_zender');
    
        // If the accountIdInZenderv2 is empty, log the situation and return.
        if (empty($accountIdInZenderv2)) {
            $this->logger->addWarning("Zenderv2 MSG request skipped. 'id_whatsapp_en_zender' field is empty for contact ID: " . $contact->getId());
            return false;
        }
    
        try {
            $number = substr($this->sanitizeNumber($number), 1);
            // Log after sanitizing the number
            $this->logger->addInfo("Sanitized number: " . $number);
        } catch (NumberParseException $e) {
            $this->logger->addInfo('Invalid number format. ', ['exception' => $e]);
            return $e->getMessage();
        }
    
        try {
            if (!$this->connected && !$this->configureConnection()) {
                throw new \Exception("Zenderv2 MSG is not configured properly.");
            }
    
            $content = $this->sanitizeContent($content, $contact);
            if (empty($content)) {
                throw new \Exception('Message content is Empty.');
            }
    
            // Log just before sending the SMS
            $this->logger->addInfo("Sending SMS to: " . $number . " with content: " . $content);
    
            // Use the fetched accountIdInZenderv2 for the "account" key in the $chat array.
            $chat = [
                "secret" => $this->zenderv2_api_key, 
                "account" => $accountIdInZenderv2,
                "recipient" => '+'.$number,
                "type" => self::ZENDERv2_TYPE,
                "message" => $content
            ];
        
            $response = $this->send($number, $content, $accountIdInZenderv2); // <-- Pass it here
            $this->logger->addInfo("Zenderv2 MSG request succeeded. ", ['response' => $response]);
            return true;
        } catch (\Exception $e) {
            $this->logger->addError("Zenderv2 MSG request failed. ", ['exception' => $e]);
            return $e->getMessage();
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
    protected function send($number, $content, $accountIdInZenderv2)
    {
        $this->logger->addInfo("[SEND FUNC] Preparing payload for sending message.");
    
        $payload = [
            "secret" => $this->zenderv2_api_key,
            "account" => $accountIdInZenderv2,
            "recipient" => '+'.$number,
            "type" => self::ZENDERv2_TYPE,
            "message" => $content
        ];
        
        $this->logger->addInfo('Processed data for Zender: ', ['payload' => $payload]);
    
        // Send the request using cURL
        $cURL = curl_init($this->zenderv2ApiUrl);
        curl_setopt($cURL, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cURL, CURLOPT_POST, true);
        curl_setopt($cURL, CURLOPT_POSTFIELDS, $payload);  
        $response = curl_exec($cURL);
        
        if ($response === false) {
            $this->logger->addError('Curl error: ', ['error' => curl_error($cURL)]);
            return false;
        }
        
        curl_close($cURL);
        
        $result = $response ? json_decode($response, true) : [];
        $this->logger->addInfo('Zender response: ', ['response' => $result]);
    
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
        $integration = $this->integrationHelper->getIntegrationObject('Zenderv2');
        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $keys = $integration->getDecryptedApiKeys();
            if (empty($keys['zenderv2_api_key'])) {
                return false;
            }
            $this->zenderv2_api_key = $keys['zenderv2_api_key'];
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
            '{contact_id_whatsapp_en_zender}' => $contact->getFieldValue('id_whatsapp_en_zender'), // Added this line
        ));
    }
}
