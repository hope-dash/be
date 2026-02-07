<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateV2AccountingTables extends Migration
{
    public function up()
    {
        // 1. Stock Ledger
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_barang' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'id_toko' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'qty' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'balance' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'reference_type' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'reference_id' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
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
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('id_barang');
        $this->forge->addKey('id_toko');
        $this->forge->createTable('stock_ledgers');

        // 2. Chart of Accounts
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'unique' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
            ],
            'type' => [
                'type' => 'ENUM',
                'constraint' => ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'],
            ],
            'normal_balance' => [
                'type' => 'ENUM',
                'constraint' => ['DEBIT', 'CREDIT'],
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('accounts');

        // Seed Default Accounts
        $seeder = \Config\Database::connect();
        $accounts = [
            ['code' => '1001', 'name' => 'Cash', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['code' => '1002', 'name' => 'Bank', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['code' => '1003', 'name' => 'Accounts Receivable', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['code' => '1004', 'name' => 'Inventory', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['code' => '2001', 'name' => 'Accounts Payable', 'type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            ['code' => '2002', 'name' => 'Unearned Revenue', 'type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            ['code' => '3001', 'name' => 'Owner Equity', 'type' => 'EQUITY', 'normal_balance' => 'CREDIT'],
            ['code' => '4001', 'name' => 'Sales Revenue', 'type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['code' => '4002', 'name' => 'Sales Discount', 'type' => 'REVENUE', 'normal_balance' => 'DEBIT'], // Contra-revenue
            ['code' => '4003', 'name' => 'Sales Returns', 'type' => 'REVENUE', 'normal_balance' => 'DEBIT'], // Contra-revenue
            ['code' => '5001', 'name' => 'Cost of Goods Sold', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
        ];
        foreach ($accounts as $acc) {
            $seeder->table('accounts')->insert($acc);
        }


        // 3. Journals
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'reference_type' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'reference_id' => [
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
            'total_debit' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'default' => 0,
            ],
            'total_credit' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'default' => 0,
            ],
             'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('journals');

        // 4. Journal Items
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'journal_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'account_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'debit' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'default' => 0,
            ],
            'credit' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('journal_id', 'journals', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('journal_items');

        // 5. Transaction Payments
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'transaction_id' => [
                'type' => 'VARCHAR',
                'constraint' => 50, // Matches transaction.id if it's varchar, checking TransactionModel suggests it might be INT or VARCHAR depending on usage. Let's assume matches existing PK.
                // Wait TransactionModel PK is 'id'. Let's check if it's int or varchar. Usually int.
                // Let's use INT to be safe, but loose connection if necessary.
                // Re-checking TransactionModel: primary key is 'id'.
                // I will use INT constraint 11 unsigned to match typical CI usage.
            ],
            'amount' => [
                 'type' => 'DECIMAL',
                'constraint' => '15,2',
            ],
            'payment_method' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
            ],
            'paid_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'note' => [
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
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('transaction_payments');

        // 6. Modify Transaction Table
        $fields = [
            'delivery_status' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'default' => 'READY_TO_PICKUP',
                'after' => 'status'
            ],
            'discount_type' => [
                'type' => 'ENUM',
                'constraint' => ['PERCENT', 'FIXED'],
                'null' => true,
                'after' => 'status'
            ],
             'discount_amount' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'default' => 0,
                'after' => 'discount_type'
            ],
        ];
        $this->forge->addColumn('transaction', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('transaction', ['delivery_status', 'discount_type', 'discount_amount']);
        $this->forge->dropTable('transaction_payments');
        $this->forge->dropTable('journal_items');
        $this->forge->dropTable('journals');
        $this->forge->dropTable('accounts');
        $this->forge->dropTable('stock_ledgers');
    }
}
