<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDescriptionToJournalItems extends Migration
{
    public function up()
    {
        $this->forge->addColumn('journal_items', [
            'description' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'default' => null,
                'after' => 'credit',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('journal_items', 'description');
    }
}
