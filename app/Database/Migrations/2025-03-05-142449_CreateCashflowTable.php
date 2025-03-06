<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCashflowTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 5,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'debit' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'null' => false,
            ],
            'credit' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'null' => false,
            ],
            'noted' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'type' => [
                'type' => 'VARCHAR',
                'constraint' => '50',
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => '50',
            ],
            'date_time' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'id_toko' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('cashflow');
    }

    public function down()
    {
        $this->forge->dropTable('cashflow');
    }
}
