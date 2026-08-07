<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

final class Version0008 extends AbstractMigration
{
    private const TABLE = 'zender_ai_settings';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable(
            $this->concatPrefix(self::TABLE)
        );
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::TABLE);

        $this->addSql(
            "CREATE TABLE `{$table}` (
              `id` smallint(5) unsigned NOT NULL,
              `active_provider` varchar(32) DEFAULT NULL,
              `active_model` varchar(191) DEFAULT NULL,
              `openai_api_key_encrypted` longtext DEFAULT NULL,
              `anthropic_api_key_encrypted` longtext DEFAULT NULL,
              `grok_api_key_encrypted` longtext DEFAULT NULL,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        $this->addSql(
            "INSERT INTO `{$table}` (
              `id`,
              `active_provider`,
              `active_model`,
              `created_at`,
              `updated_at`
            ) VALUES (
              1,
              NULL,
              NULL,
              UTC_TIMESTAMP(),
              UTC_TIMESTAMP()
            )"
        );
    }
}
