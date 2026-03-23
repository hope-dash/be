<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTenjoIdToKotaKabupaten extends Migration
{
    public function up()
    {
        $this->forge->addColumn('kota_kabupaten', [
            'tenjo_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
                'after' => 'name'
            ],
            'jenis' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'after' => 'tenjo_id'
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('kota_kabupaten', ['tenjo_id', 'jenis']);
    }
}
