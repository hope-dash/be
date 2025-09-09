<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBankFieldsToToko extends Migration
{
    public function up()
    {
        $this->forge->addColumn('toko', [
            'bank' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'image_logo', // supaya urut setelah kolom ini
            ],
            'nama_pemilik' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
                'after'      => 'bank',
            ],
            'nomer_rekening' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'after'      => 'nama_pemilik',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('toko', ['bank', 'nama_pemilik', 'nomer_rekening']);
    }
}
