<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

class SubscriptionService
{
    public function __construct(private BaseConnection $db)
    {
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

    public function canCreateProducts(int $tenantId, int $countToAdd = 1): array
    {
        $sub = $this->getActiveSubscriptionWithPackage($tenantId);
        if (!$sub) {
            return ['ok' => false, 'code' => 403, 'message' => 'Subscription tidak aktif / belum ada.'];
        }

        $quota = $sub['product_quota'];
        if ($quota === null || $quota === '') {
            return ['ok' => true];
        }

        $current = (int) $this->db->table('product')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->countAllResults();

        $limit = (int) $quota;
        if ($limit <= 0) {
            return ['ok' => false, 'code' => 403, 'message' => 'Kuota product di package adalah 0.'];
        }
        if ($current + $countToAdd > $limit) {
            return [
                'ok' => false,
                'code' => 403,
                'message' => "Kuota product habis. Limit: {$limit}, terpakai: {$current}.",
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

        $quota = $sub['transaction_monthly_quota'];
        if ($quota === null || $quota === '') {
            return ['ok' => true];
        }

        $start = date('Y-m-01 00:00:00');
        $end = date('Y-m-t 23:59:59');

        $builder = $this->db->table('transaction')
            ->where('tenant_id', $tenantId)
            ->groupStart()
                ->groupStart()
                    ->where('created_at IS NOT NULL')
                    ->where('created_at >=', $start)
                    ->where('created_at <=', $end)
                ->groupEnd()
                ->orGroupStart()
                    ->where('created_at IS NULL')
                    ->where('date_time >=', $start)
                    ->where('date_time <=', $end)
                ->groupEnd()
            ->groupEnd();

        $current = (int) $builder->countAllResults();

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
}
