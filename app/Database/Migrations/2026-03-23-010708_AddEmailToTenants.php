<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddEmailToTenants extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tenants', [
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
                'after'      => 'url'
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tenants', 'email');
    }
}
