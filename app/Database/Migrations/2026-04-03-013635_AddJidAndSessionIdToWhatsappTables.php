<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddJidAndSessionIdToWhatsappTables extends Migration
{
    public function up()
    {
        // Add columns to whatsapp_chats
        $this->forge->addColumn('whatsapp_chats', [
            'jid' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'phone',
            ],
            'session_id' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'jid',
            ],
        ]);
        $this->forge->addKey(['jid']);
        $this->forge->addKey(['session_id']);

        // Add columns to whatsapp_messages
        $this->forge->addColumn('whatsapp_messages', [
            'session_id' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'external_message_id',
            ],
            'from_me' => [
                'type' => 'BOOLEAN',
                'default' => false,
                'after' => 'direction',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('whatsapp_chats', ['jid', 'session_id']);
        $this->forge->dropColumn('whatsapp_messages', ['session_id', 'from_me']);
    }
}
