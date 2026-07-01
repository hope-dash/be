<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIntegrationActiveToTenants extends Migration
{
    public function up()
    {
        $fields = [
            'integration_tiktok_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'integration_shopee_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'integration_email_active'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'integration_moota_active'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'integration_whatsapp_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ];

        if ($this->db->tableExists('tenants')) {
            $this->forge->addColumn('tenants', $fields);
        }
    }

    public function down()
    {
        $cols = ['integration_tiktok_active', 'integration_shopee_active', 'integration_email_active', 'integration_moota_active', 'integration_whatsapp_active'];
        if ($this->db->tableExists('tenants')) {
            $this->forge->dropColumn('tenants', $cols);
        }
    }
}
