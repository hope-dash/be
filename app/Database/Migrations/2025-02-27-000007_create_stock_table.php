<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStockTable extends Migration
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
                'constraint' => 5,
            ],
            'id_toko' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'stock' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'barang_cacat' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('stock');
    }

    public function down()
    {
        $this->forge->dropTable('stock');
    }
}
