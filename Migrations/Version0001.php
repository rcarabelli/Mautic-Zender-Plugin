<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

final class Version0001 extends AbstractMigration
{
    private const CONFIG_TABLE = 'zender_dispatch_config';
    private const ACCOUNT_TABLE = 'zender_dispatch_accounts';
    private const QUEUE_TABLE = 'zender_dispatch_queue';
    private const ATTEMPT_TABLE = 'zender_dispatch_attempts';
    private const RECEIVED_TABLE = 'zender_received_chats';

    protected function isApplicable(Schema $schema): bool
    {
        foreach ([
            self::CONFIG_TABLE,
            self::ACCOUNT_TABLE,
            self::QUEUE_TABLE,
            self::ATTEMPT_TABLE,
            self::RECEIVED_TABLE,
        ] as $table) {
            if (!$schema->hasTable($this->concatPrefix($table))) {
                return true;
            }
        }

        return false;
    }

    protected function up(): void
    {
        $configTable = $this->concatPrefix(self::CONFIG_TABLE);
        $accountTable = $this->concatPrefix(self::ACCOUNT_TABLE);
        $queueTable = $this->concatPrefix(self::QUEUE_TABLE);
        $attemptTable = $this->concatPrefix(self::ATTEMPT_TABLE);
        $receivedTable = $this->concatPrefix(self::RECEIVED_TABLE);

        $accountIndexAccount = $this->concatPrefix(
            'zender_dispatch_accounts_account'
        );
        $accountIndexOrder = $this->concatPrefix(
            'zender_dispatch_accounts_order'
        );
        $accountIndexEnabled = $this->concatPrefix(
            'zender_dispatch_accounts_enabled'
        );
        $accountIndexCooldown = $this->concatPrefix(
            'zender_dispatch_accounts_cooldown'
        );

        $queueIndexDedupe = $this->concatPrefix(
            'zender_dispatch_dedupe'
        );
        $queueIndexActive = $this->concatPrefix(
            'zender_dispatch_one_active_id'
        );
        $queueIndexPending = $this->concatPrefix(
            'zender_dispatch_pending'
        );
        $queueIndexAccount = $this->concatPrefix(
            'zender_dispatch_account'
        );
        $queueIndexDate = $this->concatPrefix(
            'zender_dispatch_date'
        );

        $attemptIndexNumber = $this->concatPrefix(
            'zender_attempt_queue_number'
        );
        $attemptIndexStarted = $this->concatPrefix(
            'zender_attempt_queue_started'
        );
        $attemptIndexOutcome = $this->concatPrefix(
            'zender_attempt_outcome_started'
        );
        $attemptIndexRetry = $this->concatPrefix(
            'zender_attempt_retry_schedule'
        );
        $attemptForeignKey = $this->concatPrefix(
            'zender_attempt_queue_fk'
        );

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS `{$configTable}` (
              `id` smallint(5) unsigned NOT NULL,
              `enabled` tinyint(1) NOT NULL DEFAULT 0,
              `timezone` varchar(64) NOT NULL DEFAULT 'America/Lima',
              `window_start` time NOT NULL DEFAULT '08:00:00',
              `window_end` time NOT NULL DEFAULT '21:00:00',
              `global_daily_limit` int(10) unsigned NOT NULL DEFAULT 1400,
              `per_account_daily_limit` int(10) unsigned NOT NULL DEFAULT 280,
              `batch_size` int(10) unsigned NOT NULL DEFAULT 100,
              `dispatch_interval_seconds` int(10) unsigned NOT NULL DEFAULT 165,
              `max_attempts` smallint(5) unsigned NOT NULL DEFAULT 3,
              `retry_delay_seconds` int(10) unsigned NOT NULL DEFAULT 900,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        $this->addSql(
            "INSERT IGNORE INTO `{$configTable}` (
              `id`,
              `enabled`,
              `timezone`,
              `window_start`,
              `window_end`,
              `global_daily_limit`,
              `per_account_daily_limit`,
              `batch_size`,
              `dispatch_interval_seconds`,
              `max_attempts`,
              `retry_delay_seconds`,
              `created_at`,
              `updated_at`
            ) VALUES (
              1,
              0,
              'America/Lima',
              '08:00:00',
              '21:00:00',
              1400,
              280,
              5,
              165,
              1,
              900,
              UTC_TIMESTAMP(),
              UTC_TIMESTAMP()
            )"
        );

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS `{$accountTable}` (
              `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
              `account_id` varchar(191) NOT NULL,
              `label` varchar(191) NOT NULL,
              `phone` varchar(32) NOT NULL,
              `dispatch_order` smallint(5) unsigned NOT NULL,
              `daily_limit` int(10) unsigned NOT NULL DEFAULT 280,
              `daily_limit_override` int(10) unsigned DEFAULT NULL,
              `enabled` tinyint(1) NOT NULL DEFAULT 1,
              `last_attempt_started_at` datetime DEFAULT NULL,
              `next_eligible_at` datetime DEFAULT NULL,
              `paused_until` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `{$accountIndexAccount}` (`account_id`),
              UNIQUE KEY `{$accountIndexOrder}` (`dispatch_order`),
              KEY `{$accountIndexEnabled}` (`enabled`,`dispatch_order`),
              KEY `{$accountIndexCooldown}`
                (`enabled`,`next_eligible_at`,`dispatch_order`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS `{$queueTable}` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `dedupe_key` char(64) NOT NULL,
              `contact_id` bigint(20) unsigned NOT NULL,
              `channel` varchar(32) NOT NULL DEFAULT 'sms',
              `sms_id` int(10) unsigned DEFAULT NULL,
              `whatsapp_message_id` bigint(20) unsigned DEFAULT NULL,
              `asset_id` bigint(20) unsigned DEFAULT NULL,
              `campaign_id` int(10) unsigned DEFAULT NULL,
              `campaign_event_id` int(10) unsigned DEFAULT NULL,
              `campaign_event_log_id` bigint(20) unsigned DEFAULT NULL,
              `stat_tracking_hash` varchar(191) DEFAULT NULL,
              `source` varchar(191) DEFAULT NULL,
              `source_id` int(11) DEFAULT NULL,
              `recipient` varchar(64) NOT NULL,
              `account_id` varchar(191) NOT NULL,
              `content` longtext NOT NULL,
              `status` varchar(32) NOT NULL DEFAULT 'pending',
              `priority` smallint(6) NOT NULL DEFAULT 2,
              `attempts` smallint(6) NOT NULL DEFAULT 0,
              `queued_at` datetime NOT NULL,
              `available_at` datetime NOT NULL,
              `expires_at` datetime DEFAULT NULL,
              `claimed_at` datetime DEFAULT NULL,
              `dispatch_claim_key` char(64)
                CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
              `dispatched_at` datetime DEFAULT NULL,
              `last_error` longtext DEFAULT NULL,
              `provider_status` varchar(191) DEFAULT NULL,
              `provider_message_id` varchar(191) DEFAULT NULL,
              `provider_accepted_at` datetime DEFAULT NULL,
              `provider_status_observed_at` datetime DEFAULT NULL,
              `provider_status_response_sha256` char(64) DEFAULT NULL,
              `provider_response_sha256` char(64) DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `{$queueIndexDedupe}` (`dedupe_key`),
              UNIQUE KEY `{$queueIndexActive}` (`dispatch_claim_key`),
              UNIQUE KEY `uniq_zender_queue_campaign_log_channel`
                (`campaign_event_log_id`,`channel`),
              KEY `{$queueIndexPending}` (`status`,`available_at`),
              KEY `{$queueIndexAccount}` (`account_id`,`status`),
              KEY `{$queueIndexDate}` (`dispatched_at`),
              KEY `idx_zender_queue_channel_contact`
                (`channel`,`contact_id`,`queued_at`),
              KEY `idx_zender_queue_whatsapp_message`
                (`whatsapp_message_id`,`queued_at`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS `{$attemptTable}` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `queue_id` bigint(20) unsigned NOT NULL,
              `attempt_number` smallint(5) unsigned NOT NULL,
              `outcome_classification` varchar(64)
                NOT NULL DEFAULT 'started',
              `provider_request_started` tinyint(1)
                NOT NULL DEFAULT 0,
              `started_at` datetime NOT NULL,
              `provider_request_started_at` datetime DEFAULT NULL,
              `finished_at` datetime DEFAULT NULL,
              `duration_ms` int(10) unsigned DEFAULT NULL,
              `http_status` smallint(5) unsigned DEFAULT NULL,
              `provider_message_id` varchar(191) DEFAULT NULL,
              `provider_response_sha256` char(64)
                CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
              `error_fingerprint` char(64)
                CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
              `retry_decision` varchar(32)
                NOT NULL DEFAULT 'not_evaluated',
              `retry_scheduled_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `{$attemptIndexNumber}`
                (`queue_id`,`attempt_number`),
              KEY `{$attemptIndexStarted}` (`queue_id`,`started_at`),
              KEY `{$attemptIndexOutcome}`
                (`outcome_classification`,`started_at`),
              KEY `{$attemptIndexRetry}`
                (`retry_decision`,`retry_scheduled_at`),
              CONSTRAINT `{$attemptForeignKey}`
                FOREIGN KEY (`queue_id`)
                REFERENCES `{$queueTable}` (`id`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS `{$receivedTable}` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `provider_received_id` bigint(20) unsigned DEFAULT NULL,
              `dedupe_key` char(64) NOT NULL,
              `account_identifier` varchar(191) NOT NULL,
              `account_phone_normalized` varchar(32) DEFAULT NULL,
              `sender_phone_normalized` varchar(32) DEFAULT NULL,
              `contact_id` bigint(20) unsigned DEFAULT NULL,
              `contact_match_state` varchar(32) NOT NULL,
              `provider_created_epoch` bigint(20) unsigned NOT NULL,
              `provider_created_at` datetime NOT NULL,
              `message_type` varchar(32) NOT NULL DEFAULT 'text',
              `message_body` longtext NOT NULL,
              `message_sha256` char(64) NOT NULL,
              `has_attachment` tinyint(1) NOT NULL DEFAULT 0,
              `attachment_metadata_json` longtext DEFAULT NULL,
              `provider_payload_sha256` char(64) NOT NULL,
              `imported_at` datetime NOT NULL,
              `last_seen_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_zender_received_provider_id`
                (`provider_received_id`),
              UNIQUE KEY `uniq_zender_received_dedupe` (`dedupe_key`),
              KEY `idx_zender_received_contact_created`
                (`contact_id`,`provider_created_at`),
              KEY `idx_zender_received_match_created`
                (`contact_match_state`,`provider_created_at`),
              KEY `idx_zender_received_account_created`
                (`account_identifier`,`provider_created_at`),
              KEY `idx_zender_received_created` (`provider_created_at`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
    }
}
