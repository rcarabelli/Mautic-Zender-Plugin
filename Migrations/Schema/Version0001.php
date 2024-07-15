<?php

namespace MauticPlugin\MauticZenderBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\AbstractMauticMigration;

class Version0001 extends AbstractMauticMigration
{
    public function up(Schema $schema)
    {
        // Don't proceed if the `leads` table doesn't exist
        if (!$schema->hasTable($this->prefix . 'leads')) {
            return;
        }

        $table = $schema->getTable($this->prefix . 'leads');
        
        // Add the column if it doesn't exist
        if (!$table->hasColumn('id_whatsapp_in_zender')) {
            $table->addColumn('id_whatsapp_in_zender', 'string', ['length' => 191, 'notnull' => false, 'default' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        }

        if (!$table->hasColumn('last_sent_message_date')) {
            $table->addColumn('last_sent_message_date', 'datetime', ['notnull' => false, 'default' => null]);
        }

        if (!$table->hasColumn('last_sent_message_status')) {
            $table->addColumn('last_sent_message_status', 'string', ['length' => 191, 'notnull' => false, 'default' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        }

        if (!$table->hasColumn('last_sent_message_content')) {
            $table->addColumn('last_sent_message_content', 'text', ['notnull' => false, 'default' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        } else {
            $table->changeColumn('last_sent_message_content', ['type' => 'text', 'notnull' => false, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        }

        if (!$table->hasColumn('last_received_message_date')) {
            $table->addColumn('last_received_message_date', 'datetime', ['notnull' => false, 'default' => null]);
        }

        if (!$table->hasColumn('last_received_message_status')) {
            $table->addColumn('last_received_message_status', 'string', ['length' => 191, 'notnull' => false, 'default' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        }

        if (!$table->hasColumn('last_received_message_content')) {
            $table->addColumn('last_received_message_content', 'text', ['notnull' => false, 'default' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        } else {
            $table->changeColumn('last_received_message_content', ['type' => 'text', 'notnull' => false, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        }
    }
}
