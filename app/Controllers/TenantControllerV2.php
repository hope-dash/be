<?php

namespace App\Controllers;

use App\Libraries\TenantContext;
use App\Models\JsonResponse;
use App\Models\TenantModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\RESTful\ResourceController;

class TenantControllerV2 extends ResourceController
{
    use ResponseTrait;

    protected JsonResponse $jsonResponse;
    protected TenantModel $tenantModel;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->tenantModel = new TenantModel();
    }

    // GET /api/v2/tenant/{code}
    public function show($code = null)
    {
        try {
            $code = trim((string) ($code ?? ''));
            if ($code === '') {
                return $this->jsonResponse->error('code wajib diisi', 400);
            }

            $currentTenantCode = TenantContext::code();
            if ($currentTenantCode && $code !== $currentTenantCode) {
                return $this->jsonResponse->error('Forbidden: akses tenant lain tidak diizinkan', 403);
            }

            $tenant = $this->tenantModel
                ->select('id, code, name, logo_url, status, created_at, updated_at')
                ->where('code', $code)
                ->first();

            if (!$tenant) {
                return $this->jsonResponse->error('Tenant tidak ditemukan', 404);
            }

            $tenant['id'] = (int) $tenant['id'];

            $db = \Config\Database::connect();
            $waiting = $db->table('subscription_orders')
                ->select('id, external_transaction_id, status, amount, currency, created_at')
                ->where('tenant_id', (int) $tenant['id'])
                ->where('status', 'waiting_payment')
                ->orderBy('created_at', 'DESC')
                ->get()
                ->getRowArray();

            if ($waiting) {
                $waiting['id'] = (int) $waiting['id'];
                $waiting['amount'] = (float) $waiting['amount'];
            }

            return $this->jsonResponse->oneResp('Sukses', [
                'tenant' => $tenant,
                'waiting_payment_order' => $waiting ?: null,
            ], 200);
        } catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
