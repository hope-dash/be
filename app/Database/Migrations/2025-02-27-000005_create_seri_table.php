<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSeriTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'auto_increment' => true,
            ],
            'seri' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
                'unique' => true,
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
        $this->forge->createTable('seri');
    }

    public function down()
    {
        $this->forge->dropTable('seri');
    }
}
