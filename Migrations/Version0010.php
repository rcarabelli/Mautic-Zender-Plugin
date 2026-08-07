<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

final class Version0010 extends AbstractMigration
{
    private const TABLE = 'zender_dispatch_queue';
    private const COLUMN = 'expires_at';

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
             ADD `expires_at` datetime DEFAULT NULL
             AFTER `available_at`"
        );
    }
}
