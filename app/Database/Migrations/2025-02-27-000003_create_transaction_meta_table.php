<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTransactionMetaTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'auto_increment' => true,
            ],
            'transaction_id' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'key' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
            ],
            'value' => [
                'type' => 'TEXT',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('transaction_meta');
    }

    public function down()
    {
        $this->forge->dropTable('transaction_meta');
    }
}
