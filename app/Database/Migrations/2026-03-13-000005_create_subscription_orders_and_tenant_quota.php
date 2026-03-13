<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSubscriptionOrdersAndTenantQuota extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('tenant_quota')) {
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
                'month_start' => [
                    'type' => 'DATE',
                ],
                'product_quota' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'product_used' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'default' => 0,
                ],
                'transaction_monthly_quota' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'transaction_monthly_used' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'default' => 0,
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
            $this->forge->addUniqueKey(['tenant_id', 'month_start'], 'uq_tenant_quota_tenant_month');
            $this->forge->createTable('tenant_quota');
        }

        if (!$this->db->tableExists('subscription_orders')) {
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
                'external_transaction_id' => [
                    'type' => 'VARCHAR',
                    'constraint' => 120,
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 30,
                    'default' => 'waiting_payment', // waiting_payment|paid|failed|canceled
                ],
                'amount' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'currency' => [
                    'type' => 'VARCHAR',
                    'constraint' => 3,
                    'default' => 'IDR',
                ],
                'paid_at' => [
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
            $this->forge->addUniqueKey('external_transaction_id');
            $this->forge->createTable('subscription_orders');
        }

        $this->backfillCurrentMonthQuota();
    }

    public function down()
    {
        $this->forge->dropTable('subscription_orders', true);
        $this->forge->dropTable('tenant_quota', true);
    }

    private function backfillCurrentMonthQuota(): void
    {
        if (
            !$this->db->tableExists('tenants')
            || !$this->db->tableExists('tenant_subscriptions')
            || !$this->db->tableExists('subscription_packages')
            || !$this->db->tableExists('product')
            || !$this->db->tableExists('transaction')
            || !$this->db->tableExists('tenant_quota')
        ) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $monthStart = date('Y-m-01');
        $monthStartDateTime = $monthStart . ' 00:00:00';
        $monthEndDateTime = date('Y-m-t 23:59:59');

        $tenants = $this->db->table('tenants')->select('id')->get()->getResultArray();
        foreach ($tenants as $t) {
            $tenantId = (int) $t['id'];
            if ($tenantId <= 0) {
                continue;
            }

            $exists = $this->db->table('tenant_quota')
                ->where('tenant_id', $tenantId)
                ->where('month_start', $monthStart)
                ->get()
                ->getRowArray();
            if ($exists) {
                continue;
            }

            $sub = $this->db->table('tenant_subscriptions ts')
                ->select('sp.product_quota, sp.transaction_monthly_quota')
                ->join('subscription_packages sp', 'sp.id = ts.package_id', 'inner')
                ->where('ts.tenant_id', $tenantId)
                ->where('ts.status', 'active')
                ->groupStart()
                    ->where('ts.start_at IS NULL')
                    ->orWhere('ts.start_at <=', $now)
                ->groupEnd()
                ->groupStart()
                    ->where('ts.end_at IS NULL')
                    ->orWhere('ts.end_at >=', $now)
                ->groupEnd()
                ->orderBy('ts.end_at', 'DESC')
                ->get()
                ->getRowArray();

            $productQuota = $sub ? $sub['product_quota'] : null;
            $trxQuota = $sub ? $sub['transaction_monthly_quota'] : null;

            $productUsed = (int) $this->db->table('product')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->countAllResults();

            $trxUsed = (int) $this->db->table('transaction')
                ->where('tenant_id', $tenantId)
                ->groupStart()
                    ->groupStart()
                        ->where('created_at IS NOT NULL')
                        ->where('created_at >=', $monthStartDateTime)
                        ->where('created_at <=', $monthEndDateTime)
                    ->groupEnd()
                    ->orGroupStart()
                        ->where('created_at IS NULL')
                        ->where('date_time >=', $monthStartDateTime)
                        ->where('date_time <=', $monthEndDateTime)
                    ->groupEnd()
                ->groupEnd()
                ->countAllResults();

            $this->db->table('tenant_quota')->insert([
                'tenant_id' => $tenantId,
                'month_start' => $monthStart,
                'product_quota' => $productQuota,
                'product_used' => $productUsed,
                'transaction_monthly_quota' => $trxQuota,
                'transaction_monthly_used' => $trxUsed,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

