<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateLogAktivitas extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'null' => true],
            'action_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'target_table' => ['type' => 'VARCHAR', 'constraint' => 100],
            'target_id' => ['type' => 'INT', 'null' => true],
            'description' => ['type' => 'TEXT', 'null' => true],
            'detail' => ['type' => 'JSON', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'null' => false, 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('log_aktivitas');
    }

    public function down()
    {
        $this->forge->dropTable('log_aktivitas');
    }
}
