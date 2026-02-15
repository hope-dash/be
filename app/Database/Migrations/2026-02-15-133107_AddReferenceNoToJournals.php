<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddReferenceNoToJournals extends Migration
{
    public function up()
    {
        $fields = [
            'reference_no' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'reference_id'
            ],
        ];
        $this->forge->addColumn('journals', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('journals', 'reference_no');
    }
}
