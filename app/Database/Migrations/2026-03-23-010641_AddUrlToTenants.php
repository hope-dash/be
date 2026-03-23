<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUrlToTenants extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tenants', [
            'url' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
                'after'      => 'logo_url'
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tenants', 'url');
    }
}
