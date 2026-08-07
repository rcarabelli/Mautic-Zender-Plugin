<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;

final class WhatsAppSegmentEligibilityService
{
    private const DEFAULT_BATCH_SIZE = 500;
    private const MAXIMUM_BATCH_SIZE = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly LeadModel $leadModel,
        private readonly WhatsAppCampaignEnqueuer $enqueuer
    ) {
    }

    /**
     * @return array{
     *     segment_id: int,
     *     after_contact_id: int,
     *     next_contact_id: int,
     *     scanned_count: int,
     *     eligible_count: int,
     *     eligible_contact_ids: array<int, int>,
     *     rejected_counts: array<string, int>,
     *     has_more: bool
     * }
     */
    public function inspectBatch(
        int $segmentId,
        int $afterContactId = 0,
        int $limit = self::DEFAULT_BATCH_SIZE
    ): array {
        if ($segmentId < 1) {
            throw new \InvalidArgumentException(
                'segment_id_must_be_positive'
            );
        }

        $afterContactId = max(0, $afterContactId);
        $limit = max(
            1,
            min(self::MAXIMUM_BATCH_SIZE, $limit)
        );

        $query = $this->connection->createQueryBuilder();
        $query
            ->select('lll.lead_id')
            ->distinct()
            ->from(
                MAUTIC_TABLE_PREFIX.'lead_lists_leads',
                'lll'
            )
            ->innerJoin(
                'lll',
                MAUTIC_TABLE_PREFIX.'leads',
                'leads',
                'leads.id = lll.lead_id'
            )
            ->where('lll.leadlist_id = :segment_id')
            ->andWhere('lll.manually_removed = 0')
            ->andWhere('lll.lead_id > :after_contact_id')
            ->andWhere('leads.date_identified IS NOT NULL')
            ->setParameter('segment_id', $segmentId)
            ->setParameter('after_contact_id', $afterContactId)
            ->orderBy('lll.lead_id', 'ASC')
            ->setMaxResults($limit + 1);

        $contactIds = array_map(
            'intval',
            $query->executeQuery()->fetchFirstColumn()
        );

        $hasMore = count($contactIds) > $limit;

        if ($hasMore) {
            array_pop($contactIds);
        }

        if ([] === $contactIds) {
            return [
                'segment_id' => $segmentId,
                'after_contact_id' => $afterContactId,
                'next_contact_id' => $afterContactId,
                'scanned_count' => 0,
                'eligible_count' => 0,
                'eligible_contact_ids' => [],
                'rejected_counts' => self::emptyRejectedCounts(),
                'has_more' => false,
            ];
        }

        $contacts = $this->leadModel->getLeadsByIds(
            $contactIds
        );
        $contactsById = [];

        foreach ($contacts as $contact) {
            if (
                $contact instanceof Lead
                && null !== $contact->getId()
            ) {
                $contactsById[(int) $contact->getId()] = $contact;
            }
        }

        $eligibleContactIds = [];
        $rejectedCounts = self::emptyRejectedCounts();

        foreach ($contactIds as $contactId) {
            $contact = $contactsById[$contactId] ?? null;

            if (!$contact instanceof Lead) {
                ++$rejectedCounts['contact_unavailable'];
                continue;
            }

            $contact->setFields(
                $this->leadModel
                    ->getRepository()
                    ->getFieldValues($contactId)
            );

            $eligibility = $this->enqueuer
                ->inspectEligibility($contact);

            if (true === ($eligibility['eligible'] ?? false)) {
                $eligibleContactIds[] = $contactId;
                continue;
            }

            $reason = (string) (
                $eligibility['reason']
                ?? 'contact_unavailable'
            );

            if (!array_key_exists($reason, $rejectedCounts)) {
                $reason = 'contact_unavailable';
            }

            ++$rejectedCounts[$reason];
        }

        return [
            'segment_id' => $segmentId,
            'after_contact_id' => $afterContactId,
            'next_contact_id' => (int) end($contactIds),
            'scanned_count' => count($contactIds),
            'eligible_count' => count($eligibleContactIds),
            'eligible_contact_ids' => $eligibleContactIds,
            'rejected_counts' => $rejectedCounts,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param array<int, int|string> $segmentIds
     *
     * @return array{
     *     segment_ids: array<int, int>,
     *     segment_count: int,
     *     membership_count: int,
     *     unique_contact_count: int,
     *     duplicate_membership_count: int
     * }
     */
    public function summarizeSegmentSelection(
        array $segmentIds
    ): array {
        $normalizedIds = [];

        foreach ($segmentIds as $segmentId) {
            $segmentId = (int) $segmentId;

            if ($segmentId > 0) {
                $normalizedIds[$segmentId] = $segmentId;
            }
        }

        $normalizedIds = array_values($normalizedIds);
        sort($normalizedIds, SORT_NUMERIC);

        if ([] === $normalizedIds) {
            throw new \InvalidArgumentException(
                'segment_selection_empty'
            );
        }

        if (count($normalizedIds) > 25) {
            throw new \InvalidArgumentException(
                'segment_selection_limit_exceeded'
            );
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($normalizedIds), '?')
        );

        $sql = sprintf(
            'SELECT
                COUNT(*) AS membership_count,
                COUNT(DISTINCT selected.lead_id)
                    AS unique_contact_count
             FROM (
                SELECT DISTINCT
                    lll.leadlist_id,
                    lll.lead_id
                FROM %slead_lists_leads lll
                INNER JOIN %sleads leads
                    ON leads.id = lll.lead_id
                WHERE lll.leadlist_id IN (%s)
                  AND lll.manually_removed = 0
                  AND leads.date_identified IS NOT NULL
             ) selected',
            MAUTIC_TABLE_PREFIX,
            MAUTIC_TABLE_PREFIX,
            $placeholders
        );

        $row = $this->connection->fetchAssociative(
            $sql,
            $normalizedIds,
            array_fill(
                0,
                count($normalizedIds),
                ParameterType::INTEGER
            )
        );

        $membershipCount = max(
            0,
            (int) ($row['membership_count'] ?? 0)
        );
        $uniqueContactCount = max(
            0,
            (int) ($row['unique_contact_count'] ?? 0)
        );

        return [
            'segment_ids' => $normalizedIds,
            'segment_count' => count($normalizedIds),
            'membership_count' => $membershipCount,
            'unique_contact_count' => $uniqueContactCount,
            'duplicate_membership_count' => max(
                0,
                $membershipCount - $uniqueContactCount
            ),
        ];
    }

    /**
     * @return array{
     *     segment_id: int,
     *     scanned_count: int,
     *     eligible_count: int,
     *     rejected_counts: array<string, int>,
     *     complete: bool
     * }
     */
    /**
     * Queue one bounded DISTINCT page from a segment selection.
     *
     * @param array<int, int|string> $segmentIds
     * @param array<string, mixed> $message
     *
     * @return array{
     *     segment_ids:array<int, int>,
     *     segment_count:int,
     *     selection_hash:string,
     *     operation_id:string,
     *     after_contact_id:int,
     *     next_contact_id:int,
     *     scanned_count:int,
     *     eligible_count:int,
     *     enqueued_count:int,
     *     enqueue_rejected_count:int,
     *     rejected_counts:array<string, int>,
     *     queue_status_counts:array<string, int>,
     *     has_more:bool
     * }
     */
    public function enqueueSegmentSelectionBatch(
        array $segmentIds,
        array $message,
        string $operationId,
        int $afterContactId = 0,
        int $limit = 250,
        ?string $availableAt = null,
        ?string $expiresAt = null
    ): array {
        $normalizedSegmentIds = [];

        foreach ($segmentIds as $segmentId) {
            $segmentId = (int) $segmentId;

            if ($segmentId > 0) {
                $normalizedSegmentIds[$segmentId] = $segmentId;
            }
        }

        $normalizedSegmentIds = array_values(
            $normalizedSegmentIds
        );
        sort($normalizedSegmentIds, SORT_NUMERIC);

        $operationId = strtolower(trim($operationId));
        $limit = min(250, max(1, $limit));
        $afterContactId = max(0, $afterContactId);

        if (
            count($normalizedSegmentIds) < 2
            || count($normalizedSegmentIds) > 25
            || 1 !== preg_match('/^[a-f0-9]{32}$/', $operationId)
        ) {
            throw new \InvalidArgumentException(
                'segment_selection_operation_invalid'
            );
        }

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($normalizedSegmentIds),
                '?'
            )
        );

        $sql = sprintf(
            'SELECT DISTINCT lll.lead_id
             FROM %slead_lists_leads lll
             INNER JOIN %sleads leads
                ON leads.id = lll.lead_id
             WHERE lll.leadlist_id IN (%s)
               AND lll.manually_removed = 0
               AND leads.date_identified IS NOT NULL
               AND lll.lead_id > ?
             ORDER BY lll.lead_id ASC
             LIMIT %d',
            MAUTIC_TABLE_PREFIX,
            MAUTIC_TABLE_PREFIX,
            $placeholders,
            $limit + 1
        );

        $parameters = array_merge(
            $normalizedSegmentIds,
            [$afterContactId]
        );
        $types = array_fill(
            0,
            count($parameters),
            ParameterType::INTEGER
        );

        $contactIds = array_values(array_map(
            'intval',
            $this->connection->fetchFirstColumn(
                $sql,
                $parameters,
                $types
            )
        ));

        $hasMore = count($contactIds) > $limit;

        if ($hasMore) {
            $contactIds = array_slice($contactIds, 0, $limit);
        }

        $contactsById = [];

        if ([] !== $contactIds) {
            foreach (
                $this->leadModel->getLeadsByIds($contactIds)
                as $contact
            ) {
                if (
                    $contact instanceof Lead
                    && null !== $contact->getId()
                ) {
                    $contactsById[(int) $contact->getId()] = $contact;
                }
            }
        }

        $eligibleCount = 0;
        $enqueuedCount = 0;
        $enqueueRejectedCount = 0;
        $rejectedCounts = [
            'not_contactable' => 0,
            'missing_phone' => 0,
            'invalid_phone' => 0,
            'missing_account' => 0,
            'contact_unavailable' => 0,
            'message_unavailable' => 0,
            'queue_rejected' => 0,
        ];
        $queueStatusCounts = [];

        foreach ($contactIds as $contactId) {
            $contact = $contactsById[$contactId] ?? null;

            if (!$contact instanceof Lead) {
                ++$rejectedCounts['contact_unavailable'];
                continue;
            }

            $contact->setFields(
                $this->leadModel
                    ->getRepository()
                    ->getFieldValues($contactId)
            );

            $eligibility = $this->enqueuer
                ->inspectEligibility($contact);

            if (!$eligibility['eligible']) {
                $reason = (string) $eligibility['reason'];

                if (!array_key_exists($reason, $rejectedCounts)) {
                    $reason = 'contact_unavailable';
                }

                ++$rejectedCounts[$reason];
                continue;
            }

            ++$eligibleCount;

            $result = $this->enqueuer
                ->enqueueSegmentSelectionContact(
                    $contact,
                    $message,
                    $normalizedSegmentIds,
                    $operationId,
                    $availableAt,
                    $expiresAt
                );

            if (!$result['accepted']) {
                ++$enqueueRejectedCount;
                $reason = (string) $result['reason'];

                if (!array_key_exists($reason, $rejectedCounts)) {
                    $reason = 'queue_rejected';
                }

                ++$rejectedCounts[$reason];
                continue;
            }

            ++$enqueuedCount;
            $status = (string) (
                $result['queue_status'] ?? 'unknown'
            );
            $queueStatusCounts[$status] = (
                $queueStatusCounts[$status] ?? 0
            ) + 1;
        }

        ksort($queueStatusCounts);

        return [
            'segment_ids' => $normalizedSegmentIds,
            'segment_count' => count($normalizedSegmentIds),
            'selection_hash' => hash(
                'sha256',
                implode(',', $normalizedSegmentIds)
            ),
            'operation_id' => $operationId,
            'after_contact_id' => $afterContactId,
            'next_contact_id' => [] === $contactIds
                ? $afterContactId
                : (int) end($contactIds),
            'scanned_count' => count($contactIds),
            'eligible_count' => $eligibleCount,
            'enqueued_count' => $enqueuedCount,
            'enqueue_rejected_count' => $enqueueRejectedCount,
            'rejected_counts' => $rejectedCounts,
            'queue_status_counts' => $queueStatusCounts,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Queue one bounded page of current segment members.
     *
     * @param array<string, mixed> $message
     *
     * @return array{
     *     segment_id:int,
     *     operation_id:string,
     *     after_contact_id:int,
     *     next_contact_id:int,
     *     scanned_count:int,
     *     eligible_count:int,
     *     enqueued_count:int,
     *     enqueue_rejected_count:int,
     *     rejected_counts:array<string, int>,
     *     queue_status_counts:array<string, int>,
     *     has_more:bool
     * }
     */
    public function enqueueBatch(
        int $segmentId,
        array $message,
        string $operationId,
        int $afterContactId = 0,
        int $limit = 250,
        ?string $availableAt = null,
        ?string $expiresAt = null
    ): array {
        $operationId = strtolower(trim($operationId));

        if (1 !== preg_match('/^[a-f0-9]{32}$/', $operationId)) {
            throw new \InvalidArgumentException(
                'segment_operation_id_invalid'
            );
        }

        $batch = $this->inspectBatch(
            $segmentId,
            $afterContactId,
            min(250, max(1, $limit))
        );

        $eligibleIds = $batch['eligible_contact_ids'];
        $contactsById = [];

        if ([] !== $eligibleIds) {
            foreach (
                $this->leadModel->getLeadsByIds($eligibleIds)
                as $contact
            ) {
                if (
                    $contact instanceof Lead
                    && null !== $contact->getId()
                ) {
                    $contactsById[(int) $contact->getId()] = $contact;
                }
            }
        }

        $enqueuedCount = 0;
        $enqueueRejectedCount = 0;
        $queueStatusCounts = [];

        foreach ($eligibleIds as $contactId) {
            $contact = $contactsById[$contactId] ?? null;

            if (!$contact instanceof Lead) {
                ++$enqueueRejectedCount;
                continue;
            }

            $contact->setFields(
                $this->leadModel
                    ->getRepository()
                    ->getFieldValues($contactId)
            );

            $result = $this->enqueuer->enqueueSegmentContact(
                $contact,
                $message,
                $segmentId,
                $operationId,
                $availableAt,
                $expiresAt
            );

            if (!$result['accepted']) {
                ++$enqueueRejectedCount;
                continue;
            }

            ++$enqueuedCount;
            $status = (string) (
                $result['queue_status'] ?? 'unknown'
            );
            $queueStatusCounts[$status] = (
                $queueStatusCounts[$status] ?? 0
            ) + 1;
        }

        ksort($queueStatusCounts);

        return [
            'segment_id' => $segmentId,
            'operation_id' => $operationId,
            'after_contact_id' => $afterContactId,
            'next_contact_id' => $batch['next_contact_id'],
            'scanned_count' => $batch['scanned_count'],
            'eligible_count' => $batch['eligible_count'],
            'enqueued_count' => $enqueuedCount,
            'enqueue_rejected_count' => $enqueueRejectedCount,
            'rejected_counts' => $batch['rejected_counts'],
            'queue_status_counts' => $queueStatusCounts,
            'has_more' => $batch['has_more'],
        ];
    }

    public function summarize(
        int $segmentId,
        ?int $maximumContacts = null,
        int $batchSize = self::DEFAULT_BATCH_SIZE
    ): array {
        $maximumContacts = null === $maximumContacts
            ? null
            : max(1, $maximumContacts);

        $summary = [
            'segment_id' => $segmentId,
            'scanned_count' => 0,
            'eligible_count' => 0,
            'rejected_counts' => self::emptyRejectedCounts(),
            'complete' => false,
        ];

        $afterContactId = 0;

        while (true) {
            $remaining = null === $maximumContacts
                ? $batchSize
                : min(
                    $batchSize,
                    $maximumContacts - $summary['scanned_count']
                );

            if ($remaining < 1) {
                break;
            }

            $batch = $this->inspectBatch(
                $segmentId,
                $afterContactId,
                $remaining
            );

            $summary['scanned_count'] += $batch['scanned_count'];
            $summary['eligible_count'] += $batch['eligible_count'];

            foreach (
                $batch['rejected_counts']
                as $reason => $count
            ) {
                $summary['rejected_counts'][$reason] += $count;
            }

            if (!$batch['has_more']) {
                $summary['complete'] = true;
                break;
            }

            if (
                null !== $maximumContacts
                && $summary['scanned_count'] >= $maximumContacts
            ) {
                break;
            }

            if (
                $batch['next_contact_id'] <= $afterContactId
                || 0 === $batch['scanned_count']
            ) {
                throw new \RuntimeException(
                    'segment_eligibility_batch_did_not_advance'
                );
            }

            $afterContactId = $batch['next_contact_id'];
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private static function emptyRejectedCounts(): array
    {
        return [
            'not_contactable' => 0,
            'missing_phone' => 0,
            'invalid_phone' => 0,
            'missing_account' => 0,
            'contact_unavailable' => 0,
        ];
    }
}
