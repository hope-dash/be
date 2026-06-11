<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMootaAppIdToTenants extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tenants', [
            'moota_token' => [
                'type'       => 'TEXT',
                'null'       => true,
                'after'      => 'status'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tenants', 'moota_token');
    }
}
