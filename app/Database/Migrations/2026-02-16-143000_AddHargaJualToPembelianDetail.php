<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddHargaJualToPembelianDetail extends Migration
{
    public function up()
    {
        $this->forge->addColumn('pembelian_detail', [
            'harga_jual' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'default' => 0,
                'after' => 'harga_satuan' // Logical placement
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('pembelian_detail', 'harga_jual');
    }
}
