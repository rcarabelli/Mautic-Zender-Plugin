<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

final class Version0007 extends AbstractMigration
{
    private const TABLE = 'zender_dispatch_queue';
    private const COLUMN = 'asset_id';

    protected function isApplicable(Schema $schema): bool
    {
        $table = $this->concatPrefix(self::TABLE);

        return $schema->hasTable($table)
            && !$schema->getTable($table)->hasColumn(self::COLUMN);
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::TABLE);

        $this->addSql(
            "ALTER TABLE `{$table}`
             ADD `asset_id` bigint(20) unsigned DEFAULT NULL
             AFTER `whatsapp_message_id`"
        );
    }
}
