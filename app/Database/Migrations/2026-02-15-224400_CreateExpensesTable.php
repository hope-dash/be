<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExpensesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_toko' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'account_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'amount' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
            ],
            'payment_method' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'date' => [
                'type' => 'DATE',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('expenses');
    }

    public function down()
    {
        $this->forge->dropTable('expenses');
    }
}
