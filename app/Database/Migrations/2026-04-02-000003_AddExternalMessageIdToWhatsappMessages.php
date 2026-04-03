<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddExternalMessageIdToWhatsappMessages extends Migration
{
    public function up()
    {
        // Add external_message_id, quoted_message_id, and quoted_text fields
        $fields = [
            'external_message_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'media_mime',
            ],
            'quoted_message_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'external_message_id',
            ],
            'quoted_text' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'quoted_message_id',
            ],
        ];

        $this->forge->addColumn('whatsapp_messages', $fields);

        // Add index for external_message_id for faster lookups
        $this->db->query('ALTER TABLE whatsapp_messages ADD INDEX idx_external_message_id (external_message_id)');
    }

    public function down()
    {
        $this->forge->dropColumn('whatsapp_messages', ['external_message_id', 'quoted_message_id', 'quoted_text']);
    }
}
