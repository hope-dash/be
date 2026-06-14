<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTiktokFieldsToProduct extends Migration
{
    public function up()
    {
        $fields = [
            'tiktok_product_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'berat'
            ],
            'tiktok_sku' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'tiktok_product_id'
            ],
            'tiktok_category_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'tiktok_sku'
            ],
            'tiktok_meta' => [
                'type'       => 'TEXT',
                'null'       => true,
                'after'      => 'tiktok_category_id'
            ],
            'package_length' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 10,
                'after'      => 'tiktok_meta'
            ],
            'package_width' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 10,
                'after'      => 'package_length'
            ],
            'package_height' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 10,
                'after'      => 'package_width'
            ],
        ];

        $this->forge->addColumn('product', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('product', [
            'tiktok_product_id',
            'tiktok_sku',
            'tiktok_category_id',
            'tiktok_meta',
            'package_length',
            'package_width',
            'package_height'
        ]);
    }
}
