<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSubscriptionTables extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('subscription_packages')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'code' => [
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                ],
                'name' => [
                    'type' => 'VARCHAR',
                    'constraint' => 150,
                ],
                'price' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'currency' => [
                    'type' => 'VARCHAR',
                    'constraint' => 3,
                    'default' => 'IDR',
                ],
                'duration_months' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                // Benefits / quotas
                'product_quota' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true, // NULL = unlimited
                ],
                'transaction_monthly_quota' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true, // NULL = unlimited
                ],
                'is_active' => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'default' => 1,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('code');
            $this->forge->createTable('subscription_packages');
        }

        if (!$this->db->tableExists('tenant_subscriptions')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'tenant_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'package_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'default' => 'active', // active|canceled|expired
                ],
                'start_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'end_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('package_id');
            $this->forge->createTable('tenant_subscriptions');
        }

        $this->ensureHopeDefaultSubscription();
    }

    public function down()
    {
        $this->forge->dropTable('tenant_subscriptions', true);
        $this->forge->dropTable('subscription_packages', true);
    }

    private function ensureHopeDefaultSubscription(): void
    {
        if (!$this->db->tableExists('tenants')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // 1) Ensure package exists
        $package = $this->db->table('subscription_packages')->where('code', 'HOPE_DEFAULT')->get()->getRowArray();
        if (!$package) {
            $this->db->table('subscription_packages')->insert([
                'code' => 'HOPE_DEFAULT',
                'name' => 'HOPE Default (Unlimited)',
                'price' => 0,
                'currency' => 'IDR',
                'duration_months' => 1200,
                'product_quota' => 1000000,
                'transaction_monthly_quota' => 1000000,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $packageId = (int) ($this->db->insertID() ?: 0);
        } else {
            $packageId = (int) $package['id'];
        }

        if ($packageId <= 0) {
            return;
        }

        // 2) Ensure tenant exists
        $tenant = $this->db->table('tenants')->where('code', 'hope')->get()->getRowArray();
        if (!$tenant) {
            return;
        }

        $tenantId = (int) $tenant['id'];
        if ($tenantId <= 0) {
            return;
        }

        // 3) Ensure active subscription exists
        $sub = $this->db->table('tenant_subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get()
            ->getRowArray();

        if ($sub) {
            return;
        }

        $this->db->table('tenant_subscriptions')->insert([
            'tenant_id' => $tenantId,
            'package_id' => $packageId,
            'status' => 'active',
            'start_at' => $now,
            'end_at' => date('Y-m-d H:i:s', strtotime('+100 years')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

