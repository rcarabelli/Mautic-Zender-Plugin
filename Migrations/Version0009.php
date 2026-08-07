<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

final class Version0009 extends AbstractMigration
{
    private const TABLE = 'zender_whatsapp_messages';
    private const COLUMN = 'delivery_destination';

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
             ADD `delivery_destination` varchar(16)
                 NOT NULL DEFAULT 'campaign'
                 AFTER `asset_id`"
        );
    }
}
