<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use MauticPlugin\MauticZenderBundle\Service\DispatchQueueRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CampaignWhatsAppStatusController extends CommonController
{
    private const PAGE_SIZE = 50;

    private const FILTERS = [
        'queued',
        'sent_by_zender',
        'held',
        'retry_pending',
        'failed',
    ];

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
        private readonly DispatchQueueRepository $queueRepository
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

    public function indexAction(
        Request $request,
        int $campaignId = 0
    ): Response {
        if (
            $campaignId < 1
            || null === $this->security
            || !$this->security->isGranted(
                [
                    'campaign:campaigns:viewown',
                    'campaign:campaigns:viewother',
                ],
                'MATCH_ONE'
            )
            || !$this->security->isGranted(
                [
                    'lead:leads:viewown',
                    'lead:leads:viewother',
                ],
                'MATCH_ONE'
            )
        ) {
            return $this->accessDenied();
        }

        $campaign = $this->queueRepository
            ->findCampaignWhatsAppStatusHeader($campaignId);

        if (null === $campaign) {
            throw new NotFoundHttpException(
                'WhatsApp campaign status campaign not found.'
            );
        }

        $statusFilter = $this->normalizeStatusFilter(
            $request->query->get('status')
        );
        $page = $this->normalizePage(
            $request->query->get('page', 1)
        );
        $offset = ($page - 1) * self::PAGE_SIZE;

        $statusPage = $this->queueRepository
            ->getCampaignWhatsAppStatusPage(
                $campaignId,
                $statusFilter,
                self::PAGE_SIZE,
                $offset
            );
        $summary = $this->queueRepository
            ->getCampaignWhatsAppStatusSummary($campaignId);

        foreach ($statusPage['items'] as &$item) {
            $item['contact_url'] = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'view',
                    'objectId' => $item['contact_id'],
                ]
            );
        }
        unset($item);

        $routeParameters = ['campaignId' => $campaignId];
        $filterUrls = [
            'all' => $this->generateUrl(
                'mautic_zender_campaign_whatsapp_status',
                $routeParameters
            ),
        ];
        foreach (self::FILTERS as $filter) {
            $filterUrls[$filter] = $this->generateUrl(
                'mautic_zender_campaign_whatsapp_status',
                array_merge($routeParameters, ['status' => $filter])
            );
        }

        $basePageParameters = $routeParameters;
        if (null !== $statusFilter) {
            $basePageParameters['status'] = $statusFilter;
        }

        $previousUrl = $page > 1
            ? $this->generateUrl(
                'mautic_zender_campaign_whatsapp_status',
                array_merge(
                    $basePageParameters,
                    ['page' => $page - 1]
                )
            )
            : null;
        $nextUrl = $statusPage['has_more']
            ? $this->generateUrl(
                'mautic_zender_campaign_whatsapp_status',
                array_merge(
                    $basePageParameters,
                    ['page' => $page + 1]
                )
            )
            : null;

        return $this->delegateView([
            'contentTemplate' => (
                '@MauticZender/CampaignWhatsAppStatus/'
                .'index.html.twig'
            ),
            'viewParameters' => [
                'campaign' => $campaign,
                'campaign_url' => $this->generateUrl(
                    'mautic_campaign_action',
                    [
                        'objectAction' => 'view',
                        'objectId' => $campaignId,
                    ]
                ),
                'status_page' => $statusPage,
                'summary' => $summary,
                'status_filter' => $statusFilter,
                'filter_urls' => $filterUrls,
                'page' => $page,
                'previous_url' => $previousUrl,
                'next_url' => $nextUrl,
            ],
            'passthroughVars' => [
                'pageTitle' => $this->translator->trans(
                    'mautic.zender.whatsapp.campaign_status.title',
                    ['%campaign%' => $campaign['name']]
                ),
                'route' => $this->generateUrl(
                    'mautic_zender_campaign_whatsapp_status',
                    $basePageParameters
                ),
                'mauticContent' => 'zenderCampaignWhatsAppStatus',
            ],
        ]);
    }

    private function normalizeStatusFilter(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, self::FILTERS, true)
            ? $value
            : null;
    }

    private function normalizePage(mixed $value): int
    {
        if (
            (!is_string($value) && !is_int($value))
            || 1 !== preg_match('/^[0-9]+$/', (string) $value)
        ) {
            return 1;
        }

        return max(1, min(100000, (int) $value));
    }
}
