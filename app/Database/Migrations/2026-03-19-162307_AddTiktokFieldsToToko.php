<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTiktokFieldsToToko extends Migration
{
    public function up()
    {
        $fields = [
            'tiktok_access_token' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true,
                'after' => 'type'
            ],
            'tiktok_refresh_token' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true,
                'after' => 'type'
            ],
            'tiktok_code' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true,
                'after' => 'type'
            ],
            'tiktok_shop_cipher' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'tiktok_code'
            ],
        ];
        $this->forge->addColumn('toko', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('toko', ['tiktok_code', 'tiktok_shop_cipher']);
    }
}