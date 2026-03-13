<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLogoToTenants extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('tenants')) {
            return;
        }

        if (!$this->db->fieldExists('logo_url', 'tenants')) {
            $this->forge->addColumn('tenants', [
                'logo_url' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('tenants') && $this->db->fieldExists('logo_url', 'tenants')) {
            $this->forge->dropColumn('tenants', 'logo_url');
        }
    }
}

