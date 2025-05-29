<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CombinedPembelianUpdates extends Migration
{
    public function up()
    {
        $pembelian_fields = [
            'status' => [
                'type' => "ENUM('REVIEW','CANCEL','SUCCESS')",
                'null' => false,
                'default' => 'REVIEW',
                'after' => 'catatan'
            ]
        ];
        $this->forge->addColumn('pembelian', $pembelian_fields);


        $pembelian_detail_fields = [
            'ongkir' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'null' => true,
                'default' => 0.00,
                'after' => 'harga_satuan'
            ]
        ];
        $this->forge->addColumn('pembelian_detail', $pembelian_detail_fields);
    }

    public function down()
    {
        $this->forge->dropColumn('pembelian_detail', 'ongkir');

        $this->forge->dropColumn('pembelian', 'status');
    }
}
