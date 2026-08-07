<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Controller\AbstractFormController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticZenderBundle\Form\Type\WhatsAppContactSendType;
use InvalidArgumentException;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppAssetUploadService;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppCampaignEnqueuer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class WhatsAppContactActionController extends AbstractFormController
{
    public function __construct(
        ManagerRegistry $doctrine,
        ModelFactory $modelFactory,
        UserHelper $userHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        Translator $translator,
        FlashBag $flashBag,
        ?RequestStack $requestStack,
        ?CorePermissions $security,
        private readonly LeadModel $leadModel,
        private readonly WhatsAppCampaignEnqueuer $whatsAppEnqueuer,
        private readonly WhatsAppAssetUploadService $assetUploadService
    ) {
        parent::__construct(
            $doctrine,
            $modelFactory,
            $userHelper,
            $coreParametersHelper,
            $dispatcher,
            $translator,
            $flashBag,
            $requestStack,
            $security
        );
    }

    public function sendAction(
        Request $request,
        int $contactId = 0
    ): JsonResponse|Response {
        $contact = $this->leadModel->getEntity($contactId);

        if (
            !$contact instanceof Lead
            || null === $this->security
            || !$this->security->hasEntityAccess(
                'lead:leads:viewown',
                'lead:leads:viewother',
                $contact->getPermissionUser()
            )
        ) {
            return $this->modalAccessDenied();
        }

        $contact->setFields(
            $this->leadModel
                ->getRepository()
                ->getFieldValues($contactId)
        );

        $action = $this->generateUrl(
            'mautic_zender_whatsapp_contact_send',
            ['contactId' => $contactId]
        );
        $form = $this->createForm(
            WhatsAppContactSendType::class,
            [
                'content' => '',
                'request_id' => bin2hex(random_bytes(16)),
            ],
            [
                'action' => $action,
                'method' => Request::METHOD_POST,
            ]
        );

        $valid = false;
        $cancelled = false;

        if (Request::METHOD_POST === $request->getMethod()) {
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $data = $form->getData();

                    $createdAsset = null;

                    try {
                        $uploadedFile = $form
                            ->get('multimedia_file')
                            ->getData();

                        if (null !== $uploadedFile) {
                            if (!$uploadedFile instanceof UploadedFile) {
                                throw new InvalidArgumentException(
                                    'invalid_attachment'
                                );
                            }

                            if (
                                null === $this->security
                                || !$this->security->isGranted(
                                    'asset:assets:create'
                                )
                            ) {
                                return $this->modalAccessDenied();
                            }

                            $createdAsset = (
                                $this->assetUploadService->create(
                                    $uploadedFile,
                                    'WhatsApp contact '.$contactId
                                )
                            );
                        }

                        $result = (
                            $this->whatsAppEnqueuer
                                ->enqueueContactAction(
                                    $contact,
                                    (string) ($data['content'] ?? ''),
                                    (string) ($data['request_id'] ?? ''),
                                    (int) $this->user->getId(),
                                    null !== $createdAsset
                                        ? (int) $createdAsset->getId()
                                        : null
                                )
                        );
                    } catch (InvalidArgumentException) {
                        $this->assetUploadService->remove(
                            $createdAsset
                        );
                        $result = [
                            'accepted' => false,
                            'reason' => 'invalid_attachment',
                            'queue_id' => null,
                            'queue_status' => null,
                        ];
                    } catch (\Throwable) {
                        $this->assetUploadService->remove(
                            $createdAsset
                        );
                        $result = [
                            'accepted' => false,
                            'reason' => 'enqueue_failed',
                            'queue_id' => null,
                            'queue_status' => null,
                        ];
                    }

                    if (!$result['accepted']) {
                        $this->assetUploadService->remove(
                            $createdAsset
                        );
                    }

                    if ($result['accepted']) {
                        $statusKey = (
                            'blocked_account'
                            === $result['queue_status']
                        )
                            ? (
                                'mautic.zender.whatsapp.contact.'
                                .'send.status.blocked_account'
                            )
                            : (
                                'mautic.zender.whatsapp.contact.'
                                .'send.status.pending'
                            );

                        $this->addFlashMessage(
                            $this->translator->trans(
                                'mautic.zender.whatsapp.contact.'
                                .'send.queued',
                                [
                                    '%queue%' => (
                                        $result['queue_id']
                                    ),
                                    '%status%' => (
                                        $this->translator->trans(
                                            $statusKey
                                        )
                                    ),
                                ]
                            )
                        );
                    } else {
                        $form->addError(
                            new FormError(
                                $this->translator->trans(
                                    $this->errorTranslationKey(
                                        (string) $result['reason']
                                    )
                                )
                            )
                        );
                        $valid = false;
                    }
                }
            }
        }

        if ($valid || $cancelled) {
            $viewParameters = [
                'objectAction' => 'view',
                'objectId' => $contactId,
            ];

            return $this->postActionRedirect([
                'returnUrl' => $this->generateUrl(
                    'mautic_contact_action',
                    $viewParameters
                ),
                'viewParameters' => $viewParameters,
                'contentTemplate' => (
                    'Mautic\\LeadBundle\\Controller\\'
                    .'LeadController::viewAction'
                ),
                'passthroughVars' => [
                    'mauticContent' => 'lead',
                    'closeModal' => 1,
                ],
            ]);
        }

        return $this->ajaxAction(
            $request,
            [
                'contentTemplate' => (
                    '@MauticZender/WhatsApp/'
                    .'contact_send_modal.html.twig'
                ),
                'viewParameters' => [
                    'form' => $form->createView(),
                    'contact' => $contact,
                    'masked_phone' => $this->maskPhone(
                        (string) $contact->getLeadPhoneNumber()
                    ),
                    'placeholder_options' => (
                        $this->buildPlaceholderOptions($contact)
                    ),
                ],
                'passthroughVars' => [
                    'mauticContent' => 'zenderWhatsAppContactSend',
                    'route' => false,
                ],
            ]
        );
    }

    /**
     * @return list<array{
     *     alias: string,
     *     label: string,
     *     group: string,
     *     token: string
     * }>
     */
    private function buildPlaceholderOptions(Lead $contact): array
    {
        $options = [];

        foreach ($contact->getFields(true) as $alias => $field) {
            if (
                !is_string($alias)
                || !is_array($field)
                || 1 !== preg_match(
                    '/^[A-Za-z0-9_]+$/D',
                    $alias
                )
                || 'id_whatsapp_in_zender'
                    === strtolower($alias)
            ) {
                continue;
            }

            $label = trim(
                (string) ($field['label'] ?? $alias)
            );
            $group = trim(
                (string) ($field['group'] ?? '')
            );

            $options[] = [
                'alias' => $alias,
                'label' => '' !== $label ? $label : $alias,
                'group' => $group,
                'token' => (
                    '{contactfield='.$alias.'}'
                ),
            ];
        }

        usort(
            $options,
            static fn (array $left, array $right): int => (
                strnatcasecmp(
                    $left['label'].' '.$left['alias'],
                    $right['label'].' '.$right['alias']
                )
            )
        );

        return $options;
    }

    private function errorTranslationKey(string $reason): string
    {
        return match ($reason) {
            'not_contactable' => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.not_contactable'
            ),
            'missing_phone' => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.missing_phone'
            ),
            'invalid_phone' => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.invalid_phone'
            ),
            'missing_account' => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.missing_account'
            ),
            'message_unavailable' => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.message_required'
            ),
            'message_too_long' => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.message_too_long'
            ),
            'invalid_request' => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.invalid_request'
            ),
            'invalid_attachment' => (
                'mautic.zender.whatsapp.messages.'
                .'multimedia.invalid'
            ),
            'queue_rejected' => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.queue_rejected'
            ),
            default => (
                'mautic.zender.whatsapp.contact.'
                .'send.error.enqueue_failed'
            ),
        };
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\\D+/', '', $phone) ?? '';

        return '' !== $digits
            ? '••••'.substr($digits, -4)
            : '—';
    }
}
