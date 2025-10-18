<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePembelianTables extends Migration
{
    public function up()
    {
        // Tabel pembelian
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tanggal_belanja' => ['type' => 'DATE'],
            'supplier_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true], // Disesuaikan dengan suplier.id
            'id_toko' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'total_belanja' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'catatan' => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('supplier_id', 'suplier', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('pembelian', true);

        // Tabel pembelian_detail
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'pembelian_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'kode_barang' => ['type' => 'VARCHAR', 'constraint' => 100],
            'jumlah' => ['type' => 'INT', 'unsigned' => true],
            'harga_satuan' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'total_harga' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('pembelian_id', 'pembelian', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('pembelian_detail', true);

        // Tabel pembelian_biaya
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'pembelian_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'nama_biaya' => ['type' => 'VARCHAR', 'constraint' => 255],
            'jumlah' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('pembelian_id', 'pembelian', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('pembelian_biaya', true);
    }

    public function down()
    {
        $this->forge->dropTable('pembelian_biaya');
        $this->forge->dropTable('pembelian_detail');
        $this->forge->dropTable('pembelian');
    }
}
