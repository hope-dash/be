<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTiktokChatTables extends Migration
{
    public function up()
    {
        // 1. tiktok_chats table
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'id_toko' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'shop_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'conversation_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'participant_id' => [ // The IM User ID of the buyer
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'participant_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'participant_avatar' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'unread_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'last_message' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'last_message_time' => [
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
        $this->forge->addKey('id_toko');
        $this->forge->addKey('conversation_id');
        $this->forge->createTable('tiktok_chats', true);

        // 2. tiktok_messages table
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tiktok_chat_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'       => true,
            ],
            'message_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'sender_role' => [
                'type'       => 'VARCHAR', // BUYER or SELLER
                'constraint' => '50',
                'null'       => true,
            ],
            'type' => [
                'type'       => 'VARCHAR', // TEXT, IMAGE, etc
                'constraint' => '50',
                'null'       => true,
            ],
            'content' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'is_read' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'create_time' => [
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
        $this->forge->addKey('tiktok_chat_id');
        $this->forge->addKey('message_id');
        
        $this->forge->addForeignKey('tiktok_chat_id', 'tiktok_chats', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('tiktok_messages', true);
    }

    public function down()
    {
        $this->forge->dropTable('tiktok_messages', true);
        $this->forge->dropTable('tiktok_chats', true);
    }
}
