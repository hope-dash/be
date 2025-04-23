<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDropshipToStock extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stock', [
            'dropship' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
                'null'       => false,
                'after'      => 'barang_cacat', 
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('stock', 'dropship');
    }
}
