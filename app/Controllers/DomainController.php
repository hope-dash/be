<?php

namespace App\Controllers;

use App\Libraries\TenantContext;
use App\Models\JsonResponse;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\RESTful\ResourceController;

class DomainController extends ResourceController
{
    use ResponseTrait;

    protected JsonResponse $jsonResponse;
    protected $db;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // GET /api/v2/resolve-domain?domain=xxx
    public function resolve()
    {
        try {
            $domain = trim((string) $this->request->getGet('domain'));
            if ($domain === '') {
                return $this->jsonResponse->error('Parameter domain wajib diisi', 400);
            }

            $row = $this->db->table('tenant_domains td')
                ->select('td.*, t.name as tenant_name, t.logo_url, t.status as tenant_status')
                ->join('tenants t', 't.id = td.tenant_id', 'inner')
                ->where('td.domain', $domain)
                ->get()
                ->getRowArray();

            if (!$row) {
                return $this->jsonResponse->error('Domain tidak ditemukan', 404);
            }

            return $this->jsonResponse->oneResp('Sukses', [
                'tenant_id' => (int) $row['tenant_id'],
                'tenant_code' => $row['tenant_code'],
                'tenant_name' => $row['tenant_name'],
                'logo_url' => $row['logo_url'],
                'type' => $row['type'],
                'is_verified' => (bool) $row['is_verified'],
            ], 200);
        } catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
