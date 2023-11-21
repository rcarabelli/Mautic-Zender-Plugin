<?php

namespace MauticPlugin\MauticZenderBundle\Controller;

use Mautic\ApiBundle\Controller\CommonApiController;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

class ZenderWebhookController extends CommonApiController
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->logger->debug('ZenderWebhookController instantiated.');
    }

    public function receiveAction(Request $request, $key, $phone, $message, $time, $datetime)
    {
        $this->logger->debug('Full URL received.', [
            'full_url' => $request->getSchemeAndHttpHost() . $request->getRequestUri(),
        ]);
        
        $this->logger->debug('Received variables.', [
            'key' => $key,
            'phone' => $phone,
            'message' => $message,
            'time' => $time,
            'datetime' => $datetime
        ]);
    
        if ($key !== '1234') {
            $this->logger->debug('Key validation failed.', [
                'received_key' => $key
            ]);
            return new JsonResponse(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }
    
        $this->logger->debug('Key validation successful.', [
            'received_key' => $key
        ]);
        return new JsonResponse(['message' => 'Webhook received and key validated.'], JsonResponse::HTTP_OK);
    }

}
