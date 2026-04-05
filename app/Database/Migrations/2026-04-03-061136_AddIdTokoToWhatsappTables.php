<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIdTokoToWhatsappTables extends Migration
{
    public function up()
    {
        // Add id_toko to whatsapp_chats
        $this->forge->addColumn('whatsapp_chats', [
            'id_toko' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'after' => 'tenant_id',
            ],
        ]);
        $this->forge->addKey(['id_toko']);

        // Add id_toko to whatsapp_messages
        $this->forge->addColumn('whatsapp_messages', [
            'id_toko' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'after' => 'tenant_id',
            ],
        ]);
        $this->forge->addKey(['id_toko']);
        
        // Execute the indexing
        $this->forge->processIndexes('whatsapp_chats');
        $this->forge->processIndexes('whatsapp_messages');
    }

    public function down()
    {
        $this->forge->dropColumn('whatsapp_chats', 'id_toko');
        $this->forge->dropColumn('whatsapp_messages', 'id_toko');
    }
}
