<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ChangeMootaAppIdToMootaToken extends Migration
{
    public function up()
    {
        // Drop moota_app_id if it exists, and add moota_token
        if ($this->db->fieldExists('moota_app_id', 'tenants')) {
            $this->forge->dropColumn('tenants', 'moota_app_id');
        }
        
        $this->forge->addColumn('tenants', [
            'moota_token' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'status'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tenants', 'moota_token');
        
        $this->forge->addColumn('tenants', [
            'moota_app_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'after'      => 'status'
            ]
        ]);
    }
}
