<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\LeadBundle\Segment\ContactSegmentService;
use MauticPlugin\MauticZenderBundle\Form\Type\WhatsAppDemoSendType;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppAssetUploadService;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppCampaignEnqueuer;
use MauticPlugin\MauticZenderBundle\Service\DispatchQueueRepository;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppSegmentEligibilityService;
use MauticPlugin\MauticZenderBundle\Service\Ai\SpintaxGenerationService;
use MauticPlugin\MauticZenderBundle\Service\WhatsAppMessageRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class WhatsAppMessageController extends CommonController
{
    public const CSRF_ID = 'mautic_zender_whatsapp_message';
    public const SEGMENT_QUEUE_CSRF_ID = 'mautic_zender_whatsapp_segment_queue';

    public function __construct(
        ManagerRegistry $doctrine,
        ModelFactory $modelFactory,
        private readonly UserHelper $userHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        Translator $translator,
        FlashBag $flashBag,
        ?RequestStack $requestStack,
        ?CorePermissions $security,
        private readonly WhatsAppMessageRepository $messageRepository,
        private readonly DispatchQueueRepository $dispatchQueueRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly WhatsAppCampaignEnqueuer $whatsAppEnqueuer,
        private readonly LeadModel $leadModel,
        private readonly ListModel $listModel,
        private readonly ContactSegmentService $contactSegmentService,
        private readonly WhatsAppSegmentEligibilityService $segmentEligibilityService,
        private readonly SpintaxGenerationService $spintaxGenerationService,
        private readonly AssetModel $assetModel,
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

    public function indexAction(Request $request): Response
    {
        // AI_SPINTAX_EARLY_DISPATCH_BEFORE_SAVE_BEGIN
        if (
            $request->isMethod('POST')
            && 'ai_spintax_generate'
            === (string) $request->request->get(
                'delivery_action',
                ''
            )
        ) {
            // AI_SPINTAX_CONTROLLER_EXCHANGE_LOG_BEGIN
            $controllerRequestId = bin2hex(
                random_bytes(12)
            );
            $request->attributes->set(
                '_zender_spintax_request_id',
                $controllerRequestId
            );

            $this->writeSpintaxControllerDiagnostic(
                'early_dispatch_match',
                $controllerRequestId,
                [
                    'method' => $request->getMethod(),
                    'request_uri' => (
                        $request->getRequestUri()
                    ),
                    'is_xml_http_request' => (
                        $request->isXmlHttpRequest()
                    ),
                    'x_requested_with' => (
                        $request->headers->get(
                            'X-Requested-With'
                        )
                    ),
                    'delivery_action' => (
                        $request->request->get(
                            'delivery_action'
                        )
                    ),
                    'posted_field_names' => array_values(
                        array_filter(
                            array_keys(
                                $request->request->all()
                            ),
                            static fn (string $name): bool => (
                                '_token' !== $name
                            )
                        )
                    ),
                    'source_text' => (
                        $request->request->get(
                            'source_text'
                        )
                    ),
                    'style' => $request->request->get(
                        'style'
                    ),
                    'level' => $request->request->get(
                        'level'
                    ),
                    'minimum_blocks' => (
                        $request->request->get(
                            'minimum_blocks'
                        )
                    ),
                    'minimum_options' => (
                        $request->request->get(
                            'minimum_options'
                        )
                    ),
                    'csrf_token_present' => (
                        '' !== (string) (
                            $request->request->get(
                                '_token',
                                ''
                            )
                        )
                    ),
                ]
            );
            // AI_SPINTAX_CONTROLLER_EXCHANGE_LOG_END

            return $this->generateSpintax($request);
        }
        // AI_SPINTAX_EARLY_DISPATCH_BEFORE_SAVE_END


        if (
            null === $this->security
            || !$this->security->isGranted(
                [
                    'campaign:campaigns:viewown',
                    'campaign:campaigns:viewother',
                ],
                'MATCH_ONE'
            )
        ) {
            return $this->accessDenied();
        }

        $canEdit = $this->security->isGranted(
            [
                'campaign:campaigns:create',
                'campaign:campaigns:editown',
                'campaign:campaigns:editother',
            ],
            'MATCH_ONE'
        );

        if ($request->isMethod(Request::METHOD_POST)) {
            if (!$canEdit) {
                return $this->accessDenied();
            }

            if (
                'segment_enqueue_batch'
                === (string) $request->request->get(
                    'delivery_action',
                    ''
                )
            ) {
                return $this->enqueueSegmentBatch($request);
            }

            return $this->saveMessage($request);
        }

        $editId = $this->normalizeId($request->query->get('edit'));
        $selected = null !== $editId
            ? $this->messageRepository->find($editId)
            : null;
        $activeSegmentQueue = null !== $selected
            ? $this->dispatchQueueRepository
                ->findActiveWhatsAppMessageSchedule(
                    (int) ($selected['id'] ?? 0)
                )
            : null;
        $assetChoices = $this->getAssetChoices();
        $selectedAsset = $this->getSelectedAssetView($selected);
        $segmentChoices = $this->getPublishedSegmentChoices();
        $segmentPreview = $this->getSegmentPreview($request);
        $requestedDestination = trim(
            (string) $request->query->get(
                'delivery_destination',
                ''
            )
        );
        $deliveryDestination = null !== $segmentPreview
            ? 'segment'
            : (in_array(
                $requestedDestination,
                ['campaign', 'segment'],
                true
            )
                ? $requestedDestination
                : (string) (
                    $selected['delivery_destination']
                    ?? 'campaign'
                ));
        $demoForm = $this->createForm(
            WhatsAppDemoSendType::class,
            null,
            ['csrf_protection' => false]
        );

        return $this->delegateView([
            'contentTemplate' => '@MauticZender/WhatsApp/messages.html.twig',
            'viewParameters' => [
                'messages' => $this->messageRepository->findAll(),
                'selected_message' => $selected,
                'active_segment_queue' => $activeSegmentQueue,
                'selected_asset' => $selectedAsset,
                'asset_choices' => $assetChoices,
                'segment_choices' => $segmentChoices,
                'segment_preview' => $segmentPreview,
                'delivery_destination' => $deliveryDestination,
                'csrf_token' => $this->csrfTokenManager->getToken(
                    self::CSRF_ID
                )->getValue(),
                'segment_queue_csrf_token' => (
                    $this->csrfTokenManager->getToken(
                        self::SEGMENT_QUEUE_CSRF_ID
                    )->getValue()
                ),
                'can_edit' => $canEdit,
                'saved' => $request->query->getBoolean('saved'),
                'error' => trim((string) $request->query->get('error', '')),
                'demo_form' => $demoForm->createView(),
                'demo_enqueued' => $request->query->getBoolean('demo_enqueued'),
                'demo_queue_id' => $this->normalizeId($request->query->get('demo_queue_id')),
                'demo_contact_id' => $this->normalizeId($request->query->get('demo_contact_id')),
                'demo_status' => trim((string) $request->query->get('demo_status', '')),
            ],
            'passthroughVars' => [
                'activeLink' => '#mautic.zender.whatsapp.messages',
                'pageTitle' => $this->translator->trans(
                    'mautic.zender.whatsapp.messages.title'
                ),
                'mauticContent' => 'zenderWhatsAppMessages',
            ],
        ]);
    }

    public function cancelSegmentQueueAction(
        Request $request
    ): JsonResponse {
        if (
            null === $this->security
            || !$this->security->isGranted(
                [
                    'campaign:campaigns:create',
                    'campaign:campaigns:editown',
                    'campaign:campaigns:editother',
                ],
                'MATCH_ONE'
            )
        ) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'access_denied'],
                403
            );
        }

        $token = new CsrfToken(
            self::SEGMENT_QUEUE_CSRF_ID,
            (string) $request->request->get('_token', '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'csrf'],
                403
            );
        }

        $messageId = $this->normalizeId(
            $request->request->get('message_id')
        );

        if (null === $messageId) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'invalid_request'],
                400
            );
        }

        if (!$this->dispatchQueueRepository->acquireLock()) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'dispatch_busy'],
                409
            );
        }

        try {
            $cancelled = $this->dispatchQueueRepository
                ->cancelPendingWhatsAppMessageSchedule($messageId);
        } finally {
            $this->dispatchQueueRepository->releaseLock();
        }

        return new JsonResponse([
            'ok' => true,
            'message_id' => $messageId,
            'cancelled_count' => $cancelled,
            'status' => 'cancelled',
        ]);
    }

    public function enqueueSegmentBatchAction(
        Request $request
    ): JsonResponse {
        if (
            null === $this->security
            || !$this->security->isGranted(
                [
                    'campaign:campaigns:create',
                    'campaign:campaigns:editown',
                    'campaign:campaigns:editother',
                ],
                'MATCH_ONE'
            )
        ) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'access_denied'],
                403
            );
        }

        return $this->enqueueSegmentBatch($request);
    }

    private function enqueueSegmentBatch(
        Request $request
    ): JsonResponse {
        $token = new CsrfToken(
            self::SEGMENT_QUEUE_CSRF_ID,
            (string) $request->request->get('_token', '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'csrf'],
                403
            );
        }

        $messageId = $this->normalizeId(
            $request->request->get('message_id')
        );
        $rawSegmentIds = (string) $request->request->get(
            'segment_ids',
            ''
        );

        if ('' === trim($rawSegmentIds)) {
            $legacySegmentId = $this->normalizeId(
                $request->request->get('segment_id')
            );
            $rawSegmentIds = null === $legacySegmentId
                ? ''
                : (string) $legacySegmentId;
        }

        $segmentIds = [];

        foreach (
            preg_split('/[\s,]+/', trim($rawSegmentIds)) ?: []
            as $rawSegmentId
        ) {
            $segmentId = $this->normalizeId($rawSegmentId);

            if (null !== $segmentId) {
                $segmentIds[$segmentId] = $segmentId;
            }
        }

        $segmentIds = array_values($segmentIds);
        sort($segmentIds, SORT_NUMERIC);

        if (
            null === $messageId
            || [] === $segmentIds
            || count($segmentIds) > 25
        ) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'invalid_request'],
                400
            );
        }

        $publishedSegmentIds = [];

        foreach ($this->getPublishedSegmentChoices() as $choice) {
            $publishedSegmentIds[(int) $choice['id']] = true;
        }

        foreach ($segmentIds as $segmentId) {
            if (!isset($publishedSegmentIds[$segmentId])) {
                return new JsonResponse(
                    [
                        'ok' => false,
                        'reason' => 'segment_unavailable',
                    ],
                    404
                );
            }
        }

        $message = $this->messageRepository
            ->findPublished($messageId);

        if (
            null === $message
            || $messageId !== (int) ($message['id'] ?? 0)
        ) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'message_unavailable'],
                404
            );
        }

        $operationId = strtolower(trim(
            (string) $request->request->get(
                'operation_id',
                ''
            )
        ));

        if ('' === $operationId) {
            $operationId = bin2hex(random_bytes(16));
        }

        if (1 !== preg_match('/^[a-f0-9]{32}$/', $operationId)) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'operation_invalid'],
                400
            );
        }

        $afterContactId = max(
            0,
            (int) $request->request->get(
                'after_contact_id',
                0
            )
        );

        try {
            $availableAt = $this->normalizeQueueDateTime(
                (string) $request->request->get(
                    'available_at',
                    ''
                )
            );
            $expiresAt = $this->normalizeQueueDateTime(
                (string) $request->request->get(
                    'expires_at',
                    ''
                )
            );
        } catch (InvalidArgumentException) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'schedule_invalid'],
                400
            );
        }

        $nowUtc = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC')
        );
        $availableAt ??= $nowUtc;

        if ($availableAt < $nowUtc) {
            $availableAt = $nowUtc;
        }

        if (
            null !== $expiresAt
            && $expiresAt <= $availableAt
        ) {
            return new JsonResponse(
                [
                    'ok' => false,
                    'reason' => 'schedule_order_invalid',
                ],
                400
            );
        }

        $availableAtSql = $availableAt->format('Y-m-d H:i:s');
        $expiresAtSql = null !== $expiresAt
            ? $expiresAt->format('Y-m-d H:i:s')
            : null;

        try {
            if (1 === count($segmentIds)) {
                $batch = $this->segmentEligibilityService
                    ->enqueueBatch(
                        $segmentIds[0],
                        $message,
                        $operationId,
                        $afterContactId,
                        250,
                        $availableAtSql,
                        $expiresAtSql
                    );
                $batch['segment_ids'] = $segmentIds;
                $batch['segment_count'] = 1;
                $batch['selection_hash'] = hash(
                    'sha256',
                    (string) $segmentIds[0]
                );
            } else {
                $batch = $this->segmentEligibilityService
                    ->enqueueSegmentSelectionBatch(
                        $segmentIds,
                        $message,
                        $operationId,
                        $afterContactId,
                        250,
                        $availableAtSql,
                        $expiresAtSql
                    );
            }
        } catch (\Throwable) {
            return new JsonResponse(
                ['ok' => false, 'reason' => 'enqueue_failed'],
                500
            );
        }

        return new JsonResponse([
            'ok' => true,
            'operation_id' => $batch['operation_id'],
            'segment_ids' => $batch['segment_ids'],
            'segment_count' => $batch['segment_count'],
            'selection_hash' => $batch['selection_hash'],
            'after_contact_id' => $batch['after_contact_id'],
            'next_contact_id' => $batch['next_contact_id'],
            'scanned_count' => $batch['scanned_count'],
            'eligible_count' => $batch['eligible_count'],
            'enqueued_count' => $batch['enqueued_count'],
            'enqueue_rejected_count' => (
                $batch['enqueue_rejected_count']
            ),
            'available_at' => $availableAt->format(DATE_ATOM),
            'expires_at' => null !== $expiresAt
                ? $expiresAt->format(DATE_ATOM)
                : null,
            'rejected_counts' => $batch['rejected_counts'],
            'queue_status_counts' => (
                $batch['queue_status_counts']
            ),
            'has_more' => $batch['has_more'],
        ]);
    }

    public function generateSpintaxAction(
        Request $request
    ): JsonResponse {
        if (
            null === $this->security
            || !$this->security->isGranted(
                [
                    'campaign:campaigns:create',
                    'campaign:campaigns:editown',
                    'campaign:campaigns:editother',
                ],
                'MATCH_ONE'
            )
        ) {
            return new JsonResponse(
                [
                    'ok' => false,
                    'reason' => 'access_denied',
                ],
                403
            );
        }

        return $this->generateSpintax($request);
    }

    private function generateSpintax(
        Request $request
    ): JsonResponse {
        $diagnosticRequestId = (string) (
            $request->attributes->get(
                '_zender_spintax_request_id'
            )
            ?? bin2hex(random_bytes(12))
        );

        $this->writeSpintaxControllerDiagnostic(
            'generate_action_entry',
            $diagnosticRequestId,
            [
                'method' => $request->getMethod(),
                'request_uri' => $request->getRequestUri(),
                'delivery_action' => (
                    $request->request->get(
                        'delivery_action'
                    )
                ),
            ]
        );

        $token = new CsrfToken(
            self::CSRF_ID,
            (string) $request->request->get('_token', '')
        );

        $csrfValid = (
            $this->csrfTokenManager
                ->isTokenValid($token)
        );

        $this->writeSpintaxControllerDiagnostic(
            'csrf_validation',
            $diagnosticRequestId,
            [
                'valid' => $csrfValid,
                'token_value_logged' => false,
            ]
        );

        if (!$csrfValid) {
            $this->writeSpintaxControllerDiagnostic(
                'controller_response',
                $diagnosticRequestId,
                [
                    'http_status' => 403,
                    'payload' => [
                        'ok' => false,
                        'reason' => 'csrf',
                    ],
                ]
            );

            return new JsonResponse(
                ['ok' => false, 'reason' => 'csrf'],
                403
            );
        }

        $sourceText = trim(
            (string) $request->request->get(
                'source_text',
                ''
            )
        );

        if ('' === $sourceText) {
            return new JsonResponse(
                [
                    'ok' => false,
                    'reason' => 'source_message_empty',
                ],
                400
            );
        }

        if (mb_strlen($sourceText) > 50000) {
            return new JsonResponse(
                [
                    'ok' => false,
                    'reason' => 'source_message_too_long',
                ],
                400
            );
        }

        $style = strtolower(trim(
            (string) $request->request->get(
                'style',
                'mixed'
            )
        ));

        if (!in_array(
            $style,
            ['mixed', 'words', 'phrases'],
            true
        )) {
            $style = 'mixed';
        }

        $level = strtolower(trim(
            (string) $request->request->get(
                'level',
                'minimum_3x3'
            )
        ));

        if (!in_array(
            $level,
            [
                'minimum_3x3',
                'balanced_5x3',
                'high_8x4',
                'custom',
            ],
            true
        )) {
            $level = 'minimum_3x3';
        }

        $minimumBlocks = max(
            3,
            min(
                30,
                (int) $request->request->get(
                    'minimum_blocks',
                    3
                )
            )
        );
        $minimumOptions = max(
            3,
            min(
                10,
                (int) $request->request->get(
                    'minimum_options',
                    3
                )
            )
        );

        $this->writeSpintaxControllerDiagnostic(
            'normalized_input',
            $diagnosticRequestId,
            [
                'source_text' => $sourceText,
                'style' => $style,
                'level' => $level,
                'minimum_blocks' => $minimumBlocks,
                'minimum_options' => $minimumOptions,
            ]
        );

        $result = $this->spintaxGenerationService->generate(
            $sourceText,
            $style,
            $level,
            $minimumBlocks,
            $minimumOptions
        );

        $this->writeSpintaxControllerDiagnostic(
            'service_result',
            $diagnosticRequestId,
            [
                'result' => $result,
            ]
        );

        if (true !== ($result['ok'] ?? false)) {
            $this->writeSpintaxControllerDiagnostic(
                'controller_response',
                $diagnosticRequestId,
                [
                    'http_status' => 422,
                    'payload' => [
                        'ok' => false,
                        'reason' => (string) (
                            $result['error']
                            ?? 'generation_failed'
                        ),
                        'provider' => (string) (
                            $result['provider'] ?? ''
                        ),
                        'model' => (string) (
                            $result['model'] ?? ''
                        ),
                        'validation' => (
                            $result['validation'] ?? null
                        ),
                    ],
                ]
            );

            return new JsonResponse(
                [
                    'ok' => false,
                    'reason' => (string) (
                        $result['error']
                        ?? 'generation_failed'
                    ),
                    'provider' => (string) (
                        $result['provider'] ?? ''
                    ),
                    'model' => (string) (
                        $result['model'] ?? ''
                    ),
                    'validation' => (
                        $result['validation'] ?? null
                    ),
                ],
                422
            );
        }

        $this->writeSpintaxControllerDiagnostic(
            'controller_response',
            $diagnosticRequestId,
            [
                'http_status' => 200,
                'payload' => [
                    'ok' => true,
                    'generated_text' => (string) (
                        $result['generated_text'] ?? ''
                    ),
                    'provider' => (string) (
                        $result['provider'] ?? ''
                    ),
                    'model' => (string) (
                        $result['model'] ?? ''
                    ),
                    'validation' => (
                        $result['validation'] ?? null
                    ),
                ],
            ]
        );

        return new JsonResponse([
            'ok' => true,
            'generated_text' => (string) (
                $result['generated_text'] ?? ''
            ),
            'provider' => (string) (
                $result['provider'] ?? ''
            ),
            'model' => (string) (
                $result['model'] ?? ''
            ),
            'validation' => (
                $result['validation'] ?? null
            ),
        ]);
    }

    /**
     * Append one private controller diagnostic event.
     *
     * CSRF tokens, API keys and credentials are never logged.
     *
     * @param array<string,mixed> $data
     */
    private function writeSpintaxControllerDiagnostic(
        string $event,
        string $requestId,
        array $data
    ): void {
        try {
            $directory = (
                '/home/paellas/.7cats-runtime/'
                .'ai-spintax-debug'
            );
            $file = $directory.'/controller.jsonl';

            if (!is_dir($directory)) {
                @mkdir($directory, 0700, true);
            }

            if (!is_dir($directory)) {
                return;
            }

            @chmod($directory, 0700);

            $record = [
                'timestamp_utc' => gmdate('c'),
                'event' => $event,
                'request_id' => $requestId,
                'component' => (
                    'WhatsAppMessageController'
                ),
                'data' => $data,
            ];

            $encoded = json_encode(
                $record,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if (false === $encoded) {
                return;
            }

            @file_put_contents(
                $file,
                $encoded.PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            @chmod($file, 0600);
        } catch (\Throwable) {
            // Diagnostics must never break the request.
        }
    }

    private function saveMessage(Request $request): RedirectResponse
    {
        $token = new CsrfToken(
            self::CSRF_ID,
            (string) $request->request->get('_token', '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->redirectToRoute(
                'mautic_zender_whatsapp_messages',
                ['error' => 'csrf']
            );
        }

        $messageId = $this->normalizeId($request->request->get('message_id'));
        $name = (string) $request->request->get('name', '');
        $content = (string) $request->request->get('content', '');
        $assetId = $this->normalizeId(
            $request->request->get('asset_id')
        );
        $uploadedFile = $request->files->get('multimedia_file');
        $isPublished = $request->request->getBoolean('is_published');
        $submitAction = trim((string) $request->request->get('submit_action', 'save'));
        $deliveryDestination = trim(
            (string) $request->request->get(
                'delivery_destination',
                'campaign'
            )
        );

        if (!in_array(
            $deliveryDestination,
            ['campaign', 'segment'],
            true
        )) {
            $deliveryDestination = 'campaign';
        }

        $createdAsset = null;

        if (null !== $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile) {
                return $this->redirectToRoute(
                    'mautic_zender_whatsapp_messages',
                    [
                        'edit' => $messageId,
                        'error' => 'mautic.zender.whatsapp.messages.multimedia.invalid',
                    ]
                );
            }

            if (
                null === $this->security
                || !$this->security->isGranted('asset:assets:create')
            ) {
                return $this->accessDenied();
            }

            try {
                $createdAsset = $this->assetUploadService->create(
                    $uploadedFile,
                    $name
                );
                $assetId = (int) $createdAsset->getId();
            } catch (InvalidArgumentException $exception) {
                return $this->redirectToRoute(
                    'mautic_zender_whatsapp_messages',
                    [
                        'edit' => $messageId,
                        'error' => mb_substr(
                            $exception->getMessage(),
                            0,
                            250
                        ),
                    ]
                );
            } catch (\Throwable) {
                return $this->redirectToRoute(
                    'mautic_zender_whatsapp_messages',
                    [
                        'edit' => $messageId,
                        'error' => 'mautic.zender.whatsapp.messages.multimedia.invalid',
                    ]
                );
            }
        } elseif (
            null !== $assetId
            && null === $this->assetModel->getEntity($assetId)
        ) {
            return $this->redirectToRoute(
                'mautic_zender_whatsapp_messages',
                [
                    'edit' => $messageId,
                    'error' => 'mautic.zender.whatsapp.messages.multimedia.invalid',
                ]
            );
        }

        try {
            $savedId = $this->messageRepository->save(
                $messageId,
                $name,
                $content,
                $isPublished,
                $assetId,
                $deliveryDestination
            );
        } catch (InvalidArgumentException $exception) {
            $this->assetUploadService->remove($createdAsset);

            return $this->redirectToRoute(
                'mautic_zender_whatsapp_messages',
                [
                    'edit' => $messageId,
                    'error' => mb_substr($exception->getMessage(), 0, 250),
                ]
            );
        } catch (\Throwable) {
            $this->assetUploadService->remove($createdAsset);

            return $this->redirectToRoute(
                'mautic_zender_whatsapp_messages',
                [
                    'edit' => $messageId,
                    'error' => 'mautic.zender.whatsapp.messages.multimedia.invalid',
                ]
            );
        }

        if ('send_demo' !== $submitAction) {
            $redirectParameters = [
                'edit' => $savedId,
                'saved' => 1,
            ];
            if ('segment' === $deliveryDestination) {
                $segmentIds = [];

                foreach (
                    $request->request->all('segment_ids')
                    as $rawSegmentId
                ) {
                    $segmentId = $this->normalizeId(
                        $rawSegmentId
                    );

                    if (null !== $segmentId) {
                        $segmentIds[$segmentId] = $segmentId;
                    }
                }

                $segmentIds = array_values($segmentIds);
                sort($segmentIds, SORT_NUMERIC);

                $redirectParameters[
                    'delivery_destination'
                ] = 'segment';

                if ([] !== $segmentIds) {
                    $redirectParameters['segment_ids'] = implode(
                        ',',
                        $segmentIds
                    );
                }
            }

            return $this->redirectToRoute(
                'mautic_zender_whatsapp_messages',
                $redirectParameters
            );
        }

        $demoData = $request->request->all(
            'zender_whatsapp_demo'
        );
        $contactId = $this->normalizeId(
            $demoData['contact_id'] ?? null
        );

        if (null === $contactId) {
            return $this->demoErrorRedirect(
                $savedId,
                'mautic.zender.whatsapp.messages.demo.error.contact_required'
            );
        }

        $contact = $this->leadModel->getEntity($contactId);
        $message = $this->messageRepository->find($savedId);

        if (
            null === $contact
            || null === $message
            || null === $this->security
            || !$this->security->hasEntityAccess(
                'lead:leads:viewown',
                'lead:leads:viewother',
                $contact->getPermissionUser()
            )
        ) {
            return $this->demoErrorRedirect(
                $savedId,
                'mautic.zender.whatsapp.messages.demo.error.contact_unavailable'
            );
        }

        $contact->setFields(
            $this->leadModel
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
            return $this->demoErrorRedirect(
                $savedId,
                'mautic.zender.whatsapp.messages.demo.error.missing_account'
            );
        }

        try {
            $result = $this->whatsAppEnqueuer->enqueueDemo($contact, $message);
        } catch (\Throwable) {
            return $this->demoErrorRedirect(
                $savedId,
                'mautic.zender.whatsapp.messages.demo.error.enqueue_failed'
            );
        }

        if (!$result['accepted']) {
            $errorKey = match ($result['reason']) {
                'not_contactable' => 'mautic.zender.whatsapp.messages.demo.error.not_contactable',
                'missing_phone' => 'mautic.zender.whatsapp.messages.demo.error.missing_phone',
                'invalid_phone' => 'mautic.zender.whatsapp.messages.demo.error.invalid_phone',
                'missing_account' => 'mautic.zender.whatsapp.messages.demo.error.missing_account',
                'message_unavailable' => 'mautic.zender.whatsapp.messages.demo.error.message_unavailable',
                default => 'mautic.zender.whatsapp.messages.demo.error.queue_rejected',
            };

            return $this->demoErrorRedirect($savedId, $errorKey);
        }

        return $this->redirectToRoute(
            'mautic_zender_whatsapp_messages',
            [
                'edit' => $savedId,
                'saved' => 1,
                'demo_enqueued' => 1,
                'demo_queue_id' => $result['queue_id'],
                'demo_contact_id' => $contactId,
                'demo_status' => $result['queue_status'],
            ]
        );
    }

    private function demoErrorRedirect(int $messageId, string $error): RedirectResponse
    {
        return $this->redirectToRoute(
            'mautic_zender_whatsapp_messages',
            ['edit' => $messageId, 'saved' => 1, 'error' => $error]
        );
    }

    /**
     * @return array<int, array{id:int,title:string,language:string}>
     */
    /**
     * @param array<string, mixed>|null $selected
     *
     * @return array<string, mixed>|null
     */
    private function getSelectedAssetView(?array $selected): ?array
    {
        $assetId = $this->normalizeId(
            $selected['asset_id'] ?? null
        );

        if (null === $assetId) {
            return null;
        }

        $asset = $this->assetModel->getEntity($assetId);

        if (!$asset instanceof Asset) {
            return null;
        }

        $asset->setUploadDir(
            (string) $this->coreParametersHelper->get('upload_dir')
        );

        $originalName = trim(
            (string) $asset->getOriginalFileName()
        );
        $title = trim((string) $asset->getTitle());
        $absolutePath = $asset->getAbsolutePath();
        $size = is_string($absolutePath) && is_file($absolutePath)
            ? filesize($absolutePath)
            : false;

        $extension = '';

        if (method_exists($asset, 'getExtension')) {
            $extension = strtolower(
                trim((string) $asset->getExtension())
            );
        }

        if ('' === $extension) {
            $extension = strtolower(
                pathinfo($originalName, PATHINFO_EXTENSION)
            );
        }

        $mime = method_exists($asset, 'getMime')
            ? strtolower(trim((string) $asset->getMime()))
            : '';

        $isImage = str_starts_with($mime, 'image/')
            || in_array(
                $extension,
                ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'],
                true
            );

        $sizeValue = false === $size ? null : (int) $size;
        $sizeLabel = null;

        if (null !== $sizeValue) {
            $sizeLabel = $sizeValue >= 1048576
                ? number_format($sizeValue / 1048576, 2).' MB'
                : number_format($sizeValue / 1024, 1).' KB';
        }

        $url = null;

        try {
            $url = $this->assetModel->generateUrl($asset, true);
        } catch (\Throwable) {
            // The edit view still shows file metadata if URL generation fails.
        }

        return [
            'id' => $assetId,
            'title' => '' !== $title ? $title : $originalName,
            'original_name' => $originalName,
            'mime' => $mime,
            'extension' => $extension,
            'size' => $sizeValue,
            'size_label' => $sizeLabel,
            'url' => $url,
            'is_image' => $isImage,
        ];
    }

    /**
     * @return list<array{id: int, name: string, alias: string}>
     */
    private function getPublishedSegmentChoices(): array
    {
        $segments = $this->listModel->getRepository()->findBy(
            ['isPublished' => true],
            ['name' => 'ASC']
        );
        $choices = [];

        foreach ($segments as $segment) {
            if (
                !$segment instanceof LeadList
                || null === $segment->getId()
            ) {
                continue;
            }

            $choices[] = [
                'id' => (int) $segment->getId(),
                'name' => (string) $segment->getName(),
                'alias' => (string) $segment->getAlias(),
            ];
        }

        return $choices;
    }

    /**
     * @return array{
     *     requested: bool,
     *     available: bool,
     *     segment_id: int|null,
     *     segment_name: string,
     *     segment_alias: string,
     *     segment_ids: array<int, int>,
     *     segments: array<int, array{id:int,name:string,alias:string}>,
     *     segment_count: int,
     *     contact_count: int|null,
     *     membership_count: int|null,
     *     duplicate_membership_count: int|null,
     *     reason: string
     * }|null
     */
    private function getSegmentPreview(
        Request $request
    ): ?array {
        $query = $request->query->all();
        $rawSelection = $query['segment_ids']
            ?? $query['segment_id']
            ?? [];

        if (is_string($rawSelection)) {
            $rawSelection = preg_split(
                '/[\s,]+/',
                trim($rawSelection)
            ) ?: [];
        } elseif (!is_array($rawSelection)) {
            $rawSelection = [$rawSelection];
        }

        $segmentIds = [];

        foreach ($rawSelection as $rawSegmentId) {
            $segmentId = $this->normalizeId($rawSegmentId);

            if (null !== $segmentId) {
                $segmentIds[$segmentId] = $segmentId;
            }
        }

        $segmentIds = array_values($segmentIds);
        sort($segmentIds, SORT_NUMERIC);

        if ([] === $segmentIds) {
            return null;
        }

        if (count($segmentIds) > 25) {
            return [
                'requested' => true,
                'available' => false,
                'segment_id' => $segmentIds[0] ?? null,
                'segment_name' => '',
                'segment_alias' => '',
                'segment_ids' => $segmentIds,
                'segments' => [],
                'segment_count' => count($segmentIds),
                'contact_count' => null,
                'membership_count' => null,
                'duplicate_membership_count' => null,
                'reason' => 'selection_limit_exceeded',
            ];
        }

        $publishedChoices = [];

        foreach ($this->getPublishedSegmentChoices() as $choice) {
            $publishedChoices[$choice['id']] = $choice;
        }

        $selectedSegments = [];

        foreach ($segmentIds as $segmentId) {
            if (!isset($publishedChoices[$segmentId])) {
                return [
                    'requested' => true,
                    'available' => false,
                    'segment_id' => $segmentIds[0] ?? null,
                    'segment_name' => '',
                    'segment_alias' => '',
                    'segment_ids' => $segmentIds,
                    'segments' => [],
                    'segment_count' => count($segmentIds),
                    'contact_count' => null,
                    'membership_count' => null,
                    'duplicate_membership_count' => null,
                    'reason' => 'segment_unavailable',
                ];
            }

            $selectedSegments[] = $publishedChoices[$segmentId];
        }

        try {
            $summary = $this->segmentEligibilityService
                ->summarizeSegmentSelection($segmentIds);
        } catch (\Throwable) {
            return [
                'requested' => true,
                'available' => false,
                'segment_id' => $segmentIds[0] ?? null,
                'segment_name' => '',
                'segment_alias' => '',
                'segment_ids' => $segmentIds,
                'segments' => $selectedSegments,
                'segment_count' => count($segmentIds),
                'contact_count' => null,
                'membership_count' => null,
                'duplicate_membership_count' => null,
                'reason' => 'count_unavailable',
            ];
        }

        $singleSegment = 1 === count($selectedSegments)
            ? $selectedSegments[0]
            : null;

        return [
            'requested' => true,
            'available' => true,
            'segment_id' => $segmentIds[0] ?? null,
            'segment_name' => null === $singleSegment
                ? ''
                : $singleSegment['name'],
            'segment_alias' => null === $singleSegment
                ? ''
                : $singleSegment['alias'],
            'segment_ids' => $segmentIds,
            'segments' => $selectedSegments,
            'segment_count' => $summary['segment_count'],
            'contact_count' => $summary['unique_contact_count'],
            'membership_count' => $summary['membership_count'],
            'duplicate_membership_count' => (
                $summary['duplicate_membership_count']
            ),
            'reason' => '',
        ];
    }

    private function getAssetChoices(): array
    {
        $viewOther = null !== $this->security
            && $this->security->isGranted('asset:assets:viewother');
        $repository = $this->assetModel->getRepository();
        $repository->setCurrentUser($this->userHelper->getUser());

        $choices = [];
        foreach ($repository->getAssetList('', 0, 0, $viewOther) as $asset) {
            $id = (int) ($asset['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $choices[] = [
                'id' => $id,
                'title' => trim(
                    (string) ($asset['title'] ?? 'Asset #'.$id)
                ),
                'language' => trim(
                    (string) ($asset['language'] ?? '')
                ),
            ];
        }

        return $choices;
    }

    private function normalizeQueueDateTime(
        string $value
    ): ?\DateTimeImmutable {
        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        try {
            $dateTime = new \DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                'queue_schedule_datetime_invalid',
                0,
                $exception
            );
        }

        return $dateTime->setTimezone(
            new \DateTimeZone('UTC')
        );
    }

    private function normalizeId(mixed $value): ?int
    {
        if (
            !is_scalar($value)
            || 1 !== preg_match('/^[0-9]+$/', (string) $value)
        ) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
