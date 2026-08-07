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
    }
}
