<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SuplierTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 5,
                'unsigned' => true,
                'auto_increment' => true
            ],
            'suplier_name' => [
                'type' => 'VARCHAR',
                'constraint' => '255'
            ],
            'suplier_number' => [
                'type' => 'VARCHAR',
                'constraint' => '50'
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true
            ]
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('suplier');
    }

    public function down()
    {
        $this->forge->dropTable('suplier');
    }

}
