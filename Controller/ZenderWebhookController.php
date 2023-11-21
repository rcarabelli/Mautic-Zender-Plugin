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
    
        // Assuming you have a way to get the LeadModel instance
        $leadModel = $this->getModel('lead');
        // Find lead by phone number
        $lead = $leadModel->getRepository()->findOneBy(['mobile' => $phone]);
    
        if ($lead) {
            // Get the tag repository
            $tagRepository = $this->getModel('lead.tag')->getRepository();
    
            // Find or create the tag
            $tagEntity = $tagRepository->findOneBy(['tag' => 'whatsapp message answered through zender']);
            if (!$tagEntity) {
                $tagEntity = new \Mautic\LeadBundle\Entity\Tag();
                $tagEntity->setTag('whatsapp message answered through zender');
                $tagRepository->saveEntity($tagEntity);
            }
    
            // Add tag to lead
            if (!$lead->getTags()->contains($tagEntity)) {
                $lead->addTag($tagEntity);
                $leadModel->saveEntity($lead);
                $this->logger->debug('Tag added to lead.', [
                    'leadId' => $lead->getId(),
                    'tag' => $tagEntity->getTag()
                ]);
            } else {
                $this->logger->debug('Lead already has the tag.', [
                    'leadId' => $lead->getId(),
                    'tag' => $tagEntity->getTag()
                ]);
            }
        } else {
            $this->logger->debug('Lead not found.', ['phone' => $phone]);
        }
    
        $this->logger->debug('Key validation successful.', [
            'received_key' => $key
        ]);
    
        return new JsonResponse(['message' => 'Webhook received and key validated.'], JsonResponse::HTTP_OK);
    }

}
