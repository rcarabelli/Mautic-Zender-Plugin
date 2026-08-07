<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use MauticPlugin\MauticZenderBundle\Service\DispatchSettingsWriter;
use MauticPlugin\MauticZenderBundle\Service\Ai\AiSettingsRepository;
use MauticPlugin\MauticZenderBundle\Service\Ai\AiSettingsWriter;
use MauticPlugin\MauticZenderBundle\Service\Ai\AnthropicProviderClient;
use MauticPlugin\MauticZenderBundle\Service\Ai\GrokProviderClient;
use MauticPlugin\MauticZenderBundle\Service\Ai\OpenAiProviderClient;
use MauticPlugin\MauticZenderBundle\Service\ZenderControlReadModel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

final class ZenderControlController extends CommonController
{
    public const SETTINGS_CSRF_ID = 'mautic_zender_settings';

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
        private readonly ZenderControlReadModel $readModel,
        private readonly DispatchSettingsWriter $settingsWriter,
        private readonly AiSettingsRepository $aiSettingsRepository,
        private readonly AiSettingsWriter $aiSettingsWriter,
        private readonly OpenAiProviderClient $openAiProviderClient,
        private readonly AnthropicProviderClient $anthropicProviderClient,
        private readonly GrokProviderClient $grokProviderClient,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
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
            $security,
        );
    }

    public function indexAction(Request $request): Response
    {
        if (
            $request->isMethod(Request::METHOD_POST)
            && 'ai_settings' === (string) (
                $request->request->get('settings_action', '')
            )
        ) {
            return $this->saveAiSettings($request);
        }

        if (
            $request->isMethod(Request::METHOD_POST)
            && 'account_pause_administration' === (string) (
                $request->request->get('settings_action', '')
            )
        ) {
            return $this->saveAccountPause($request);
        }

        if (
            $request->isMethod(Request::METHOD_POST)
            && 'account_quota_administration' === (string) (
                $request->request->get(
                    'settings_action',
                    ''
                )
            )
        ) {
            return $this->saveAccountQuotaAdministration($request);
        }

        if (
            $request->isMethod(Request::METHOD_POST)
            && 'account_administration' === (string) (
                $request->request->get(
                    'settings_action',
                    ''
                )
            )
        ) {
            return $this->saveAccountAdministration($request);
        }

        if (
            $request->isMethod(Request::METHOD_POST)
            && 'accounts_only' === (string) (
                $request->request->get(
                    'settings_action',
                    ''
                )
            )
        ) {
            return $this->saveAccounts($request);
        }

        if (
            $request->isMethod(Request::METHOD_POST)
            && 'dispatcher_only' === (string) (
                $request->request->get(
                    'settings_action',
                    ''
                )
            )
        ) {
            return $this->saveDispatcher($request);
        }

        if (
            $request->isMethod(Request::METHOD_POST)
            && 'window_only' === (string) (
                $request->request->get(
                    'settings_action',
                    ''
                )
            )
        ) {
            return $this->saveWindow($request);
        }

        if (
            $request->isMethod(Request::METHOD_POST)
            && 'interval_only' === (string) (
                $request->request->get(
                    'settings_action',
                    ''
                )
            )
        ) {
            return $this->saveInterval($request);
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            return $this->saveSettings($request);
        }

        /*
         * PHASE_1F3_CONTROLLER_FILTER_QUERY_PARSING_V1
         */
        $queueQuery = $request->query->all();
        $queueFilters = $this->normalizeQueueHistoryFilters(
            $queueQuery
        );
        $queueFilterQuery = $this->buildQueueHistoryFilterQuery(
            $queueFilters
        );
        $queuePage = $this->normalizeQueueHistoryPage(
            $queueQuery['queue_page'] ?? 1
        );
        $queuePageSize = 10;
        $queueOffset = (
            ($queuePage - 1) * $queuePageSize
        );
        $queueHistory = $this->readModel
            ->getFilteredQueueHistoryPage(
                $queueFilters,
                $queuePageSize,
                $queueOffset
            );

        $queueHistory['page'] = $queuePage;
        $queueHistory['page_size'] = $queuePageSize;
        $queueHistory['filters'] = $queueFilters;
        $queueHistory['filter_query'] = $queueFilterQuery;
        $queueHistory['filters_active'] = (
            [] !== $queueFilters
        );
        $queueHistory['previous_page'] = (
            $queuePage > 1
                ? $queuePage - 1
                : null
        );
        $queueHistory['next_page'] = (
            $queueHistory['has_more']
                ? $queuePage + 1
                : null
        );
        $queueHistory['previous_query'] = (
            null !== $queueHistory['previous_page']
                ? array_merge(
                    $queueFilterQuery,
                    [
                        'queue_page' => (
                            $queueHistory['previous_page']
                        ),
                    ]
                )
                : null
        );
        $queueHistory['next_query'] = (
            null !== $queueHistory['next_page']
                ? array_merge(
                    $queueFilterQuery,
                    [
                        'queue_page' => (
                            $queueHistory['next_page']
                        ),
                    ]
                )
                : null
        );
        $queueHistory['from_record'] = (
            [] === $queueHistory['items']
                ? 0
                : $queueOffset + 1
        );
        $queueHistory['to_record'] = (
            [] === $queueHistory['items']
                ? 0
                : $queueOffset
                    + count($queueHistory['items'])
        );


        $receivedPageValue = $request->query->get(
            'received_page',
            1
        );
        $receivedPage = (
            is_scalar($receivedPageValue)
            && 1 === preg_match(
                '/^[0-9]+$/',
                (string) $receivedPageValue
            )
        )
            ? max(1, (int) $receivedPageValue)
            : 1;
        $receivedChats = $this->readModel
            ->getReceivedChatPage(
                $receivedPage,
                25
            );
        $receivedChatsActive = (
            $request->query->has('received_page')
            || $request->query->has('received_tab')
        );
        $whatsappCampaignsActive = $request->query->has(
            'campaigns_tab'
        );
        $whatsappCampaigns = $this->readModel
            ->getWhatsAppCampaignStatusSummaries();

        return $this->delegateView([
            'contentTemplate' => '@MauticZender/Control/index.html.twig',
            'viewParameters' => [
                    'ai_settings' => $this->aiSettingsRepository
                        ->getPublicConfiguration(),
                    'ai_settings_csrf_token' => $this->csrfTokenManager
                        ->getToken(self::SETTINGS_CSRF_ID)
                        ->getValue(),
                'dashboard' => $this->readModel->getDashboard(),
                'queue_history' => $queueHistory,
                'queue_history_active' => (
                    $queueHistory['filters_active']
                    || $request->query->has('queue_page')
                ),
                'received_chats' => $receivedChats,
                'received_chats_active' => $receivedChatsActive,
                'whatsapp_campaigns' => $whatsappCampaigns,
                'whatsapp_campaigns_active' => (
                    $whatsappCampaignsActive
                ),
                'plugin_version' => '2.2.1',
            ],
            'passthroughVars' => [
                'activeLink' => '#mautic.zender.control_center',
                'pageTitle' => 'Control de Zender',
                'route' => $this->generateUrl(
                    'mautic_zender_control_index'
                ),
                'mauticContent' => 'zenderControl',
            ],
        ]);
    }

    /**
     * PHASE_1F3_CONTROLLER_FILTER_HELPERS_V1
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, int|string>
     */
    private function normalizeQueueHistoryFilters(
        array $query
    ): array {
        $filters = [];

        $status = $this->normalizeQueueHistoryIdentifierFilter(
            $query['queue_status'] ?? null
        );
        $source = $this->normalizeQueueHistoryIdentifierFilter(
            $query['queue_source'] ?? null
        );
        $contactId = $this->normalizeQueueHistoryPositiveIdFilter(
            $query['queue_contact'] ?? null
        );
        $smsId = $this->normalizeQueueHistoryPositiveIdFilter(
            $query['queue_sms'] ?? null
        );
        $accountHash = $this->normalizeQueueHistoryAccountHashFilter(
            $query['queue_account'] ?? null
        );
        $queuedFrom = $this->normalizeQueueHistoryDateFilter(
            $query['queue_from'] ?? null
        );
        $queuedTo = $this->normalizeQueueHistoryDateFilter(
            $query['queue_to'] ?? null
        );

        if (null !== $status) {
            $filters['status'] = $status;
        }

        if (null !== $source) {
            $filters['source'] = $source;
        }

        if (null !== $contactId) {
            $filters['contact_id'] = $contactId;
        }

        if (null !== $smsId) {
            $filters['sms_id'] = $smsId;
        }

        if (null !== $accountHash) {
            $filters['account_hash'] = $accountHash;
        }

        if (null !== $queuedFrom) {
            $filters['queued_from'] = $queuedFrom;
        }

        if (null !== $queuedTo) {
            $filters['queued_to'] = $queuedTo;
        }

        return $filters;
    }

    /**
     * @param array<string, int|string> $filters
     *
     * @return array<string, string>
     */
    private function buildQueueHistoryFilterQuery(
        array $filters
    ): array {
        $query = [];
        $mapping = [
            'status' => 'queue_status',
            'source' => 'queue_source',
            'contact_id' => 'queue_contact',
            'sms_id' => 'queue_sms',
            'account_hash' => 'queue_account',
            'queued_from' => 'queue_from',
            'queued_to' => 'queue_to',
        ];

        foreach ($mapping as $filterKey => $queryKey) {
            if (!array_key_exists($filterKey, $filters)) {
                continue;
            }

            $query[$queryKey] = (string) $filters[$filterKey];
        }

        return $query;
    }

    private function normalizeQueueHistoryPage(
        mixed $value
    ): int {
        if (is_int($value)) {
            return min(
                1000000,
                max(1, $value)
            );
        }

        if (!is_string($value)) {
            return 1;
        }

        $value = trim($value);

        if (
            '' === $value
            || 1 !== preg_match('/^[0-9]+$/', $value)
        ) {
            return 1;
        }

        if (
            strlen($value) > 7
            || (
                7 === strlen($value)
                && $value > '1000000'
            )
        ) {
            return 1000000;
        }

        return min(
            1000000,
            max(1, (int) $value)
        );
    }

    private function normalizeQueueHistoryIdentifierFilter(
        mixed $value
    ): ?string {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        if ('' === $value) {
            return null;
        }

        $value = preg_replace(
            '/[^a-z0-9._:-]+/',
            '_',
            $value
        ) ?? '';
        $value = trim($value, '_');

        return '' === $value
            ? null
            : substr($value, 0, 64);
    }

    private function normalizeQueueHistoryPositiveIdFilter(
        mixed $value
    ): ?int {
        if (is_int($value)) {
            return $value > 0
                ? $value
                : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            '' === $value
            || 1 !== preg_match('/^[1-9][0-9]*$/', $value)
        ) {
            return null;
        }

        $normalized = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => PHP_INT_MAX,
                ],
            ]
        );

        return false === $normalized
            ? null
            : $normalized;
    }

    private function normalizeQueueHistoryAccountHashFilter(
        mixed $value
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return 1 === preg_match(
            '/^[a-f0-9]{12}$/',
            $value
        )
            ? $value
            : null;
    }

    private function normalizeQueueHistoryDateFilter(
        mixed $value
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            1 !== preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/',
                $value
            )
        ) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            false === $date
            || (
                false !== $errors
                && (
                    0 < $errors['warning_count']
                    || 0 < $errors['error_count']
                )
            )
            || $value !== $date->format('Y-m-d')
        ) {
            return null;
        }

        return $value;
    }


    public function aiModelsAction(
        Request $request
    ): JsonResponse {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'csrf'],
                Response::HTTP_FORBIDDEN
            );
        }

        $provider = strtolower(trim(
            (string) ($payload['provider'] ?? '')
        ));

        $providerClients = [
            'openai' => $this->openAiProviderClient,
            'anthropic' => $this->anthropicProviderClient,
            'grok' => $this->grokProviderClient,
        ];

        if (!isset($providerClients[$provider])) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'provider'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $credential = trim(
            (string) ($payload['api_key'] ?? '')
        );

        if ('' === $credential) {
            $credential = (string) (
                $this->aiSettingsRepository
                    ->getCredential($provider)
                ?? ''
            );
        }

        if ('' === $credential) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'missing_api_key'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $providerClients[$provider]
            ->inspectCredential($credential);

        if (!(bool) ($result['valid'] ?? false)) {
            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => (string) (
                        $result['error'] ?? 'provider_error'
                    ),
                    'http_status' => $result['http_status'] ?? null,
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return new JsonResponse([
            'ok' => true,
            'models' => array_values(
                (array) ($result['models'] ?? [])
            ),
        ]);
    }

    private function saveAiSettings(
        Request $request
    ): Response {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $this->aiSettingsWriter
                ->saveConfiguration(
                    (string) ($payload['active_provider'] ?? ''),
                    (string) ($payload['active_model'] ?? ''),
                    [
                        'openai' => (string) (
                            $payload['openai_api_key'] ?? ''
                        ),
                        'anthropic' => (string) (
                            $payload['anthropic_api_key'] ?? ''
                        ),
                        'grok' => (string) (
                            $payload['grok_api_key'] ?? ''
                        ),
                    ]
                );
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                [
                    'settings_saved' => 'ai_settings',
                    'settings_tab' => 1,
                ]
            ).'#zender-ai-settings'
        );
    }

    private function saveAccountPause(
        Request $request
    ): Response {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN
            );
        }

        $rawId = $payload['pause_account_id'] ?? null;
        $rawUntil = $payload['paused_until_local'] ?? '';

        if (
            !is_scalar($rawId)
            || 1 !== preg_match(
                '/^[0-9]+$/',
                (string) $rawId
            )
            || !is_scalar($rawUntil)
        ) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $this->settingsWriter->saveAccountPause(
                (int) $rawId,
                (string) $rawUntil
            );
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (Throwable) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_SAVE_FAILED',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                [
                    'settings_saved' => (
                        'account_pause_administration'
                    ),
                    'settings_tab' => 1,
                ]
            ).'#zender-tab-settings'
        );
    }
    private function saveAccountQuotaAdministration(
        Request $request
    ): Response {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $values = $payload[
            'account_daily_limit_override'
        ] ?? null;

        if (!is_array($values) || [] === $values) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $this->settingsWriter
                ->saveAccountQuotaOverrides($values);
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        } catch (Throwable) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_SAVE_FAILED',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                [
                    'settings_saved' => (
                        'account_quota_administration'
                    ),
                    'settings_tab' => 1,
                ]
            ).'#zender-tab-settings'
        );
    }
    private function saveAccountAdministration(
        Request $request
    ): Response {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $accountLabels = $payload['account_label'] ?? null;
        $accountOrder = $payload['account_order'] ?? null;

        if (
            !is_array($accountLabels)
            || !is_array($accountOrder)
            || [] === $accountLabels
            || [] === $accountOrder
        ) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $this->settingsWriter->saveAccountAdministration(
                $accountLabels,
                $accountOrder
            );
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        } catch (Throwable) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_SAVE_FAILED',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                [
                    'settings_saved' => 'account_administration',
                    'settings_tab' => 1,
                ]
            ).'#zender-tab-settings'
        );
    }

    private function saveAccounts(
        Request $request
    ): Response {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $enabledAccountIds = (
            $payload['account_enabled'] ?? []
        );

        if (
            !is_array($enabledAccountIds)
            || [] === $enabledAccountIds
        ) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $this->settingsWriter->saveAccounts(
                array_values($enabledAccountIds)
            );
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        } catch (Throwable) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_SAVE_FAILED',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                ['settings_saved' => 'accounts']
            ).'#zender-tab-settings'
        );
    }

    private function saveDispatcher(
        Request $request
    ): Response {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $enabledValue = $payload['dispatcher_enabled'] ?? null;

        if (
            0 === $enabledValue
            || '0' === $enabledValue
            || false === $enabledValue
        ) {
            $enabled = false;
        } elseif (
            1 === $enabledValue
            || '1' === $enabledValue
            || true === $enabledValue
        ) {
            $enabled = true;
        } else {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $this->settingsWriter->saveDispatcher(
                $enabled
            );
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        } catch (Throwable) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_SAVE_FAILED',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                ['settings_saved' => 'dispatcher']
            ).'#zender-tab-settings'
        );
    }

    private function saveWindow(
        Request $request
    ): Response {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $windowStart = $payload['window_start'] ?? null;
        $windowEnd = $payload['window_end'] ?? null;

        if (
            !is_string($windowStart)
            || !is_string($windowEnd)
        ) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $windowStart = trim($windowStart);
        $windowEnd = trim($windowEnd);

        $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

        if (
            1 !== preg_match($timePattern, $windowStart)
            || 1 !== preg_match($timePattern, $windowEnd)
        ) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $this->settingsWriter->saveWindow(
                $windowStart,
                $windowEnd
            );
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        } catch (Throwable) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_SAVE_FAILED',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                ['settings_saved' => 'window']
            ).'#zender-tab-settings'
        );
    }

    private function saveInterval(
        Request $request
    ): Response {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $intervalValue = (
            $payload['dispatch_interval_seconds'] ?? null
        );

        if (
            is_int($intervalValue)
            || (
                is_string($intervalValue)
                && 1 === preg_match(
                    '/^[0-9]+$/',
                    $intervalValue
                )
            )
        ) {
            $interval = (int) $intervalValue;
        } else {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        if ($interval < 0 || $interval > 600) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $this->settingsWriter->saveInterval($interval);
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        } catch (Throwable) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_SAVE_FAILED',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                ['settings_saved' => 'interval']
            ).'#zender-tab-settings'
        );
    }

    private function saveSettings(Request $request): Response
    {
        $payload = $request->request->all();
        $token = new CsrfToken(
            self::SETTINGS_CSRF_ID,
            (string) ($payload['_token'] ?? '')
        );

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_CSRF_INVALID',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $settings = $this->normalizeSettingsPayload(
                $payload
            );

            $this->settingsWriter->save(
                $settings['dispatcher_enabled'],
                $settings['window_start'],
                $settings['window_end'],
                $settings['dispatch_interval_seconds'],
                $settings['enabled_account_ids']
            );
        } catch (InvalidArgumentException) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        } catch (Throwable) {
            return new Response(
                'MAUTIC_ZENDER_SETTINGS_SAVE_FAILED',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new RedirectResponse(
            $this->generateUrl(
                'mautic_zender_control_index',
                ['settings_saved' => 1]
            ).'#zender-tab-settings'
        );
    }

    /**
     * @return array{
     *     dispatcher_enabled: bool,
     *     window_start: string,
     *     window_end: string,
     *     dispatch_interval_seconds: int,
     *     enabled_account_ids: array<int, int|string>
     * }
     */
    private function normalizeSettingsPayload(
        array $payload
    ): array {
        $windowStart = $payload['window_start'] ?? null;
        $windowEnd = $payload['window_end'] ?? null;
        $intervalValue = (
            $payload['dispatch_interval_seconds'] ?? null
        );
        $enabledAccountIds = (
            $payload['account_enabled'] ?? []
        );

        if (
            !is_string($windowStart)
            || !is_string($windowEnd)
        ) {
            throw new InvalidArgumentException(
                'window values must be strings.'
            );
        }

        if (
            is_int($intervalValue)
            || (
                is_string($intervalValue)
                && 1 === preg_match(
                    '/^[0-9]+$/',
                    $intervalValue
                )
            )
        ) {
            $interval = (int) $intervalValue;
        } else {
            throw new InvalidArgumentException(
                'dispatch_interval_seconds must be an integer.'
            );
        }

        if (!is_array($enabledAccountIds)) {
            throw new InvalidArgumentException(
                'account_enabled must be an array.'
            );
        }

        return [
            'dispatcher_enabled' => (
                '1' === (string) (
                    $payload['dispatcher_enabled'] ?? '0'
                )
            ),
            'window_start' => trim($windowStart),
            'window_end' => trim($windowEnd),
            'dispatch_interval_seconds' => $interval,
            'enabled_account_ids' => array_values(
                $enabledAccountIds
            ),
        ];
    }
}
