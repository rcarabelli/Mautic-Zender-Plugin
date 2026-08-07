<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\DoNotContact as DoNotContactEntity;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use Mautic\LeadBundle\Model\DoNotContact;

final class WhatsAppCampaignEnqueuer
{
    public function __construct(
        private ChannelNeutralEnqueueService $enqueueService,
        private DoNotContact $doNotContact
    ) {
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array{
     *     accepted:bool,
     *     permanent:bool,
     *     reason:string,
     *     queue_id:int|null,
     *     queue_status:string|null
     * }
     */
    public function enqueue(
        Lead $contact,
        array $message,
        LeadEventLog $log
    ): array {
        if (
            DoNotContactEntity::IS_CONTACTABLE
            !== $this->doNotContact->isContactable($contact, 'whatsapp')
        ) {
            return $this->permanentFailure(
                'Contact is not contactable through the WhatsApp channel.'
            );
        }

        $rawNumber = trim((string) $contact->getLeadPhoneNumber());
        if ('' === $rawNumber) {
            return $this->permanentFailure(
                'Contact has no phone number for WhatsApp.'
            );
        }

        try {
            $recipient = $this->normalizePhone($rawNumber);
        } catch (NumberParseException) {
            return $this->permanentFailure(
                'Contact phone number is not valid E.164 WhatsApp input.'
            );
        }

        $accountId = trim((string) $contact->getFieldValue(
            'id_whatsapp_in_zender'
        ));
        if ('' === $accountId) {
            return $this->permanentFailure(
                'Contact has no managed Zender sender account.'
            );
        }

        $messageId = (int) ($message['id'] ?? 0);
        $content = $this->personalize(
            (string) ($message['content'] ?? ''),
            $contact
        );

        if ($messageId < 1 || '' === trim($content)) {
            return [
                'accepted' => false,
                'permanent' => false,
                'reason' => 'WhatsApp message content is unavailable.',
                'queue_id' => null,
                'queue_status' => null,
            ];
        }

        $campaignEvent = $log->getEvent();
        $campaign = $log->getCampaign();
        $logId = $log->getId();
        $eventId = null !== $campaignEvent
            ? (int) $campaignEvent->getId()
            : null;
        $campaignId = null !== $campaign
            ? (int) $campaign->getId()
            : null;

        if ($logId < 1 || null === $eventId || $eventId < 1) {
            return [
                'accepted' => false,
                'permanent' => false,
                'reason' => 'Campaign event log correlation is unavailable.',
                'queue_id' => null,
                'queue_status' => null,
            ];
        }

        $result = $this->enqueueService->enqueue([
            'dedupe_key' => hash(
                'sha256',
                'whatsapp:campaign_event_log:'.$logId
            ),
            'contact_id' => (int) $contact->getId(),
            'channel' => 'whatsapp',
            'sms_id' => null,
            'whatsapp_message_id' => $messageId,
            'campaign_id' => $campaignId,
            'campaign_event_id' => $eventId,
            'campaign_event_log_id' => $logId,
            'stat_tracking_hash' => null,
            'source' => 'campaign.event',
            'source_id' => $eventId,
            'recipient' => $recipient,
            'account_id' => $accountId,
            'content' => $content,
            'priority' => 2,
        ]);

        return [
            'accepted' => $result['accepted'],
            'permanent' => false,
            'reason' => $result['accepted']
                ? ''
                : 'Zender queue rejected the WhatsApp request.',
            'queue_id' => $result['queue_id'],
            'queue_status' => $result['status'],
        ];
    }

    /**
     * Queue one direct text message from the Contact profile.
     *
     * The fixed id_whatsapp_in_zender field remains the only sender-account
     * source. The browser request never calls Zender directly.
     *
     * @return array{
     *     accepted:bool,
     *     permanent:bool,
     *     reason:string,
     *     queue_id:int|null,
     *     queue_status:string|null
     * }
     */
    public function enqueueContactAction(
        Lead $contact,
        string $content,
        string $requestId,
        int $userId,
        ?int $assetId = null
    ): array {
        if (
            DoNotContactEntity::IS_CONTACTABLE
            !== $this->doNotContact->isContactable(
                $contact,
                'whatsapp'
            )
        ) {
            return $this->demoFailure(
                'not_contactable',
                true
            );
        }

        $rawNumber = trim(
            (string) $contact->getLeadPhoneNumber()
        );
        if ('' === $rawNumber) {
            return $this->demoFailure(
                'missing_phone',
                true
            );
        }

        try {
            $recipient = $this->normalizePhone($rawNumber);
        } catch (NumberParseException) {
            return $this->demoFailure(
                'invalid_phone',
                true
            );
        }

        $accountId = trim(
            (string) $contact->getFieldValue(
                'id_whatsapp_in_zender'
            )
        );
        if ('' === $accountId) {
            return $this->demoFailure(
                'missing_account',
                true
            );
        }

        $contactId = (int) $contact->getId();
        $requestId = strtolower(trim($requestId));
        $content = $this->personalize(
            trim($content),
            $contact
        );

        if (
            $contactId < 1
            || $userId < 1
            || 1 !== preg_match(
                '/^[a-f0-9]{32}$/',
                $requestId
            )
        ) {
            return $this->demoFailure(
                'invalid_request',
                false
            );
        }

        if ('' === trim($content)) {
            return $this->demoFailure(
                'message_unavailable',
                false
            );
        }

        if (mb_strlen($content) > 4096) {
            return $this->demoFailure(
                'message_too_long',
                false
            );
        }

        $result = $this->enqueueService->enqueue([
            'dedupe_key' => hash(
                'sha256',
                implode('|', [
                    'whatsapp.contact.profile',
                    (string) $contactId,
                    (string) $userId,
                    $requestId,
                ])
            ),
            'contact_id' => $contactId,
            'channel' => 'whatsapp',
            'sms_id' => null,
            'whatsapp_message_id' => null,
            'asset_id' => $assetId,
            'campaign_id' => null,
            'campaign_event_id' => null,
            'campaign_event_log_id' => null,
            'stat_tracking_hash' => null,
            'source' => 'whatsapp.contact.profile',
            'source_id' => $userId,
            'recipient' => $recipient,
            'account_id' => $accountId,
            'content' => $content,
            'priority' => 0,
        ]);

        return [
            'accepted' => $result['accepted'],
            'permanent' => false,
            'reason' => $result['accepted']
                ? ''
                : 'queue_rejected',
            'queue_id' => $result['queue_id'],
            'queue_status' => $result['status'],
        ];
    }

    /**
     * Queue one repeatable demo using the Contact's fixed Zender association.
     *
     * @param array<string, mixed> $message
     * @return array{accepted:bool,permanent:bool,reason:string,queue_id:int|null,queue_status:string|null}
     */
    /**
     * Read-only contact eligibility for WhatsApp queue preparation.
     *
     * This method applies the same DNC, phone parsing and fixed
     * id_whatsapp_in_zender requirements used by the existing campaign,
     * contact-profile and demo enqueue paths. It never writes to the queue.
     *
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     recipient: string,
     *     account_id: string
     * }
     */
    public function inspectEligibility(Lead $contact): array
    {
        if (
            DoNotContactEntity::IS_CONTACTABLE
            !== $this->doNotContact->isContactable(
                $contact,
                'whatsapp'
            )
        ) {
            return [
                'eligible' => false,
                'reason' => 'not_contactable',
                'recipient' => '',
                'account_id' => '',
            ];
        }

        $rawNumber = trim(
            (string) $contact->getLeadPhoneNumber()
        );

        if ('' === $rawNumber) {
            return [
                'eligible' => false,
                'reason' => 'missing_phone',
                'recipient' => '',
                'account_id' => '',
            ];
        }

        try {
            $recipient = $this->normalizePhone($rawNumber);
        } catch (NumberParseException) {
            return [
                'eligible' => false,
                'reason' => 'invalid_phone',
                'recipient' => '',
                'account_id' => '',
            ];
        }

        $accountId = trim(
            (string) $contact->getFieldValue(
                'id_whatsapp_in_zender'
            )
        );

        if ('' === $accountId) {
            return [
                'eligible' => false,
                'reason' => 'missing_account',
                'recipient' => '',
                'account_id' => '',
            ];
        }

        return [
            'eligible' => true,
            'reason' => '',
            'recipient' => $recipient,
            'account_id' => $accountId,
        ];
    }

    /**
     * Queue one eligible contact for a manual segment broadcast.
     *
     * @param array<string, mixed> $message
     *
     * @return array{
     *     accepted:bool,
     *     permanent:bool,
     *     reason:string,
     *     queue_id:int|null,
     *     queue_status:string|null
     * }
     */
    /**
     * Queue one eligible contact for a multisegment broadcast.
     *
     * @param array<string, mixed> $message
     * @param array<int, int|string> $segmentIds
     *
     * @return array{
     *     accepted:bool,
     *     permanent:bool,
     *     reason:string,
     *     queue_id:int|null,
     *     queue_status:string|null
     * }
     */
    public function enqueueSegmentSelectionContact(
        Lead $contact,
        array $message,
        array $segmentIds,
        string $operationId,
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

        $eligibility = $this->inspectEligibility($contact);

        if (!$eligibility['eligible']) {
            return [
                'accepted' => false,
                'permanent' => true,
                'reason' => $eligibility['reason'],
                'queue_id' => null,
                'queue_status' => null,
            ];
        }

        $contactId = (int) $contact->getId();
        $messageId = (int) ($message['id'] ?? 0);
        $content = $this->personalize(
            (string) ($message['content'] ?? ''),
            $contact
        );
        $operationId = strtolower(trim($operationId));

        if (
            $contactId < 1
            || $messageId < 1
            || count($normalizedSegmentIds) < 2
            || count($normalizedSegmentIds) > 25
            || 1 !== preg_match('/^[a-f0-9]{32}$/', $operationId)
            || '' === trim($content)
        ) {
            return [
                'accepted' => false,
                'permanent' => false,
                'reason' => 'message_unavailable',
                'queue_id' => null,
                'queue_status' => null,
            ];
        }

        $selectionHash = hash(
            'sha256',
            implode(',', $normalizedSegmentIds)
        );
        $primarySegmentId = $normalizedSegmentIds[0];

        $result = $this->enqueueService->enqueue([
            'dedupe_key' => hash(
                'sha256',
                implode('|', [
                    'whatsapp.multisegment',
                    $operationId,
                    $selectionHash,
                    (string) $contactId,
                    (string) $messageId,
                    $eligibility['recipient'],
                    $eligibility['account_id'],
                ])
            ),
            'contact_id' => $contactId,
            'channel' => 'whatsapp',
            'sms_id' => null,
            'whatsapp_message_id' => $messageId,
            'asset_id' => null,
            'campaign_id' => null,
            'campaign_event_id' => null,
            'campaign_event_log_id' => null,
            'stat_tracking_hash' => null,
            'source' => 'whatsapp.multisegment',
            'source_id' => $primarySegmentId,
            'recipient' => $eligibility['recipient'],
            'account_id' => $eligibility['account_id'],
            'content' => $content,
            'priority' => 1,
            'available_at' => $availableAt,
            'expires_at' => $expiresAt,
        ]);

        return [
            'accepted' => $result['accepted'],
            'permanent' => false,
            'reason' => $result['accepted']
                ? ''
                : 'queue_rejected',
            'queue_id' => $result['queue_id'],
            'queue_status' => $result['status'],
        ];
    }

    public function enqueueSegmentContact(
        Lead $contact,
        array $message,
        int $segmentId,
        string $operationId,
        ?string $availableAt = null,
        ?string $expiresAt = null
    ): array {
        $eligibility = $this->inspectEligibility($contact);

        if (!$eligibility['eligible']) {
            return [
                'accepted' => false,
                'permanent' => true,
                'reason' => $eligibility['reason'],
                'queue_id' => null,
                'queue_status' => null,
            ];
        }

        $contactId = (int) $contact->getId();
        $messageId = (int) ($message['id'] ?? 0);
        $content = $this->personalize(
            (string) ($message['content'] ?? ''),
            $contact
        );
        $operationId = strtolower(trim($operationId));

        if (
            $contactId < 1
            || $messageId < 1
            || $segmentId < 1
            || 1 !== preg_match('/^[a-f0-9]{32}$/', $operationId)
            || '' === trim($content)
        ) {
            return [
                'accepted' => false,
                'permanent' => false,
                'reason' => 'message_unavailable',
                'queue_id' => null,
                'queue_status' => null,
            ];
        }

        $result = $this->enqueueService->enqueue([
            'dedupe_key' => hash(
                'sha256',
                implode('|', [
                    'whatsapp.segment',
                    $operationId,
                    (string) $segmentId,
                    (string) $contactId,
                    (string) $messageId,
                    $eligibility['recipient'],
                    $eligibility['account_id'],
                ])
            ),
            'contact_id' => $contactId,
            'channel' => 'whatsapp',
            'sms_id' => null,
            'whatsapp_message_id' => $messageId,
            'asset_id' => null,
            'campaign_id' => null,
            'campaign_event_id' => null,
            'campaign_event_log_id' => null,
            'stat_tracking_hash' => null,
            'source' => 'whatsapp.segment',
            'source_id' => $segmentId,
            'recipient' => $eligibility['recipient'],
            'account_id' => $eligibility['account_id'],
            'content' => $content,
            'priority' => 1,
            'available_at' => $availableAt,
            'expires_at' => $expiresAt,
        ]);

        return [
            'accepted' => $result['accepted'],
            'permanent' => false,
            'reason' => $result['accepted']
                ? ''
                : 'queue_rejected',
            'queue_id' => $result['queue_id'],
            'queue_status' => $result['status'],
        ];
    }

    public function enqueueDemo(Lead $contact, array $message): array
    {
        if (DoNotContactEntity::IS_CONTACTABLE !== $this->doNotContact->isContactable($contact, 'whatsapp')) {
            return $this->demoFailure('not_contactable', true);
        }

        $rawNumber = trim((string) $contact->getLeadPhoneNumber());
        if ('' === $rawNumber) {
            return $this->demoFailure('missing_phone', true);
        }

        try {
            $recipient = $this->normalizePhone($rawNumber);
        } catch (NumberParseException) {
            return $this->demoFailure('invalid_phone', true);
        }

        $accountId = trim((string) $contact->getFieldValue('id_whatsapp_in_zender'));
        if ('' === $accountId) {
            return $this->demoFailure('missing_account', true);
        }

        $messageId = (int) ($message['id'] ?? 0);
        $content = $this->personalize((string) ($message['content'] ?? ''), $contact);
        if ((int) $contact->getId() < 1 || $messageId < 1 || '' === trim($content)) {
            return $this->demoFailure('message_unavailable', false);
        }

        $nonce = bin2hex(random_bytes(16));
        $result = $this->enqueueService->enqueue([
            'dedupe_key' => hash('sha256', implode('|', [
                'whatsapp.demo',
                (string) $contact->getId(),
                (string) $messageId,
                $recipient,
                $accountId,
                $nonce,
            ])),
            'contact_id' => (int) $contact->getId(),
            'channel' => 'whatsapp',
            'sms_id' => null,
            'whatsapp_message_id' => $messageId,
            'campaign_id' => null,
            'campaign_event_id' => null,
            'campaign_event_log_id' => null,
            'stat_tracking_hash' => null,
            'source' => 'whatsapp.demo',
            'source_id' => $messageId,
            'recipient' => $recipient,
            'account_id' => $accountId,
            'content' => $content,
            'priority' => 0,
        ]);

        return [
            'accepted' => $result['accepted'],
            'permanent' => false,
            'reason' => $result['accepted'] ? '' : 'queue_rejected',
            'queue_id' => $result['queue_id'],
            'queue_status' => $result['status'],
        ];
    }

    /** @return array{accepted:false,permanent:bool,reason:string,queue_id:null,queue_status:null} */
    private function demoFailure(string $reason, bool $permanent): array
    {
        return [
            'accepted' => false,
            'permanent' => $permanent,
            'reason' => $reason,
            'queue_id' => null,
            'queue_status' => null,
        ];
    }

    /**
     * @return array{
     *     accepted:bool,
     *     permanent:bool,
     *     reason:string,
     *     queue_id:null,
     *     queue_status:null
     * }
     */
    private function permanentFailure(string $reason): array
    {
        return [
            'accepted' => false,
            'permanent' => true,
            'reason' => $reason,
            'queue_id' => null,
            'queue_status' => null,
        ];
    }

    /**
     * @throws NumberParseException
     */
    private function normalizePhone(string $number): string
    {
        $util = PhoneNumberUtil::getInstance();
        $parsed = $util->parse($number, null);

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    private function personalize(string $content, Lead $contact): string
    {
        // Preserve the first-slice legacy tokens for existing saved messages.
        $content = strtr($content, [
            '{contact_title}' => (string) $contact->getTitle(),
            '{contact_firstname}' => (string) $contact->getFirstname(),
            '{contact_lastname}' => (string) $contact->getLastname(),
            '{contact_name}' => (string) $contact->getName(),
            '{contact_company}' => (string) $contact->getCompany(),
            '{contact_email}' => (string) $contact->getEmail(),
            '{contact_address1}' => (string) $contact->getAddress1(),
            '{contact_address2}' => (string) $contact->getAddress2(),
            '{contact_city}' => (string) $contact->getCity(),
            '{contact_state}' => (string) $contact->getState(),
            '{contact_country}' => (string) $contact->getCountry(),
            '{contact_zipcode}' => (string) $contact->getZipcode(),
            '{contact_location}' => (string) $contact->getLocation(),
            '{contact_phone}' => ltrim(
                (string) $contact->getLeadPhoneNumber(),
                '+'
            ),
            '{contact_id_whatsapp_in_zender}' => (string) (
                $contact->getFieldValue('id_whatsapp_in_zender')
            ),
        ]);

        $profileFields = array_merge(
            [
                'title' => (string) $contact->getTitle(),
                'firstname' => (string) $contact->getFirstname(),
                'lastname' => (string) $contact->getLastname(),
                'company' => (string) $contact->getCompany(),
                'position' => (string) $contact->getPosition(),
                'email' => (string) $contact->getEmail(),
                'phone' => (string) $contact->getPhone(),
                'mobile' => (string) $contact->getMobile(),
                'address1' => (string) $contact->getAddress1(),
                'address2' => (string) $contact->getAddress2(),
                'city' => (string) $contact->getCity(),
                'state' => (string) $contact->getState(),
                'zipcode' => (string) $contact->getZipcode(),
                'country' => (string) $contact->getCountry(),
            ],
            $contact->getProfileFields()
        );

        return (string) TokenHelper::findLeadTokens(
            $content,
            $profileFields,
            true
        );
    }
}
