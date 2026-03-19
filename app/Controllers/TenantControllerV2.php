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
            $code = trim((string)($code ?? ''));
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

            $tenant['id'] = (int)$tenant['id'];

            $db = \Config\Database::connect();
            $waiting = $db->table('subscription_orders')
                ->select('id, external_transaction_id, status, amount, currency, created_at')
                ->where('tenant_id', (int)$tenant['id'])
                ->where('status', 'waiting_payment')
                ->orderBy('created_at', 'DESC')
                ->get()
                ->getRowArray();

            if ($waiting) {
                $waiting['id'] = (int)$waiting['id'];
                $waiting['amount'] = (float)$waiting['amount'];
            }

            return $this->jsonResponse->oneResp('Sukses', [
                'tenant' => $tenant,
                'waiting_payment_order' => $waiting ?: null,
            ], 200);
        }
        catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
    // POST /api/v2/tenant
    public function create()
    {
        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $input = $this->request->getPost();
            if (empty($input)) {
                $input = $this->request->getJSON(true);
            }

            $picName = $input['pic_name'] ?? '';
            $username = $input['username'] ?? '';

            // Check if user name or username already exists (global check)
            $existingUser = $db->table('users')
                ->where('name', $picName)
                ->orWhere('username', $username)
                ->get()
                ->getRowArray();

            if ($existingUser) {
                if ($existingUser['name'] === $picName) {
                    return $this->jsonResponse->error('Nama PIC sudah digunakan', 400);
                }
                if ($existingUser['username'] === $username) {
                    return $this->jsonResponse->error('Username sudah digunakan', 400);
                }
            }

            // 1. Generate Code
            $code = $this->generateTenantCode();

            // 2. Insert Tenant
            $tenantData = [
                'code' => $code,
                'name' => $input['toko_name'] ?? '',
                'logo_url' => $input['logo_url'] ?? '',
                'url' => $input['url'] ?? '',
                'email' => $input['email'] ?? '',
                'status' => 'active',
            ];

            $tenantId = $this->tenantModel->insert($tenantData);
            if (!$tenantId) {
                throw new \Exception('Gagal membuat tenant');
            }

            // 3. Insert Toko
            $tokoModel = new \App\Models\TokoModel();
            $tokoData = [
                'tenant_id' => $tenantId,
                'toko_name' => $input['toko_name'] ?? '',
                'alamat' => $input['alamat'] ?? '',
                'phone_number' => $input['phone_number'] ?? '',
                'email_toko' => $input['email'] ?? '',
                'image_logo' => $input['logo_url'] ?? '',
                'bank' => $input['bank'] ?? '',
                'nama_pemilik' => $input['nama_pemilik_rekening'] ?? '',
                'nomer_rekening' => $input['nomer_rekening'] ?? '',
                'provinsi' => $input['provinsi'] ?? '',
                'kota_kabupaten' => $input['kota_kabupaten'] ?? '',
                'kecamatan' => $input['kecamatan'] ?? '',
                'kelurahan' => $input['kelurahan'] ?? '',
                'kode_pos' => $input['kode_pos'] ?? '',
                'type' => 'CABANG',
            ];
            $tokoId = $tokoModel->insert($tokoData);
            if (!$tokoId) {
                throw new \Exception('Gagal membuat toko');
            }

            // 4. Insert User
            $userModel = new \App\Models\UserModel();
            $userData = [
                'tenant_id' => $tenantId,
                'name' => $input['pic_name'] ?? '',
                'username' => $input['username'] ?? '',
                'email' => $input['email'] ?? '',
                'password' => password_hash($input['password'] ?? '', PASSWORD_DEFAULT),
                'access' => json_encode([(string)$tokoId]),
                'permissions' => json_encode([
                    "dashboard.stats", "dashboard.performance", "dashboard.top_products",
                    "inventory.products.view", "inventory.products.create", "inventory.products.update", "inventory.products.delete",
                    "inventory.categories.view", "inventory.categories.create", "inventory.categories.update", "inventory.categories.delete",
                    "inventory.models.view", "inventory.models.create", "inventory.models.update", "inventory.models.delete",
                    "inventory.transfer.execute", "sales.invoice.view", "sales.invoice.create", "sales.invoice.payment",
                    "sales.invoice.cancel", "sales.invoice.refund", "sales.invoice.retur",
                    "sales.shipping.view", "sales.shipping.update", "purchase.view", "purchase.create",
                    "expense.view", "expense.create", "finance.view", "finance.journal.create",
                    "reports.view", "settings.view", "settings.toko", "settings.admin", "settings.pelanggan", "settings.suplier"
                ]),
            ];
            $userId = $userModel->insert($userData);
            if (!$userId) {
                throw new \Exception('Gagal membuat user');
            }

            $db->transCommit();

            return $this->jsonResponse->oneResp('Tenant berhasil dibuat', [
                'tenant_id' => $tenantId,
                'code' => $code,
                'toko_id' => $tokoId,
                'user_id' => $userId
            ], 201);
        }
        catch (\Throwable $e) {
            $db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    private function generateTenantCode()
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';

        $randomLetters = '';
        $length = rand(4, 8);
        for ($i = 0; $i < $length; $i++) {
            $randomLetters .= $letters[rand(0, strlen($letters) - 1)];
        }

        $randomNumbers = '';
        for ($i = 0; $i < 2; $i++) {
            $randomNumbers .= $numbers[rand(0, strlen($numbers) - 1)];
        }

        $generatedCode = $randomLetters . $randomNumbers;

        // Ensure uniqueness
        if ($this->tenantModel->where('code', $generatedCode)->first()) {
            return $this->generateTenantCode();
        }

        return $generatedCode;
    }
}