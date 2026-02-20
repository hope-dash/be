<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class MakeReferenceIdNullableInJournals extends Migration
{
    public function up()
    {
        $fields = [
            'reference_id' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ],
        ];
        $this->forge->modifyColumn('journals', $fields);
    }

    public function down()
    {
        $fields = [
            'reference_id' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => false,
            ],
        ];
        $this->forge->modifyColumn('journals', $fields);
    }
}
