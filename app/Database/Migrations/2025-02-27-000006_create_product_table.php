<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 5,
                'auto_increment' => true,
            ],
            'id_barang' => [
                'type' => 'VARCHAR',
                'constraint' => '5',
            ],
            'nama_barang' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
            ],
            'id_seri_barang' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'harga_modal' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'harga_jual' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('product');
    }

    public function down()
    {
        $this->forge->dropTable('product');
    }
}
