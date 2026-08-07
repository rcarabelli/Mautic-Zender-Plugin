<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

final class Version0004 extends AbstractMigration
{
    private const MESSAGE_TABLE = 'zender_whatsapp_messages';
    private const QUEUE_TABLE = 'zender_dispatch_queue';

    protected function isApplicable(Schema $schema): bool
    {
        $messageTable = $this->concatPrefix(self::MESSAGE_TABLE);
        $queueTable = $this->concatPrefix(self::QUEUE_TABLE);

        if (!$schema->hasTable($messageTable)) {
            return true;
        }

        if (!$schema->hasTable($queueTable)) {
            return false;
        }

        $queue = $schema->getTable($queueTable);

        foreach ([
            'channel',
            'whatsapp_message_id',
            'campaign_id',
            'campaign_event_id',
            'campaign_event_log_id',
        ] as $column) {
            if (!$queue->hasColumn($column)) {
                return true;
            }
        }

        return false;
    }

    protected function up(): void
    {
        $messageTable = $this->concatPrefix(self::MESSAGE_TABLE);
        $queueTable = $this->concatPrefix(self::QUEUE_TABLE);

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS `{$messageTable}` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(191) NOT NULL,
              `content` longtext NOT NULL,
              `is_published` tinyint(1) NOT NULL DEFAULT 1,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_zender_whatsapp_published_name`
                  (`is_published`,`name`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        $this->addSql(
            "ALTER TABLE `{$queueTable}`
             ADD COLUMN IF NOT EXISTS `channel`
                 varchar(32) NOT NULL DEFAULT 'sms'
                 AFTER `contact_id`,
             ADD COLUMN IF NOT EXISTS `whatsapp_message_id`
                 bigint(20) unsigned DEFAULT NULL
                 AFTER `sms_id`,
             ADD COLUMN IF NOT EXISTS `campaign_id`
                 int(10) unsigned DEFAULT NULL
                 AFTER `whatsapp_message_id`,
             ADD COLUMN IF NOT EXISTS `campaign_event_id`
                 int(10) unsigned DEFAULT NULL
                 AFTER `campaign_id`,
             ADD COLUMN IF NOT EXISTS `campaign_event_log_id`
                 bigint(20) unsigned DEFAULT NULL
                 AFTER `campaign_event_id`"
        );

        $this->addSql(
            "ALTER TABLE `{$queueTable}`
             ADD INDEX IF NOT EXISTS `idx_zender_queue_channel_contact`
                 (`channel`,`contact_id`,`queued_at`),
             ADD INDEX IF NOT EXISTS `idx_zender_queue_whatsapp_message`
                 (`whatsapp_message_id`,`queued_at`),
             ADD UNIQUE INDEX IF NOT EXISTS
                 `uniq_zender_queue_campaign_log_channel`
                 (`campaign_event_log_id`,`channel`)"
        );
    }
}
