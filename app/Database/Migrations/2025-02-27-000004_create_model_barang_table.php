<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateModelBarangTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'auto_increment' => true,
            ],
            'kode_awal' => [
                'type' => 'VARCHAR',
                'constraint' => '3',
                'unique' => true,
            ],
            'nama_model' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
             'created_by' => [
               'type' => 'VARCHAR',
                'constraint' => '3',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
             'updated_by' => [
               'type' => 'VARCHAR',
                'constraint' => '3',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
                'default' => null
            ]
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('model_barang');
    }

    public function down()
    {
        $this->forge->dropTable('model_barang');
    }
}
