<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAttachmentToExpenses extends Migration
{
    public function up()
    {
        $this->forge->addColumn('expenses', [
            'attachment' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'description'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('expenses', 'attachment');
    }
}
