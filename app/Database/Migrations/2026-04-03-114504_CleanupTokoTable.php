<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CleanupTokoTable extends Migration
{
    public function up()
    {
        $colsToDrop = [
            'chat_session_id',
            'chat_session_status',
            'tiktok_code',
            'tiktok_shop_cipher',
            'tiktok_access_token',
            'tiktok_refresh_token'
        ];

        foreach ($colsToDrop as $col) {
            if ($this->db->fieldExists($col, 'toko')) {
                $this->forge->dropColumn('toko', $col);
            }
        }
    }

    public function down()
    {
        $this->forge->addColumn('toko', [
            'chat_session_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true
            ],
            'chat_session_status' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true
            ],
            'tiktok_code' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true
            ],
            'tiktok_shop_cipher' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true
            ],
            'tiktok_access_token' => [
                'type' => 'TEXT',
                'null' => true
            ],
            'tiktok_refresh_token' => [
                'type' => 'TEXT',
                'null' => true
            ]
        ]);
    }
}
