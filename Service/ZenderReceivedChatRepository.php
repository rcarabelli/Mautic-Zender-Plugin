<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;

final class ZenderReceivedChatRepository
{
    private const TABLE = 'zender_received_chats';
    private const LEADS_TABLE = 'leads';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPage(
        int $limit = 25,
        int $offset = 0
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return $this->connection->fetchAllAssociative(
            'SELECT
                received.id,
                received.provider_received_id,
                received.account_identifier,
                received.sender_phone_normalized,
                received.contact_id,
                received.contact_match_state,
                received.provider_created_at,
                received.message_type,
                received.message_body,
                received.has_attachment,
                lead.firstname,
                lead.lastname,
                lead.email
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.' received
             LEFT JOIN '.MAUTIC_TABLE_PREFIX.self::LEADS_TABLE.' lead
               ON lead.id = received.contact_id
             ORDER BY
                received.provider_created_at DESC,
                received.id DESC
             LIMIT '.$limit.'
             OFFSET '.$offset
        );
    }

    /**
     * @return array{
     *     total: int,
     *     unique: int,
     *     ambiguous: int,
     *     unmatched: int,
     *     unmatchable: int
     * }
     */
    public function getSummary(): array
    {
        $summary = $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) AS total,
                SUM(
                    contact_match_state = :unique_state
                ) AS unique_count,
                SUM(
                    contact_match_state = :ambiguous_state
                ) AS ambiguous_count,
                SUM(
                    contact_match_state = :unmatched_state
                ) AS unmatched_count,
                SUM(
                    contact_match_state = :unmatchable_state
                ) AS unmatchable_count
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE,
            [
                'unique_state' => 'unique_contact',
                'ambiguous_state' => 'ambiguous_contact',
                'unmatched_state' => 'unmatched_contact',
                'unmatchable_state' => 'unmatchable_number',
            ]
        );

        return [
            'total' => max(
                0,
                (int) ($summary['total'] ?? 0)
            ),
            'unique' => max(
                0,
                (int) ($summary['unique_count'] ?? 0)
            ),
            'ambiguous' => max(
                0,
                (int) ($summary['ambiguous_count'] ?? 0)
            ),
            'unmatched' => max(
                0,
                (int) ($summary['unmatched_count'] ?? 0)
            ),
            'unmatchable' => max(
                0,
                (int) ($summary['unmatchable_count'] ?? 0)
            ),
        ];
    }
}
