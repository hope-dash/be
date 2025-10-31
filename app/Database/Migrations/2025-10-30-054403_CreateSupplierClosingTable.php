<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSupplierClosingTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true
            ],
            'transaction_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => false
            ],
            'kode_barang' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
                'null' => false
            ],
            'transaction_date' => [
                'type' => 'DATETIME',
                'null' => false
            ],
            'jumlah' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => false
            ],
            'harga_jual' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => false
            ],
            'total' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => false
            ],
            'harga_modal' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => false
            ],
            'total_harga_modal' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => false
            ],
            'dropship_suplier' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
                'null' => true
            ],
            'closing_month' => [
                'type' => 'VARCHAR',
                'constraint' => '7', // Format: YYYY-MM
                'null' => false
            ],
            'closing_date' => [
                'type' => 'DATETIME',
                'null' => false
            ],
            'closing_status' => [
                'type' => 'ENUM',
                'constraint' => ['active', 'rolled_back'],
                'default' => 'active'
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true
            ]
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('transaction_id');
        $this->forge->addKey('kode_barang');
        $this->forge->addKey('closing_month');
        $this->forge->addKey('closing_status');
        $this->forge->addKey(['transaction_id', 'kode_barang', 'closing_month']); // Composite key untuk mencegah duplikasi
        $this->forge->createTable('supplier_closing');
    }

    public function down()
    {
        $this->forge->dropTable('supplier_closing');
    }
}