<?php

namespace MauticPlugin\MauticZenderBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\AbstractMauticMigration;

class Version0002 extends AbstractMauticMigration
{
    public function up(Schema $schema)
    {
        // Don't proceed if the `zender_api_request_log` table already exists
        if ($schema->hasTable($this->prefix . 'zender_api_request_log')) {
            $table = $schema->getTable($this->prefix . 'zender_api_request_log');
            if (!$table->hasColumn('message_type')) {
                $table->addColumn('message_type', 'string', ['length' => 50, 'notnull' => true]);
            }
            if (!$table->hasColumn('processed_at')) {
                $table->addColumn('processed_at', 'datetime', ['notnull' => false, 'default' => null]);
            }
            return;
        }

        // Create the table
        $table = $schema->createTable($this->prefix . 'zender_api_request_log');

        // Add the columns
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('requested_at', 'datetime', ['notnull' => true]);
        $table->addColumn('first_message_at', 'datetime', ['notnull' => false]);
        $table->addColumn('last_message_at', 'datetime', ['notnull' => false]);
        $table->addColumn('response_data', 'text', ['notnull' => true]);
        $table->addColumn('status', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('message_type', 'string', ['length' => 50, 'notnull' => true]);
        $table->addColumn('processed_at', 'datetime', ['notnull' => false, 'default' => null]);

        // Add the primary key
        $table->setPrimaryKey(['id']);
    }
}
