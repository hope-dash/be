<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddQuotaSnapshotToTenantSubscriptions extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('tenant_subscriptions')) {
            return;
        }

        // Snapshot quotas so future edits to `subscription_packages` don't change what tenant already bought.
        if (!$this->db->fieldExists('product_quota_snapshot', 'tenant_subscriptions')) {
            $this->forge->addColumn('tenant_subscriptions', [
                'product_quota_snapshot' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
            ]);
        }

        if (!$this->db->fieldExists('transaction_monthly_quota_snapshot', 'tenant_subscriptions')) {
            $this->forge->addColumn('tenant_subscriptions', [
                'transaction_monthly_quota_snapshot' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
            ]);
        }

        // Backfill snapshots from current package values for existing subscriptions.
        if ($this->db->tableExists('subscription_packages')) {
            $this->db->query("
                UPDATE tenant_subscriptions ts
                INNER JOIN subscription_packages sp ON sp.id = ts.package_id
                SET
                    ts.product_quota_snapshot = COALESCE(ts.product_quota_snapshot, sp.product_quota),
                    ts.transaction_monthly_quota_snapshot = COALESCE(ts.transaction_monthly_quota_snapshot, sp.transaction_monthly_quota),
                    ts.updated_at = COALESCE(ts.updated_at, NOW())
            ");
        }
    }

    public function down()
    {
        if ($this->db->tableExists('tenant_subscriptions')) {
            if ($this->db->fieldExists('product_quota_snapshot', 'tenant_subscriptions')) {
                $this->forge->dropColumn('tenant_subscriptions', 'product_quota_snapshot');
            }
            if ($this->db->fieldExists('transaction_monthly_quota_snapshot', 'tenant_subscriptions')) {
                $this->forge->dropColumn('tenant_subscriptions', 'transaction_monthly_quota_snapshot');
            }
        }
    }
}

