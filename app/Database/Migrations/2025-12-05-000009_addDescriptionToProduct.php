<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDescriptionToProduct extends Migration
{
    public function up()
    {
        $fields = [
            'description' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'nama_barang'
            ],
        ];
        $this->forge->addColumn('product', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('product', 'description');
    }
}
