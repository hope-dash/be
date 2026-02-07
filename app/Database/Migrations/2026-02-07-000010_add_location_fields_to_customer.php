<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLocationFieldsToCustomer extends Migration
{
    public function up()
    {
        $fields = [
            'provinsi' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'alamat',
            ],
            'kota_kabupaten' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'provinsi',
            ],
            'kode_pos' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'kota_kabupaten',
            ],
        ];
        $this->forge->addColumn('customer', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('customer', [
            'provinsi',
            'kota_kabupaten',
            'kode_pos'
        ]);
    }
}
