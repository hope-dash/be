<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAdministrativeFieldsToToko extends Migration
{
    public function up()
    {
        $fields = [
            'provinsi' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'alamat',
            ],
            'kota_kabupaten' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'provinsi',
            ],
            'kecamatan' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'kota_kabupaten',
            ],
            'kelurahan' => [
                'type' => 'VARCHAR',
                'constraint' => 15,
                'null' => true,
                'after' => 'kecamatan',
            ],
            'kode_pos' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'kelurahan',
            ],
        ];
        $this->forge->addColumn('toko', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('toko', ['provinsi', 'kota_kabupaten', 'kecamatan', 'kelurahan', 'kode_pos']);
    }
}
