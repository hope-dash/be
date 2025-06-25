<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateClosingTables extends Migration
{
    public function up()
    {
        // Create table transaction_closing
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => TRUE,
                'auto_increment' => TRUE
            ],
            'transaction_id' => [
                'type' => 'BIGINT',
                'unsigned' => TRUE,
                'null' => TRUE,
            ],
            'transaction_status' => ['type' => 'VARCHAR', 'constraint' => 50],
            'period_start' => [
                'type' => 'DATE',
                'null' => FALSE,
            ],
            'period_end' => [
                'type' => 'DATE',
                'null' => FALSE,
            ],
            'closing_status' => [
                'type' => 'TINYINT',
                'default' => 0, // 0=belum closing, 1=closed, 2=reclosed
            ],
            'payment_count' => [
                'type' => 'INT',
                'default' => 0,
            ],
            'total_debit' => [
                'type' => 'DECIMAL',
                'constraint' => '18,2',
                'default' => 0,
            ],
            'total_credit' => [
                'type' => 'DECIMAL',
                'constraint' => '18,2',
                'default' => 0,
            ],
            'total_profit' => [
                'type' => 'DECIMAL',
                'constraint' => '18,2',
                'default' => 0,
            ],
            'total_modal' => [
                'type' => 'DECIMAL',
                'constraint' => '18,2',
                'default' => 0,
            ],
            'closing_date' => [
                'type' => 'DATETIME',
                'null' => TRUE,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => TRUE,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => TRUE,
            ],
        ]);

        $this->forge->addKey('id', TRUE);
        $this->forge->createTable('transaction_closing');

        // Create table closing_detail
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => TRUE,
                'auto_increment' => TRUE,
            ],
            'transaction_closing_id' => [
                'type' => 'INT',
                'unsigned' => TRUE,
                'null' => FALSE,
            ],
            'keterangan' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
                'null' => TRUE,
            ],
            'tipe' => [
                'type' => "ENUM('PEMBAYARAN', 'REFUND', 'DP', 'ONGKOS_KIRIM')",
                'null' => TRUE,
            ],
            'tanggal' => [
                'type' => 'DATE',
                'null' => TRUE,
            ],
            'debit' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'credit' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'urutan' => [
                'type' => 'INT',
                'default' => 0,
            ],
            'id_cashflow' => [
                'type' => 'INT',
                'unsigned' => TRUE,
                'null' => TRUE,
            ],
        ]);

        $this->forge->addKey('id', TRUE);
        $this->forge->createTable('closing_detail');

        // Add "closing" column to transaction
        $this->forge->addColumn('transaction', [
            'closing' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'status']
        ]);

        // Add "closing" column to sales_product
        $this->forge->addColumn('sales_product', [
            'closing' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'actual_total']
        ]);

        // Add "closing" column to cashflow
        $this->forge->addColumn('cashflow', [
            'closing' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'credit']
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('transaction_closing', true);
        $this->forge->dropTable('closing_detail', true);

        $this->forge->dropColumn('transaction', 'closing');
        $this->forge->dropColumn('sales_product', 'closing');
        $this->forge->dropColumn('cashflow', 'closing');
    }
}
