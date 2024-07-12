<?php

namespace MauticPlugin\MauticZenderBundle\Controller;

use Mautic\ApiBundle\Controller\CommonApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Mautic\LeadBundle\Model\LeadModel;

class ApiController extends CommonApiController
{
    public function zenderMessageStatus(Request $request)
    {
        $method = $request->getMethod();

        if ($method === 'POST') {
            $data = json_decode($request->getContent(), true);

            $integrationHelper = $this->get('mautic.helper.integration');
            $integration = $integrationHelper->getIntegrationObject('Zender');
            $settings = $integration->getIntegrationSettings();
            $secret = $settings->getFeatureSettings()['webhook_secret'];

            if (!isset($data['secret']) || $data['secret'] !== $secret) {
                return new JsonResponse(['error' => 'Invalid secret'], 403);
            }

            $payloadType = $data['type'];
            $payloadData = $data['data'];

            if ($payloadType === 'whatsapp') {
                $phone = $payloadData['phone'];
                $message = $payloadData['message'];
                $status = $payloadData['status'];

                /** @var LeadModel $leadModel */
                $leadModel = $this->getModel('lead');
                $lead = $leadModel->getRepository()->findOneBy(['phone' => $phone]);

                if ($lead) {
                    // Aquí puedes actualizar el contacto con el mensaje recibido
                    // Por ejemplo, puedes agregar una nota al contacto
                    $lead->addTag('Received WhatsApp message: ' . $message);
                    $leadModel->saveEntity($lead);

                    return new JsonResponse(['message' => 'Contact updated'], 200);
                } else {
                    return new JsonResponse(['error' => 'Contact not found'], 404);
                }
            }

            return new JsonResponse(['error' => 'Invalid payload type'], 400);
        }

        return new JsonResponse(['error' => 'Method not allowed'], 405);
    }
}
