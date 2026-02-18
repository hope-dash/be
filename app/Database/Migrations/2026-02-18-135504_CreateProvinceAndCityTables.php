<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProvinceAndCityTables extends Migration
{
    public function up()
    {
        // 1. Provincy Table
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type' => 'VARCHAR',
                'constraint' => '10',
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('code');
        $this->forge->createTable('provincy');

        // 2. Kota Kabupaten Table
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type' => 'VARCHAR',
                'constraint' => '10',
            ],
            'province_code' => [
                'type' => 'VARCHAR',
                'constraint' => '10',
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('code');
        $this->forge->addKey('province_code');
        $this->forge->createTable('kota_kabupaten');
    }

    public function down()
    {
        $this->forge->dropTable('kota_kabupaten');
        $this->forge->dropTable('provincy');
    }
}
