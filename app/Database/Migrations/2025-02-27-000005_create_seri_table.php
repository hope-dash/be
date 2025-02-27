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
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('seri');
    }

    public function down()
    {
        $this->forge->dropTable('seri');
    }
}
