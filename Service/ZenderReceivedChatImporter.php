<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ZenderReceivedChatImporter
{
    private const TABLE = 'zender_received_chats';
    private const LEADS_TABLE = 'leads';
    private const LOCK_NAME = '7cats.mautic_zender.received_import';
    private const CONTACT_CHUNK = 5000;
    private const MAX_PAGES_CAP = 20;

    public function __construct(
        private readonly ZenderReceivedChatApiClient $apiClient,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(
        int $limit,
        bool $execute,
        bool $verifyIdempotency,
        int $maxPages = 5
    ): array {
        $snapshot = $this->fetchPaginatedSnapshot(
            $limit,
            $maxPages
        );

        $prepared = $this->prepareRecords(
            $snapshot['rows'] ?? []
        );
        $records = $prepared['records'];
        $matchSummary = $this->associateContacts($records);

        $summary = [
            'mode' => $execute ? 'execute' : 'dry_run',
            'provider_read_requests' => (
                (int) ($snapshot['provider_read_requests'] ?? 0)
            ),
            'http_status' => (
                (int) ($snapshot['http_status'] ?? 0)
            ),
            'provider_status' => (
                (int) ($snapshot['provider_status'] ?? 0)
            ),
            'provider_body_sha256' => (
                (string) ($snapshot['body_sha256'] ?? '')
            ),
            'received_rows_fetched' => (
                (int) ($snapshot['received_rows_fetched'] ?? 0)
            ),
            'unique_rows_fetched' => count(
                $snapshot['rows'] ?? []
            ),
            'cross_page_duplicates' => (
                (int) ($snapshot['cross_page_duplicates'] ?? 0)
            ),
            'pages_requested' => (
                (int) ($snapshot['pages_requested'] ?? 0)
            ),
            'pages_with_rows' => (
                (int) ($snapshot['pages_with_rows'] ?? 0)
            ),
            'pagination_max_pages' => (
                (int) ($snapshot['pagination_max_pages'] ?? 0)
            ),
            'pagination_stop_reason' => (
                (string) (
                    $snapshot['pagination_stop_reason']
                    ?? 'unknown'
                )
            ),
            'page_row_counts' => (
                (string) ($snapshot['page_row_counts'] ?? '')
            ),
            'valid_rows' => count($records),
            'skipped_rows' => (
                (int) ($prepared['skipped_rows'] ?? 0)
            ),
            'unique_contact_matches' => (
                (int) ($matchSummary['unique_contact'] ?? 0)
            ),
            'ambiguous_contact_matches' => (
                (int) ($matchSummary['ambiguous_contact'] ?? 0)
            ),
            'unmatched_contacts' => (
                (int) ($matchSummary['unmatched_contact'] ?? 0)
            ),
            'unmatchable_numbers' => (
                (int) ($matchSummary['unmatchable_number'] ?? 0)
            ),
            'first_pass_inserted' => 0,
            'first_pass_replayed' => 0,
            'second_pass_inserted' => 0,
            'second_pass_replayed' => 0,
            'same_snapshot_idempotency' => (
                $verifyIdempotency ? 'pending' : 'not_requested'
            ),
        ];

        if (!$execute) {
            return $summary;
        }

        if ([] === $records) {
            throw new RuntimeException(
                'received_import_has_no_valid_rows'
            );
        }

        if (!$this->acquireLock()) {
            throw new RuntimeException(
                'received_import_lock_busy'
            );
        }

        try {
            $this->connection->beginTransaction();

            $first = $this->persistPass($records);
            $summary['first_pass_inserted'] = $first['inserted'];
            $summary['first_pass_replayed'] = $first['replayed'];

            if ($verifyIdempotency) {
                $second = $this->persistPass($records);
                $summary['second_pass_inserted'] = (
                    $second['inserted']
                );
                $summary['second_pass_replayed'] = (
                    $second['replayed']
                );

                if (
                    0 !== $second['inserted']
                    || count($records) !== $second['replayed']
                ) {
                    throw new RuntimeException(
                        'same_snapshot_idempotency_failed'
                    );
                }

                $summary['same_snapshot_idempotency'] = 'pass';
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->logger->error(
                'Zender received-chat import failed.',
                [
                    'error_class' => $exception::class,
                    'error_fingerprint' => hash(
                        'sha256',
                        $exception->getMessage()
                    ),
                    'provider_mutation' => false,
                    'message_submission' => false,
                ]
            );

            throw $exception;
        } finally {
            $this->releaseLock();
        }

        return $summary;
    }

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     provider_read_requests: int,
     *     http_status: int,
     *     provider_status: int,
     *     body_sha256: string,
     *     received_rows_fetched: int,
     *     cross_page_duplicates: int,
     *     pages_requested: int,
     *     pages_with_rows: int,
     *     pagination_max_pages: int,
     *     pagination_stop_reason: string,
     *     page_row_counts: string
     * }
     */
    private function fetchPaginatedSnapshot(
        int $limit,
        int $maxPages
    ): array {
        $limit = max(1, min(100, $limit));
        $maxPages = max(
            1,
            min(self::MAX_PAGES_CAP, $maxPages)
        );
        $rows = [];
        $seenRows = [];
        $seenPageBodies = [];
        $bodyHashes = [];
        $pageRowCounts = [];
        $providerReadRequests = 0;
        $receivedRowsFetched = 0;
        $crossPageDuplicates = 0;
        $pagesRequested = 0;
        $pagesWithRows = 0;
        $httpStatus = 0;
        $providerStatus = 0;
        $stopReason = 'max_pages';

        for ($page = 1; $page <= $maxPages; ++$page) {
            $snapshot = $this->apiClient->fetchReceived(
                $limit,
                $page
            );
            ++$pagesRequested;
            $providerReadRequests += (int) (
                $snapshot['provider_read_requests'] ?? 0
            );
            $httpStatus = (int) (
                $snapshot['http_status'] ?? 0
            );
            $providerStatus = (int) (
                $snapshot['provider_status'] ?? 0
            );
            $bodyHash = trim(
                (string) ($snapshot['body_sha256'] ?? '')
            );
            $pageRows = [];

            foreach ($snapshot['rows'] ?? [] as $row) {
                if (is_array($row)) {
                    $pageRows[] = $row;
                }
            }

            $rowCount = count($pageRows);
            $receivedRowsFetched += $rowCount;
            $pageRowCounts[] = $page.':'.$rowCount;

            if (
                '' !== $bodyHash
                && isset($seenPageBodies[$bodyHash])
            ) {
                $stopReason = 'repeated_page';
                break;
            }

            if ('' !== $bodyHash) {
                $seenPageBodies[$bodyHash] = true;
                $bodyHashes[] = $bodyHash;
            }

            if (0 === $rowCount) {
                $stopReason = 'empty_page';
                break;
            }

            ++$pagesWithRows;
            $uniqueRowCountBeforePage = count($rows);

            foreach ($pageRows as $row) {
                $rowIdentity = $this->providerRowIdentity($row);

                if (isset($seenRows[$rowIdentity])) {
                    ++$crossPageDuplicates;
                    continue;
                }

                $seenRows[$rowIdentity] = true;
                $rows[] = $row;
            }

            if (count($rows) === $uniqueRowCountBeforePage) {
                $stopReason = 'no_new_unique_rows';
                break;
            }

            if ($rowCount < $limit) {
                $stopReason = 'short_page';
                break;
            }
        }

        return [
            'rows' => $rows,
            'provider_read_requests' => $providerReadRequests,
            'http_status' => $httpStatus,
            'provider_status' => $providerStatus,
            'body_sha256' => hash(
                'sha256',
                implode('|', $bodyHashes)
            ),
            'received_rows_fetched' => $receivedRowsFetched,
            'cross_page_duplicates' => $crossPageDuplicates,
            'pages_requested' => $pagesRequested,
            'pages_with_rows' => $pagesWithRows,
            'pagination_max_pages' => $maxPages,
            'pagination_stop_reason' => $stopReason,
            'page_row_counts' => implode(',', $pageRowCounts),
        ];
    }

    private function providerRowIdentity(array $row): string
    {
        if (
            isset($row['id'])
            && is_scalar($row['id'])
            && ctype_digit((string) $row['id'])
            && (int) $row['id'] > 0
        ) {
            return 'provider_received_id:'.(string) $row['id'];
        }

        $canonical = [
            'account' => $this->scalarString(
                $row['account'] ?? null
            ),
            'attachment' => filter_var(
                $row['attachment'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
            'created' => (
                isset($row['created'])
                && is_scalar($row['created'])
                    ? (string) $row['created']
                    : null
            ),
            'message' => $this->scalarString(
                $row['message'] ?? null
            ),
            'recipient' => $this->scalarString(
                $row['recipient'] ?? null
            ),
        ];
        ksort($canonical);

        return 'fallback:'.hash(
            'sha256',
            json_encode(
                $canonical,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            )
        );
    }
    private function prepareRecords(array $rows): array
    {
        $records = [];
        $skipped = 0;
        $now = gmdate('Y-m-d H:i:s');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                ++$skipped;
                continue;
            }

            $record = $this->prepareRecord($row, $now);

            if (null === $record) {
                ++$skipped;
                continue;
            }

            $records[] = $record;
        }

        return [
            'records' => $records,
            'skipped_rows' => $skipped,
        ];
    }

    private function prepareRecord(
        array $row,
        string $now
    ): ?array {
        $account = $this->scalarString(
            $row['account'] ?? null
        );
        $recipient = $this->scalarString(
            $row['recipient'] ?? null
        );
        $message = $this->scalarString(
            $row['message'] ?? ''
        );

        if (
            null === $account
            || null === $recipient
            || null === $message
        ) {
            return null;
        }

        $providerId = null;

        if (
            isset($row['id'])
            && is_scalar($row['id'])
            && ctype_digit((string) $row['id'])
            && (int) $row['id'] > 0
        ) {
            $providerId = (int) $row['id'];
        }

        if (
            !isset($row['created'])
            || !is_scalar($row['created'])
            || !ctype_digit((string) $row['created'])
        ) {
            return null;
        }

        $providerCreatedEpoch = (int) $row['created'];

        if ($providerCreatedEpoch > 20000000000) {
            $providerCreatedEpoch = intdiv(
                $providerCreatedEpoch,
                1000
            );
        }

        if (
            $providerCreatedEpoch < 946684800
            || $providerCreatedEpoch > 4102444800
        ) {
            return null;
        }

        $sender = $this->normalizePhone($recipient);
        $accountPhone = $this->normalizePhone($account);
        $hasAttachment = filter_var(
            $row['attachment'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $messageSha = hash('sha256', $message);
        $attachmentFingerprint = $hasAttachment
            ? hash('sha256', 'provider_attachment:true')
            : hash('sha256', 'provider_attachment:false');

        $senderNormalized = $sender['e164']
            ?? (
                '' !== $sender['digits']
                    ? $sender['digits']
                    : null
            );
        $accountPhoneNormalized = $accountPhone['e164']
            ?? (
                '' !== $accountPhone['digits']
                    ? $accountPhone['digits']
                    : null
            );

        $dedupeKey = null !== $providerId
            ? hash(
                'sha256',
                'provider_received_id|'.$providerId
            )
            : hash(
                'sha256',
                implode('|', [
                    (string) ($accountPhoneNormalized ?? $account),
                    (string) ($senderNormalized ?? ''),
                    (string) $providerCreatedEpoch,
                    $messageSha,
                    $attachmentFingerprint,
                ])
            );

        $canonicalPayload = [
            'account' => $account,
            'attachment' => $hasAttachment,
            'created' => $providerCreatedEpoch,
            'id' => $providerId,
            'message_sha256' => $messageSha,
            'recipient_normalized' => $senderNormalized,
        ];
        ksort($canonicalPayload);

        $payloadHash = hash(
            'sha256',
            json_encode(
                $canonicalPayload,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            )
        );

        $matchKeys = [];

        if (is_string($sender['e164'])) {
            $matchKeys[] = 'e164:'.$sender['e164'];
        }

        if ('' !== $sender['digits']) {
            $matchKeys[] = 'digits:'.$sender['digits'];
        }

        return [
            'provider_received_id' => $providerId,
            'dedupe_key' => $dedupeKey,
            'account_identifier' => mb_substr(
                $account,
                0,
                191
            ),
            'account_phone_normalized' => (
                $accountPhoneNormalized
            ),
            'sender_phone_normalized' => (
                $senderNormalized
            ),
            'contact_id' => null,
            'contact_match_state' => (
                [] === $matchKeys
                    ? 'unmatchable_number'
                    : 'unmatched_contact'
            ),
            'provider_created_epoch' => (
                $providerCreatedEpoch
            ),
            'provider_created_at' => gmdate(
                'Y-m-d H:i:s',
                $providerCreatedEpoch
            ),
            'message_type' => (
                $hasAttachment ? 'attachment' : 'text'
            ),
            'message_body' => $message,
            'message_sha256' => $messageSha,
            'has_attachment' => $hasAttachment ? 1 : 0,
            'attachment_metadata_json' => (
                $hasAttachment
                    ? '{"provider_attachment":true}'
                    : null
            ),
            'provider_payload_sha256' => $payloadHash,
            'imported_at' => $now,
            'last_seen_at' => $now,
            '_match_keys' => array_values(
                array_unique($matchKeys)
            ),
        ];
    }

    private function associateContacts(array &$records): array
    {
        $targets = [];

        foreach ($records as $record) {
            foreach ($record['_match_keys'] as $key) {
                $targets[$key] = true;
            }
        }

        $matches = [];

        foreach (array_keys($targets) as $key) {
            $matches[$key] = [];
        }

        if ([] !== $targets) {
            $lastId = 0;

            while (true) {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT id, phone, mobile
                     FROM '.MAUTIC_TABLE_PREFIX.self::LEADS_TABLE.'
                     WHERE id > ?
                       AND (
                           (phone IS NOT NULL AND phone <> \'\')
                           OR
                           (mobile IS NOT NULL AND mobile <> \'\')
                       )
                     ORDER BY id ASC
                     LIMIT '.self::CONTACT_CHUNK,
                    [$lastId]
                );

                if ([] === $rows) {
                    break;
                }

                foreach ($rows as $row) {
                    $contactId = (int) ($row['id'] ?? 0);
                    $lastId = max($lastId, $contactId);

                    foreach (['phone', 'mobile'] as $field) {
                        $raw = trim(
                            (string) ($row[$field] ?? '')
                        );

                        if ('' === $raw) {
                            continue;
                        }

                        $normalized = $this->normalizePhone(
                            $raw
                        );
                        $candidateKeys = [];

                        if (is_string($normalized['e164'])) {
                            $candidateKeys[] = (
                                'e164:'.$normalized['e164']
                            );
                        }

                        if ('' !== $normalized['digits']) {
                            $candidateKeys[] = (
                                'digits:'.$normalized['digits']
                            );
                        }

                        foreach (
                            array_unique($candidateKeys)
                            as $candidateKey
                        ) {
                            if (
                                array_key_exists(
                                    $candidateKey,
                                    $matches
                                )
                            ) {
                                $matches[$candidateKey][$contactId] = (
                                    $contactId
                                );
                            }
                        }
                    }
                }

                if (count($rows) < self::CONTACT_CHUNK) {
                    break;
                }
            }
        }

        $summary = [
            'unique_contact' => 0,
            'ambiguous_contact' => 0,
            'unmatched_contact' => 0,
            'unmatchable_number' => 0,
        ];

        foreach ($records as &$record) {
            $candidateIds = [];

            foreach ($record['_match_keys'] as $key) {
                foreach ($matches[$key] ?? [] as $id) {
                    $candidateIds[$id] = $id;
                }
            }

            $candidateIds = array_values($candidateIds);
            sort($candidateIds);

            if ([] === $record['_match_keys']) {
                $record['contact_id'] = null;
                $record['contact_match_state'] = (
                    'unmatchable_number'
                );
            } elseif (1 === count($candidateIds)) {
                $record['contact_id'] = $candidateIds[0];
                $record['contact_match_state'] = (
                    'unique_contact'
                );
            } elseif (count($candidateIds) > 1) {
                $record['contact_id'] = null;
                $record['contact_match_state'] = (
                    'ambiguous_contact'
                );
            } else {
                $record['contact_id'] = null;
                $record['contact_match_state'] = (
                    'unmatched_contact'
                );
            }

            ++$summary[$record['contact_match_state']];
        }
        unset($record);

        return $summary;
    }

    private function persistPass(array $records): array
    {
        $inserted = 0;
        $replayed = 0;

        foreach ($records as $record) {
            unset($record['_match_keys']);

            if ($this->exists($record)) {
                $this->updateExisting($record);
                ++$replayed;
                continue;
            }

            try {
                $this->connection->insert(
                    MAUTIC_TABLE_PREFIX.self::TABLE,
                    $record
                );
                ++$inserted;
            } catch (UniqueConstraintViolationException) {
                $this->updateExisting($record);
                ++$replayed;
            }
        }

        return [
            'inserted' => $inserted,
            'replayed' => $replayed,
        ];
    }

    private function exists(array $record): bool
    {
        if (null !== $record['provider_received_id']) {
            return false !== $this->connection->fetchOne(
                'SELECT id
                 FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
                 WHERE provider_received_id = :provider_id
                    OR dedupe_key = :dedupe_key
                 LIMIT 1',
                [
                    'provider_id' => (
                        $record['provider_received_id']
                    ),
                    'dedupe_key' => $record['dedupe_key'],
                ]
            );
        }

        return false !== $this->connection->fetchOne(
            'SELECT id
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE dedupe_key = :dedupe_key
             LIMIT 1',
            ['dedupe_key' => $record['dedupe_key']]
        );
    }

    private function updateExisting(array $record): void
    {
        $criteria = null !== $record['provider_received_id']
            ? ['provider_received_id' => (
                $record['provider_received_id']
            )]
            : ['dedupe_key' => $record['dedupe_key']];

        $this->connection->update(
            MAUTIC_TABLE_PREFIX.self::TABLE,
            [
                'account_identifier' => (
                    $record['account_identifier']
                ),
                'account_phone_normalized' => (
                    $record['account_phone_normalized']
                ),
                'sender_phone_normalized' => (
                    $record['sender_phone_normalized']
                ),
                'contact_id' => $record['contact_id'],
                'contact_match_state' => (
                    $record['contact_match_state']
                ),
                'provider_created_epoch' => (
                    $record['provider_created_epoch']
                ),
                'provider_created_at' => (
                    $record['provider_created_at']
                ),
                'message_type' => $record['message_type'],
                'message_body' => $record['message_body'],
                'message_sha256' => $record['message_sha256'],
                'has_attachment' => $record['has_attachment'],
                'attachment_metadata_json' => (
                    $record['attachment_metadata_json']
                ),
                'provider_payload_sha256' => (
                    $record['provider_payload_sha256']
                ),
                'last_seen_at' => $record['last_seen_at'],
            ],
            $criteria
        );
    }

    private function acquireLock(): bool
    {
        return 1 === (int) $this->connection->fetchOne(
            'SELECT GET_LOCK(:lock_name, 0)',
            ['lock_name' => self::LOCK_NAME]
        );
    }

    private function releaseLock(): void
    {
        $this->connection->fetchOne(
            'SELECT RELEASE_LOCK(:lock_name)',
            ['lock_name' => self::LOCK_NAME]
        );
    }

    private function scalarString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private function normalizePhone(string $raw): array
    {
        $raw = trim($raw);

        if ('' === $raw) {
            return [
                'digits' => '',
                'e164' => null,
                'quality' => 'empty',
            ];
        }

        $hadWhatsAppJid = str_contains($raw, '@');

        if ($hadWhatsAppJid) {
            $raw = explode('@', $raw, 2)[0];
        }

        $raw = trim($raw);
        $hadPlusPrefix = str_starts_with($raw, '+');
        $hadDoubleZeroPrefix = str_starts_with(
            $raw,
            '00'
        );

        if ($hadDoubleZeroPrefix) {
            $raw = '+'.substr($raw, 2);
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (
            '' === $digits
            || strlen($digits) < 8
            || strlen($digits) > 15
        ) {
            return [
                'digits' => $digits,
                'e164' => null,
                'quality' => 'unusable',
            ];
        }

        $explicitInternationalSignal = (
            $hadPlusPrefix
            || $hadDoubleZeroPrefix
            || $hadWhatsAppJid
        );

        if (!$explicitInternationalSignal) {
            return [
                'digits' => $digits,
                'e164' => null,
                'quality' => (
                    'digits_only_requires_region_or_review'
                ),
            ];
        }

        $candidate = str_starts_with($raw, '+')
            ? $raw
            : '+'.$digits;
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($candidate, null);

            if ($util->isValidNumber($parsed)) {
                $e164 = $util->format(
                    $parsed,
                    PhoneNumberFormat::E164
                );

                return [
                    'digits' => (
                        preg_replace('/\D+/', '', $e164)
                        ?? ''
                    ),
                    'e164' => $e164,
                    'quality' => 'e164_valid',
                ];
            }

            if ($util->isPossibleNumber($parsed)) {
                $e164 = $util->format(
                    $parsed,
                    PhoneNumberFormat::E164
                );

                return [
                    'digits' => (
                        preg_replace('/\D+/', '', $e164)
                        ?? ''
                    ),
                    'e164' => $e164,
                    'quality' => (
                        'e164_possible_not_valid'
                    ),
                ];
            }
        } catch (NumberParseException) {
        }

        return [
            'digits' => $digits,
            'e164' => null,
            'quality' => (
                'digits_only_requires_region_or_review'
            ),
        ];
    }
}
