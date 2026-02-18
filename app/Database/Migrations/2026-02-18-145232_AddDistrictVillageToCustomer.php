<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDistrictVillageToCustomer extends Migration
{
    public function up()
    {
        $fields = [
            'kecamatan' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'kota_kabupaten',
            ],
            'kelurahan' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'kecamatan',
            ],
        ];
        $this->forge->addColumn('customer', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('customer', ['kecamatan', 'kelurahan']);
    }
}
