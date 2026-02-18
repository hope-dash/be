<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateKelurahanTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type' => 'VARCHAR',
                'constraint' => '20',
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
            ],
            'district_code' => [
                'type' => 'VARCHAR',
                'constraint' => '15',
            ],
            'regency_code' => [
                'type' => 'VARCHAR',
                'constraint' => '10',
            ],
            'province_code' => [
                'type' => 'VARCHAR',
                'constraint' => '10',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('code');
        $this->forge->addKey('district_code');
        $this->forge->addKey('regency_code');
        $this->forge->addKey('province_code');
        $this->forge->createTable('kelurahan');
    }

    public function down()
    {
        $this->forge->dropTable('kelurahan');
    }
}
