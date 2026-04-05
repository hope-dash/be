<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddChatSessionToToko extends Migration
{
    public function up()
    {
        // Add chat_session_id column to toko table
        $this->forge->addColumn('toko', [
            'chat_session_id' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'comment' => 'WhatsApp session ID from external chat service',
            ],
            'chat_session_status' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'default' => 'disconnected',
                'comment' => 'Session status: disconnected, qr_ready, connecting, ready',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('toko', ['chat_session_id', 'chat_session_status']);
    }
}
