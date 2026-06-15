<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTiktokFieldsToStock extends Migration
{
    public function up()
    {
        $fields = [
            'tiktok_product_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'dropship'
            ],
            'product_tiktok_status' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'tiktok_product_id'
            ],
        ];

        $this->forge->addColumn('stock', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('stock', [
            'tiktok_product_id',
            'product_tiktok_status'
        ]);
    }
}
