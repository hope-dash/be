<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAccountFieldsToToko extends Migration
{
    public function up()
    {
        $this->forge->addColumn('toko', [
            'bank_account_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'id'
            ],
            'cash_account_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'bank_account_id'
            ]
        ]);

        // Add foreign keys
        $this->forge->addForeignKey('bank_account_id', 'accounts', 'id', 'SET NULL', 'CASCADE', 'toko_bank_account_fk');
        $this->forge->addForeignKey('cash_account_id', 'accounts', 'id', 'SET NULL', 'CASCADE', 'toko_cash_account_fk');
    }

    public function down()
    {
        // Drop foreign keys first
        $this->forge->dropForeignKey('toko', 'toko_bank_account_fk');
        $this->forge->dropForeignKey('toko', 'toko_cash_account_fk');

        // Drop columns
        $this->forge->dropColumn('toko', ['bank_account_id', 'cash_account_id']);
    }
}
