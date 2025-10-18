<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddImageLogoToToko extends Migration
{
    public function up()
    {
        $fields = [
            'image_logo' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true,
                'after' => 'email_toko',
            ],
        ];
        $this->forge->addColumn('toko', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('toko', 'image_logo');
    }
}
