<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSalesProductTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'auto_increment' => true,
            ],
            'id_transaction' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'kode_barang' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
            ],
            'jumlah' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'harga_system' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'harga_jual' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'total' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'modal_system' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'total_modal' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'actual_per_piece' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'actual_total' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('sales_product');
    }

    public function down()
    {
        $this->forge->dropTable('sales_product');
    }
}
