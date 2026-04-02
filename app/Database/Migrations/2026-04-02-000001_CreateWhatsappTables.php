<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWhatsappTables extends Migration
{
    public function up()
    {
        // whatsapp_chats
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'null' => true,
            ],
            'phone' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
            ],
            'display_name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
            'last_message_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_message_snippet' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'unread_count' => [
                'type' => 'INT',
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'phone']);
        $this->forge->createTable('whatsapp_chats');

        // whatsapp_messages
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'null' => true,
            ],
            'chat_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
            ],
            'direction' => [
                'type' => 'ENUM',
                'constraint' => ['in', 'out'],
                'default' => 'in',
            ],
            'message_type' => [
                'type' => 'ENUM',
                'constraint' => ['text', 'image', 'document', 'other'],
                'default' => 'text',
            ],
            'text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'media_path' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'media_mime' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
            'received_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'chat_id']);
        $this->forge->createTable('whatsapp_messages');

        // whatsapp_labels
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'null' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'color' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'name']);
        $this->forge->createTable('whatsapp_labels');

        // whatsapp_chat_labels
        $this->forge->addField([
            'tenant_id' => [
                'type' => 'INT',
                'null' => true,
            ],
            'chat_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
            ],
            'label_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
        ]);
        $this->forge->addKey(['chat_id', 'label_id'], true);
        $this->forge->addKey(['tenant_id']);
        $this->forge->createTable('whatsapp_chat_labels');
    }

    public function down()
    {
        $this->forge->dropTable('whatsapp_chat_labels', true);
        $this->forge->dropTable('whatsapp_labels', true);
        $this->forge->dropTable('whatsapp_messages', true);
        $this->forge->dropTable('whatsapp_chats', true);
    }
}
