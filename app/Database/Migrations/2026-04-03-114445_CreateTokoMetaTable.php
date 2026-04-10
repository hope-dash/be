<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTokoMetaTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'id_toko' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'meta_key' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
            ],
            'meta_value' => [
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
        $this->forge->addForeignKey('id_toko', 'toko', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addKey(['id_toko', 'meta_key']);
        if (!$this->db->tableExists('toko_meta')) {
            $this->forge->createTable('toko_meta');
        }
    }

    public function down()
    {
        $this->forge->dropTable('toko_meta');
    }
}
