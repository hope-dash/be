<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedExpenseAccounts extends Migration
{
    public function up()
    {
        $data = [
            ['code' => '6001', 'name' => 'Salaries Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['code' => '6002', 'name' => 'Rent Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['code' => '6003', 'name' => 'Electricity Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['code' => '6004', 'name' => 'Marketing & Admin Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['code' => '6005', 'name' => 'Operational Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT']
        ];

        // Insert using Query Builder to avoid model issues during migration
        $this->db->table('accounts')->insertBatch($data);
    }

    public function down()
    {
        $this->db->table('accounts')
             ->whereIn('code', ['6001', '6002', '6003', '6004', '6005'])
             ->delete();
    }
}
