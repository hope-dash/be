<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

class SubscriptionService
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function getCurrentMonthStartDate(): string
    {
        return date('Y-m-01');
    }

    public function getActiveSubscriptionWithPackage(int $tenantId): ?array
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->db->table('tenant_subscriptions ts')
            ->select('ts.*, sp.code as package_code, sp.name as package_name, sp.duration_months, sp.product_quota, sp.transaction_monthly_quota')
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

        return $row ?: null;
    }

    public function syncCurrentTenantQuota(int $tenantId): ?array
    {
        if (!$this->db->tableExists('tenant_quota')) {
            return null;
        }

        $sub = $this->getActiveSubscriptionWithPackage($tenantId);
        if (!$sub) {
            return null;
        }

        $monthStart = $this->getCurrentMonthStartDate();
        $monthStartDateTime = $monthStart . ' 00:00:00';
        $monthEndDateTime = date('Y-m-t 23:59:59');

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

        $now = date('Y-m-d H:i:s');
        $row = $this->db->table('tenant_quota')
            ->where('tenant_id', $tenantId)
            ->where('month_start', $monthStart)
            ->get()
            ->getRowArray();

        $payload = [
            'product_quota' => $sub['product_quota'],
            'product_used' => $productUsed,
            'transaction_monthly_quota' => $sub['transaction_monthly_quota'],
            'transaction_monthly_used' => $trxUsed,
            'updated_at' => $now,
        ];

        if ($row) {
            $this->db->table('tenant_quota')
                ->where('id', (int) $row['id'])
                ->update($payload);
        } else {
            $payload['tenant_id'] = $tenantId;
            $payload['month_start'] = $monthStart;
            $payload['created_at'] = $now;
            $this->db->table('tenant_quota')->insert($payload);
        }

        return $this->db->table('tenant_quota')
            ->where('tenant_id', $tenantId)
            ->where('month_start', $monthStart)
            ->get()
            ->getRowArray() ?: null;
    }

    public function canCreateProducts(int $tenantId, int $countToAdd = 1): array
    {
        $sub = $this->getActiveSubscriptionWithPackage($tenantId);
        if (!$sub) {
            return ['ok' => false, 'code' => 403, 'message' => 'Subscription tidak aktif / belum ada.'];
        }

        $quotaRow = $this->syncCurrentTenantQuota($tenantId);
        $quota = $quotaRow['product_quota'] ?? $sub['product_quota'];
        $current = (int) ($quotaRow['product_used'] ?? 0);

        if ($quota === null || $quota === '') {
            return ['ok' => true];
        }

        $limit = (int) $quota;
        if ($limit <= 0) {
            return ['ok' => false, 'code' => 403, 'message' => 'Kuota product di package adalah 0.'];
        }
        if ($current + $countToAdd > $limit) {
            return [
                'ok' => false,
                'code' => 403,
                'message' => "Kuota product habis. Upgrade akun kamu untuk bisa menambah product.",
            ];
        }

        return ['ok' => true];
    }

    public function canCreateTransactionsThisMonth(int $tenantId, int $countToAdd = 1): array
    {
        $sub = $this->getActiveSubscriptionWithPackage($tenantId);
        if (!$sub) {
            return ['ok' => false, 'code' => 403, 'message' => 'Subscription tidak aktif / belum ada.'];
        }

        $quotaRow = $this->syncCurrentTenantQuota($tenantId);
        $quota = $quotaRow['transaction_monthly_quota'] ?? $sub['transaction_monthly_quota'];
        $current = (int) ($quotaRow['transaction_monthly_used'] ?? 0);

        if ($quota === null || $quota === '') {
            return ['ok' => true];
        }

        $limit = (int) $quota;
        if ($limit <= 0) {
            return ['ok' => false, 'code' => 403, 'message' => 'Kuota transaksi bulanan di package adalah 0.'];
        }
        if ($current + $countToAdd > $limit) {
            return [
                'ok' => false,
                'code' => 403,
                'message' => "Kuota transaksi bulanan habis. Limit: {$limit}/bulan, terpakai bulan ini: {$current}.",
            ];
        }

        return ['ok' => true];
    }

    public function incrementProductUsed(int $tenantId, int $delta = 1): void
    {
        if ($delta <= 0 || !$this->db->tableExists('tenant_quota')) {
            return;
        }

        $monthStart = $this->getCurrentMonthStartDate();
        $row = $this->db->table('tenant_quota')
            ->where('tenant_id', $tenantId)
            ->where('month_start', $monthStart)
            ->get()
            ->getRowArray();

        if (!$row) {
            $this->syncCurrentTenantQuota($tenantId);
            $row = $this->db->table('tenant_quota')
                ->where('tenant_id', $tenantId)
                ->where('month_start', $monthStart)
                ->get()
                ->getRowArray();
        }

        if ($row) {
            $this->db->query(
                "UPDATE tenant_quota SET product_used = product_used + ?, updated_at = ? WHERE id = ?",
                [$delta, date('Y-m-d H:i:s'), (int) $row['id']]
            );
        }
    }

    public function incrementTransactionUsed(int $tenantId, int $delta = 1): void
    {
        if ($delta <= 0 || !$this->db->tableExists('tenant_quota')) {
            return;
        }

        $monthStart = $this->getCurrentMonthStartDate();
        $row = $this->db->table('tenant_quota')
            ->where('tenant_id', $tenantId)
            ->where('month_start', $monthStart)
            ->get()
            ->getRowArray();

        if (!$row) {
            $this->syncCurrentTenantQuota($tenantId);
            $row = $this->db->table('tenant_quota')
                ->where('tenant_id', $tenantId)
                ->where('month_start', $monthStart)
                ->get()
                ->getRowArray();
        }

        if ($row) {
            $this->db->query(
                "UPDATE tenant_quota SET transaction_monthly_used = transaction_monthly_used + ?, updated_at = ? WHERE id = ?",
                [$delta, date('Y-m-d H:i:s'), (int) $row['id']]
            );
        }
    }

    public function applyPaidPackagePurchase(int $tenantId, int $packageId): array
    {
        $now = date('Y-m-d H:i:s');

        $package = $this->db->table('subscription_packages')->where('id', $packageId)->where('is_active', 1)->get()->getRowArray();
        if (!$package) {
            throw new \Exception('Package tidak ditemukan / tidak aktif');
        }

        $months = (int) $package['duration_months'];
        if ($months <= 0) {
            throw new \Exception('Duration package tidak valid');
        }

        $active = $this->db->table('tenant_subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->groupStart()
                ->where('end_at IS NULL')
                ->orWhere('end_at >=', $now)
            ->groupEnd()
            ->orderBy('end_at', 'DESC')
            ->get()
            ->getRowArray();

        if ($active && (int) $active['package_id'] === (int) $packageId) {
            $baseEnd = $active['end_at'] ?: $now;
            $newEnd = (new \DateTimeImmutable($baseEnd))->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
            $this->db->table('tenant_subscriptions')->where('id', (int) $active['id'])->update([
                'end_at' => $newEnd,
                'updated_at' => $now,
            ]);

            $this->syncCurrentTenantQuota($tenantId);

            return [
                'action' => 'extended',
                'subscription_id' => (int) $active['id'],
                'start_at' => $active['start_at'],
                'end_at' => $newEnd,
            ];
        }

        // Replace package (different or inactive subscription)
        $this->db->table('tenant_subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->update([
                'status' => 'expired',
                'end_at' => $now,
                'updated_at' => $now,
            ]);

        $endAt = (new \DateTimeImmutable($now))->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
        $this->db->table('tenant_subscriptions')->insert([
            'tenant_id' => $tenantId,
            'package_id' => $packageId,
            'status' => 'active',
            'start_at' => $now,
            'end_at' => $endAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $subscriptionId = (int) ($this->db->insertID() ?: 0);
        $this->syncCurrentTenantQuota($tenantId);

        return [
            'action' => 'replaced',
            'subscription_id' => $subscriptionId,
            'start_at' => $now,
            'end_at' => $endAt,
        ];
    }
}
