<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMootaFieldsToToko extends Migration
{
    public function up()
    {
        $this->forge->addColumn('toko', [
            'moota_connection' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'nomer_rekening'
            ],
            'moota_bank_type' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
                'after'      => 'moota_connection'
            ],
            'moota_username' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'moota_bank_type'
            ],
            'moota_password' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
                'after'      => 'moota_username'
            ],
            'moota_bank_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'moota_password'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('toko', [
            'moota_connection',
            'moota_bank_type',
            'moota_username',
            'moota_password',
            'moota_bank_id'
        ]);
    }
}
