<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTypeToToko extends Migration
{
    public function up()
    {
        $fields = [
            'type' => [
                'type' => 'ENUM',
                'constraint' => ['UTAMA', 'CABANG'],
                'default' => 'CABANG',
                'after' => 'toko_name'
            ],
        ];
        $this->forge->addColumn('toko', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('toko', 'type');
    }
}
