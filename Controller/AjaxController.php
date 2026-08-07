<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AjaxController extends CommonAjaxController
{
    public function whatsAppDemoContactListAction(
        Request $request,
        LeadModel $leadModel,
        CorePermissions $corePermissions
    ): JsonResponse {
        $results = [];

        if (
            !$corePermissions->isGranted(
                [
                    'lead:leads:viewown',
                    'lead:leads:viewother',
                ],
                'MATCH_ONE'
            )
        ) {
            $results['success'] = 1;

            return $this->sendJsonResponse($results);
        }

        $search = trim(
            (string) InputHelper::clean(
                $request->query->get('filter', '')
            )
        );

        if ('' === $search) {
            $results['success'] = 1;

            return $this->sendJsonResponse($results);
        }

        $filter = [
            'string' => $search,
            'force' => [
                [
                    'column' => 'l.id_whatsapp_in_zender',
                    'expr' => 'isNotNull',
                    'value' => null,
                ],
                [
                    'column' => 'l.id_whatsapp_in_zender',
                    'expr' => 'neq',
                    'value' => '',
                ],
            ],
        ];

        if (
            !$corePermissions->isGranted(
                ['lead:leads:viewother'],
                'MATCH_ONE'
            )
        ) {
            $filter['force'][] = $this->translator->trans(
                'mautic.core.searchcommand.ismine'
            );
        }

        $contacts = $leadModel->getEntities([
            'start' => 0,
            'limit' => 20,
            'filter' => $filter,
            'orderBy' => (
                'l.firstname,l.lastname,l.email,l.id'
            ),
            'orderByDir' => 'ASC',
            'withTotalCount' => false,
        ]);

        foreach ($contacts as $contact) {
            if (!$contact instanceof Lead) {
                continue;
            }

            $contactId = (int) $contact->getId();
            $contact->setFields(
                $leadModel
                    ->getRepository()
                    ->getFieldValues($contactId)
            );

            if (
                '' === trim(
                    (string) $contact->getFieldValue(
                        'id_whatsapp_in_zender'
                    )
                )
            ) {
                continue;
            }

            $results[] = [
                'value' => $this->buildContactLabel($contact),
                'id' => $contactId,
            ];
        }

        $results['success'] = 1;

        return $this->sendJsonResponse($results);
    }

    private function buildContactLabel(Lead $contact): string
    {
        $contactId = (int) $contact->getId();
        $name = trim((string) $contact->getName());
        $email = trim((string) $contact->getEmail());
        $phone = trim(
            (string) $contact->getLeadPhoneNumber()
        );
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $maskedPhone = '' !== $digits
            ? '••••'.substr($digits, -4)
            : '';

        if ('' === $name) {
            $name = '' !== $email
                ? $email
                : 'Contact #'.$contactId;
        }

        $parts = ['#'.$contactId, $name];

        if ('' !== $email && $email !== $name) {
            $parts[] = $email;
        }

        if ('' !== $maskedPhone) {
            $parts[] = $maskedPhone;
        }

        return implode(' — ', $parts);
    }
}
