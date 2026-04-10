<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStockTransferTable extends Migration
{
    public function up()
    {
        // Stock Transfer Table
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'ref_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'unique'     => true,
            ],
            'source_toko_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'target_toko_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['PENDING', 'APPROVED', 'REJECTED'],
                'default'    => 'PENDING',
            ],
            'note' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'date' => [
                'type' => 'DATE',
            ],
            'total_value' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
            ],
            'ongkos_kirim' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
            ],
            'payment_method' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'created_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'approved_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'tenant_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
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
        $this->forge->addKey('tenant_id');
        $this->forge->addKey('source_toko_id');
        $this->forge->addKey('target_toko_id');
        $this->forge->createTable('stock_transfer');

        // Stock Transfer Items Table
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'transfer_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'kode_barang' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'qty' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'harga_modal' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('transfer_id');
        $this->forge->addKey('kode_barang');
        $this->forge->createTable('stock_transfer_item');
    }

    public function down()
    {
        $this->forge->dropTable('stock_transfer_item');
        $this->forge->dropTable('stock_transfer');
    }
}
