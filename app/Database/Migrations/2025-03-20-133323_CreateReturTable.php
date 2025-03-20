<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReturTable extends Migration
{
    public function up()
    {
        // Create the 'retur' table
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'transaction_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'kode_barang' => [
                'type' => 'VARCHAR',
                'constraint' => '50',
            ],
            'barang_cacat' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],
            'jumlah' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'solution' => [
                'type' => 'ENUM',
                'constraint' => ['refund', 'exchange'],
            ],
        ]);

        // Add primary key
        $this->forge->addPrimaryKey('id');

        // Create the table
        $this->forge->createTable('retur');
    }

    public function down()
    {
        // Drop the 'retur' table
        $this->forge->dropTable('retur');
    }
}
