<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedExpenseAccounts extends Migration
{
    public function up()
    {
        $data = [
            ['code' => '5002', 'name' => 'Salaries Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['code' => '5003', 'name' => 'Rent Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['code' => '5004', 'name' => 'Electricity Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['code' => '5005', 'name' => 'Marketing & Admin Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['code' => '5006', 'name' => 'Operational Expense', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT']
        ];

        // Insert using Query Builder to avoid model issues during migration
        $this->db->table('accounts')->insertBatch($data);
    }

    public function down()
    {
        $this->db->table('accounts')
            ->whereIn('code', ['5002', '5003', '5004', '5005', '5006'])
            ->delete();
    }
}
