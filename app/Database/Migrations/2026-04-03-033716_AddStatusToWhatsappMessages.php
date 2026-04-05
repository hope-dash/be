<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddStatusToWhatsappMessages extends Migration
{
    public function up()
    {
        $this->forge->addColumn('whatsapp_messages', [
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'pending',
                'after' => 'session_id',
            ],
        ]);
        $this->forge->addKey(['status']);
    }

    public function down()
    {
        $this->forge->dropColumn('whatsapp_messages', 'status');
    }
}
