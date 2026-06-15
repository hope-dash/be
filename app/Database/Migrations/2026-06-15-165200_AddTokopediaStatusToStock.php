<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTokopediaStatusToStock extends Migration
{
    public function up()
    {
        $fields = [
            'product_tokopedia_status' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'product_tiktok_status'
            ],
        ];

        $this->forge->addColumn('stock', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('stock', [
            'product_tokopedia_status'
        ]);
    }
}
