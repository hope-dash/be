<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTenjoIdToKecamatan extends Migration
{
    public function up()
    {
        $this->forge->addColumn('kecamatan', [
            'tenjo_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'name',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('kecamatan', 'tenjo_id');
    }
}
