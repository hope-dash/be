<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIntegrationFieldsToPackagesAndQuota extends Migration
{
    public function up()
    {
        $fields = [
            'integration_tiktok'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'integration_shopee'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'integration_email'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'integration_moota'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'integration_whatsapp' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ];

        if ($this->db->tableExists('subscription_packages')) {
            $this->forge->addColumn('subscription_packages', $fields);
        }
        if ($this->db->tableExists('tenant_quota')) {
            $this->forge->addColumn('tenant_quota', $fields);
        }
    }

    public function down()
    {
        $cols = ['integration_tiktok', 'integration_shopee', 'integration_email', 'integration_moota', 'integration_whatsapp'];
        if ($this->db->tableExists('subscription_packages')) {
            $this->forge->dropColumn('subscription_packages', $cols);
        }
        if ($this->db->tableExists('tenant_quota')) {
            $this->forge->dropColumn('tenant_quota', $cols);
        }
    }
}
