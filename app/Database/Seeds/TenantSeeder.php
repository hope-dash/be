<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run()
    {
        if (!$this->db->tableExists('tenants')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $existing = $this->db->table('tenants')->where('code', 'hope')->get()->getRowArray();
        if ($existing) {
            return;
        }

        $this->db->table('tenants')->insert([
            'code' => 'hope',
            'name' => 'HOPE',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

