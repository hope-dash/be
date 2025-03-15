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
                'constraint' => '10',
            ],
            'nama_barang' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
            ],
            'id_model_barang' => [
                'type' => 'INT',
                'constraint' => 3,
            ],
            'id_seri_barang' => [
                'type' => 'INT',
                'constraint' => 3,
                'null' => true,
            ],
            'harga_modal' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'harga_jual' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'suplier' => [
                'type' => 'INT',
                'constraint' => 3,
            ],
            'notes' => [
                'type' => 'TEXT'
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
             'created_by' => [
               'type' => 'VARCHAR',
                'constraint' => '3',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
             'updated_by' => [
               'type' => 'VARCHAR',
                'constraint' => '3',
                'null' => true,
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
