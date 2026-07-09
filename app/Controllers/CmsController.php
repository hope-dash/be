<?php

namespace App\Controllers;

use App\Libraries\VercelDeploy;

class CmsController extends BaseController
{
    protected $db;
    protected $session;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->db = \Config\Database::connect();
        $this->session = service('session');
    }

    // --- Auth ---

    public function login()
    {
        if ($this->session->get('cms_logged_in')) {
            return redirect()->to('/cms/dashboard');
        }
        return view('cms/login');
    }

    public function doLogin()
    {
        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        if ($username === 'admin' && $password === 'admin123') {
            $this->session->set('cms_logged_in', true);
            $this->session->set('cms_user', 'admin');
            return redirect()->to('/cms/dashboard');
        }

        return redirect()->back()->with('error', 'Username atau password salah');
    }

    public function logout()
    {
        $this->session->remove('cms_logged_in');
        $this->session->remove('cms_user');
        return redirect()->to('/cms/login');
    }

    // --- Dashboard ---

    public function dashboard()
    {
        $this->_auth();

        $totalTenants = $this->db->table('tenants')->countAllResults();
        $activeTenants = $this->db->table('tenants')->where('status', 'active')->countAllResults();
        $totalPackages = $this->db->table('subscription_packages')->countAllResults();
        $totalOrders = $this->db->table('subscription_orders')->countAllResults();
        $pendingOrders = $this->db->table('subscription_orders')->where('status', 'waiting_payment')->countAllResults();
        $activeSubscriptions = $this->db->table('tenant_subscriptions')->where('status', 'active')->countAllResults();

        return $this->_render('cms/dashboard', [
            'totalTenants' => $totalTenants,
            'activeTenants' => $activeTenants,
            'totalPackages' => $totalPackages,
            'totalOrders' => $totalOrders,
            'pendingOrders' => $pendingOrders,
            'activeSubscriptions' => $activeSubscriptions,
        ]);
    }

    // --- Tenants ---

    public function tenants()
    {
        $this->_auth();

        $search = $this->request->getGet('search');
        $status = $this->request->getGet('status');

        $builder = $this->db->table('tenants');
        if ($search) {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('code', $search)
                ->orLike('email', $search)
            ->groupEnd();
        }
        if ($status) {
            $builder->where('status', $status);
        }
        $builder->orderBy('created_at', 'DESC');
        $tenants = $builder->get()->getResultArray();

        return $this->_render('cms/tenants/index', ['tenants' => $tenants]);
    }

    public function tenantForm($id = null)
    {
        $this->_auth();

        $tenant = null;
        if ($id) {
            $tenant = $this->db->table('tenants')->where('id', $id)->get()->getRowArray();
            if (!$tenant) {
                return redirect()->to('/cms/tenants')->with('error', 'Tenant tidak ditemukan');
            }
        }

        return $this->_render('cms/tenants/form', ['tenant' => $tenant]);
    }

    public function tenantCreate()
    {
        $this->_auth();

        $input = $this->request->getPost();

        $tokoName = $input['toko_name'] ?? '';
        $picName = $input['pic_name'] ?? '';
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (!$tokoName || !$picName || !$username || !$password) {
            return redirect()->back()->withInput()->with('error', 'Semua field wajib diisi');
        }

        $existing = $this->db->table('users')
            ->groupStart()
                ->where('name', $picName)
                ->orWhere('username', $username)
            ->groupEnd()
            ->get()
            ->getRowArray();

        if ($existing) {
            $field = $existing['name'] === $picName ? 'Nama PIC' : 'Username';
            return redirect()->back()->withInput()->with('error', "$field sudah digunakan");
        }

        $this->db->transBegin();

        try {
            $code = $this->_generateTenantCode();

            $tenantId = $this->db->table('tenants')->insert([
                'code' => $code,
                'name' => $tokoName,
                'email' => $input['email'] ?? '',
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$tenantId) {
                throw new \Exception('Gagal membuat tenant');
            }
            $tenantId = $this->db->insertID();

            $tokoId = $this->db->table('toko')->insert([
                'tenant_id' => $tenantId,
                'toko_name' => $tokoName,
                'alamat' => $input['alamat'] ?? '',
                'phone_number' => $input['phone_number'] ?? '',
                'email_toko' => $input['email'] ?? '',
                'type' => 'CABANG',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$tokoId) {
                throw new \Exception('Gagal membuat toko');
            }
            $tokoId = $this->db->insertID();

            $accountModel = new \App\Models\AccountModel();
            $baseAccounts = $this->db->table('accounts')
                ->where('id_toko', null)
                ->where('tenant_id', null)
                ->get()
                ->getResultArray();
            foreach ($baseAccounts as $acc) {
                $baseCode = $acc['base_code'] ?? $acc['code'];
                $newCode = substr($baseCode, 0, 2) . $tokoId . substr($baseCode, 3);
                $newName = $acc['name'] . ' ' . $tokoName;
                $accountModel->insert([
                    'tenant_id' => $tenantId,
                    'id_toko' => $tokoId,
                    'base_code' => $baseCode,
                    'code' => $newCode,
                    'name' => $newName,
                    'type' => $acc['type'],
                    'normal_balance' => $acc['normal_balance'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $userId = $this->db->table('users')->insert([
                'tenant_id' => $tenantId,
                'name' => $picName,
                'username' => $username,
                'email' => $input['email'] ?? '',
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'access' => json_encode([(string) $tokoId]),
                'permissions' => json_encode([
                    "dashboard.stats", "dashboard.performance", "dashboard.top_products",
                    "inventory.products.view", "inventory.products.create", "inventory.products.update", "inventory.products.delete",
                    "inventory.categories.view", "inventory.categories.create", "inventory.categories.update", "inventory.categories.delete",
                    "inventory.models.view", "inventory.models.create", "inventory.models.update", "inventory.models.delete",
                    "inventory.transfer.execute", "sales.invoice.view", "sales.invoice.create", "sales.invoice.payment",
                    "sales.invoice.cancel", "sales.invoice.refund", "sales.invoice.retur",
                    "sales.shipping.view", "sales.shipping.update", "purchase.view", "purchase.create",
                    "expense.view", "expense.create", "finance.view", "finance.journal.create",
                    "reports.view", "settings.view", "settings.toko", "settings.admin", "settings.pelanggan", "settings.suplier",
                ]),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$userId) {
                throw new \Exception('Gagal membuat user');
            }

            // Auto-create 2 default domains + register to Vercel
            $shopDomain = strtolower($code) . '.satualur.my.id';
            $adminDomain = 'admin.' . strtolower($code) . '.satualur.my.id';

            $vercel = new \App\Libraries\VercelDeploy();
            $domainRecords = [];

            foreach ([
                ['domain' => $shopDomain, 'type' => 'shop'],
                ['domain' => $adminDomain, 'type' => 'admin'],
            ] as $d) {
                $vercelId = null;
                $verified = 0;
                if ($vercel->isConfigured()) {
                    $result = $vercel->addDomain($d['domain'], $d['type']);
                    if ($result['success']) {
                        $vercelId = $result['data']['uid'] ?? $result['data']['name'] ?? null;
                        $verified = !empty($result['data']['verified']) ? 1 : 0;
                    }
                }
                $domainRecords[] = [
                    'tenant_id' => $tenantId,
                    'tenant_code' => $code,
                    'domain' => $d['domain'],
                    'type' => $d['type'],
                    'is_verified' => $verified,
                    'vercel_domain_id' => $vercelId,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }

            $this->db->table('tenant_domains')->insertBatch($domainRecords);

            $this->db->transCommit();

            return redirect()->to('/cms/tenants/' . $tenantId)->with('success', 'Tenant berhasil dibuat');
        } catch (\Throwable $e) {
            $this->db->transRollback();
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function tenantUpdate($id)
    {
        $this->_auth();

        $tenant = $this->db->table('tenants')->where('id', $id)->get()->getRowArray();
        if (!$tenant) {
            return redirect()->to('/cms/tenants')->with('error', 'Tenant tidak ditemukan');
        }

        $input = $this->request->getPost();
        $this->db->table('tenants')->where('id', $id)->update([
            'name' => $input['name'] ?? $tenant['name'],
            'email' => $input['email'] ?? $tenant['email'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to('/cms/tenants/' . $id)->with('success', 'Tenant berhasil diupdate');
    }

    public function tenantDetail($id)
    {
        $this->_auth();

        $tenant = $this->db->table('tenants')->where('id', $id)->get()->getRowArray();
        if (!$tenant) {
            return redirect()->to('/cms/tenants')->with('error', 'Tenant tidak ditemukan');
        }

        $subscriptions = $this->db->table('tenant_subscriptions ts')
            ->select('ts.*, sp.name as package_name, sp.price')
            ->join('subscription_packages sp', 'sp.id = ts.package_id', 'left')
            ->where('ts.tenant_id', $id)
            ->orderBy('ts.created_at', 'DESC')
            ->get()
            ->getResultArray();

        $quota = $this->db->table('tenant_quota')
            ->where('tenant_id', $id)
            ->orderBy('month_start', 'DESC')
            ->get()
            ->getResultArray();

        $users = $this->db->table('users')
            ->where('tenant_id', $id)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        $tokos = $this->db->table('toko')
            ->where('tenant_id', $id)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        $domains = $this->db->table('tenant_domains')
            ->where('tenant_id', $id)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->_render('cms/tenants/detail', [
            'tenant' => $tenant,
            'subscriptions' => $subscriptions,
            'quota' => $quota,
            'users' => $users,
            'tokos' => $tokos,
            'domains' => $domains,
        ]);
    }

    public function tenantToggleStatus($id)
    {
        $this->_auth();

        $tenant = $this->db->table('tenants')->where('id', $id)->get()->getRowArray();
        if (!$tenant) {
            return redirect()->to('/cms/tenants')->with('error', 'Tenant tidak ditemukan');
        }

        $newStatus = $tenant['status'] === 'active' ? 'inactive' : 'active';
        $this->db->table('tenants')->where('id', $id)->update([
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()->with('success', 'Status tenant berhasil diubah ke ' . $newStatus);
    }

    public function tenantDelete($id)
    {
        $this->_auth();

        $tenant = $this->db->table('tenants')->where('id', $id)->get()->getRowArray();
        if (!$tenant) {
            return redirect()->to('/cms/tenants')->with('error', 'Tenant tidak ditemukan');
        }

        $domains = $this->db->table('tenant_domains')
            ->where('tenant_id', $id)
            ->get()
            ->getResultArray();

        $vercel = new \App\Libraries\VercelDeploy();
        foreach ($domains as $d) {
            if (!empty($d['vercel_domain_id'])) {
                $vercel->removeDomain($d['domain'], $d['type']);
            }
        }

        $this->db->table('tenant_domains')->where('tenant_id', $id)->delete();
        $this->db->table('tenants')->where('id', $id)->update([
            'status' => 'inactive',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to('/cms/tenants')->with('success', 'Tenant ' . esc($tenant['name']) . ' berhasil dinonaktifkan dan domain dihapus.');
    }

    public function tenantUserCreate($tenantId)
    {
        $this->_auth();

        $tenant = $this->db->table('tenants')->where('id', $tenantId)->get()->getRowArray();
        if (!$tenant) {
            return redirect()->to('/cms/tenants')->with('error', 'Tenant tidak ditemukan');
        }

        $input = $this->request->getPost();
        $name = $input['name'] ?? '';
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (!$name || !$username || !$password) {
            return redirect()->back()->with('error', 'Semua field wajib diisi');
        }

        $existing = $this->db->table('users')
            ->where('username', $username)
            ->get()
            ->getRowArray();
        if ($existing) {
            return redirect()->back()->with('error', 'Username sudah digunakan');
        }

        $tokoIds = $this->db->table('toko')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $access = array_map(function ($t) { return (string) $t['id']; }, $tokoIds);

        $this->db->table('users')->insert([
            'tenant_id' => $tenantId,
            'name' => $name,
            'username' => $username,
            'email' => $input['email'] ?? '',
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'access' => json_encode($access),
            'permissions' => json_encode(['*']),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()->with('success', 'User berhasil dibuat');
    }

    public function tenantUserResetPassword($tenantId, $userId)
    {
        $this->_auth();

        $password = $this->request->getPost('password');
        if (!$password) {
            return redirect()->back()->with('error', 'Password wajib diisi');
        }

        $user = $this->db->table('users')
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();
        if (!$user) {
            return redirect()->back()->with('error', 'User tidak ditemukan');
        }

        $this->db->table('users')
            ->where('user_id', $userId)
            ->update([
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return redirect()->back()->with('success', 'Password berhasil direset');
    }

    public function tenantTokoCreate($tenantId)
    {
        $this->_auth();

        $tenant = $this->db->table('tenants')->where('id', $tenantId)->get()->getRowArray();
        if (!$tenant) {
            return redirect()->to('/cms/tenants')->with('error', 'Tenant tidak ditemukan');
        }

        $input = $this->request->getPost();
        $tokoName = $input['toko_name'] ?? '';

        if (!$tokoName) {
            return redirect()->back()->with('error', 'Nama toko wajib diisi');
        }

        $tokoId = $this->db->table('toko')->insert([
            'tenant_id' => $tenantId,
            'toko_name' => $tokoName,
            'alamat' => $input['alamat'] ?? '',
            'phone_number' => $input['phone_number'] ?? '',
            'email_toko' => $input['email'] ?? '',
            'type' => 'CABANG',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$tokoId) {
            return redirect()->back()->with('error', 'Gagal membuat toko');
        }

        $tokoId = $this->db->insertID();

        $accountModel = new \App\Models\AccountModel();
        $baseAccounts = $this->db->table('accounts')
            ->where('id_toko', null)
            ->where('tenant_id', null)
            ->get()
            ->getResultArray();
        foreach ($baseAccounts as $acc) {
            $baseCode = $acc['base_code'] ?? $acc['code'];
            $newCode = substr($baseCode, 0, 2) . $tokoId . substr($baseCode, 3);
            $newName = $acc['name'] . ' ' . $tokoName;
            $accountModel->insert([
                'tenant_id' => $tenantId,
                'id_toko' => $tokoId,
                'base_code' => $baseCode,
                'code' => $newCode,
                'name' => $newName,
                'type' => $acc['type'],
                'normal_balance' => $acc['normal_balance'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return redirect()->back()->with('success', 'Toko berhasil dibuat');
    }

    // --- Domain CRUD ---

    public function tenantDomains($tenantId)
    {
        $this->_auth();

        $domains = $this->db->table('tenant_domains')
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => true,
            'data' => $domains,
        ]);
    }

    public function tenantDomainCreate($tenantId)
    {
        $this->_auth();

        $tenant = $this->db->table('tenants')->where('id', $tenantId)->get()->getRowArray();
        if (!$tenant) {
            return $this->response->setJSON(['status' => false, 'message' => 'Tenant tidak ditemukan']);
        }

        $input = $this->request->getPost();
        $domain = trim($input['domain'] ?? '');
        $type = trim($input['type'] ?? '');

        if (!$domain || !in_array($type, ['admin', 'shop', 'main'])) {
            return $this->response->setJSON(['status' => false, 'message' => 'Domain dan type wajib diisi (admin/shop/main)']);
        }

        $existing = $this->db->table('tenant_domains')
            ->where('domain', $domain)
            ->get()
            ->getRowArray();
        if ($existing) {
            return $this->response->setJSON(['status' => false, 'message' => 'Domain sudah terdaftar']);
        }

        // Call Vercel API
        $vercel = new \App\Libraries\VercelDeploy();
        if ($vercel->isConfigured()) {
            $result = $vercel->addDomain($domain, $type);
            if (!$result['success']) {
                $errMsg = $result['data']['error']['message'] ?? 'Gagal menambahkan domain ke Vercel';
                return $this->response->setJSON(['status' => false, 'message' => $errMsg]);
            }
            $vercelDomainId = $result['data']['uid'] ?? $result['data']['name'] ?? null;
            $isVerified = !empty($result['data']['verified']) ? 1 : 0;
        } else {
            $vercelDomainId = null;
            $isVerified = 0;
        }

        $this->db->table('tenant_domains')->insert([
            'tenant_id' => $tenantId,
            'tenant_code' => $tenant['code'],
            'domain' => $domain,
            'type' => $type,
            'is_verified' => $isVerified,
            'vercel_domain_id' => $vercelDomainId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $msg = $vercel->isConfigured() ? 'Domain berhasil ditambahkan ke Vercel' : 'Domain disimpan (Vercel tidak dikonfigurasi)';
        return $this->response->setJSON(['status' => true, 'message' => $msg]);
    }

    public function tenantDomainDelete($tenantId, $domainId)
    {
        $this->_auth();

        $domain = $this->db->table('tenant_domains')
            ->where('id', $domainId)
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();
        if (!$domain) {
            return $this->response->setJSON(['status' => false, 'message' => 'Domain tidak ditemukan'], 404);
        }

        // Remove from Vercel API if it was added there
        $vercel = new \App\Libraries\VercelDeploy();
        if ($vercel->isConfigured() && !empty($domain['vercel_domain_id'])) {
            $vercel->removeDomain($domain['domain'], $domain['type']);
        }

        $this->db->table('tenant_domains')->where('id', $domainId)->delete();

        return $this->response->setJSON(['status' => true, 'message' => 'Domain berhasil dihapus']);
    }

    // --- Packages ---

    public function packages()
    {
        $this->_auth();

        $packages = $this->db->table('subscription_packages')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->_render('cms/packages/index', ['packages' => $packages]);
    }

    public function packageForm($id = null)
    {
        $this->_auth();

        $package = null;
        if ($id) {
            $package = $this->db->table('subscription_packages')->where('id', $id)->get()->getRowArray();
            if (!$package) {
                return redirect()->to('/cms/packages')->with('error', 'Paket tidak ditemukan');
            }
        }

        $integrationFields = $this->_integrationFields();
        $selectedFeatures = [];
        $detailItems = [];
        $multiTokoValue = 1;
        if ($package && !empty($package['description'])) {
            $decoded = json_decode($package['description'], true);
            if (is_array($decoded)) {
                // New format: { features: [...], details: [...] }
                if (isset($decoded['features'])) {
                    $selectedFeatures = $decoded['features'];
                    $detailItems = $decoded['details'] ?? [];
                    $multiTokoValue = (int) ($decoded['multi_toko'] ?? 1);
                } else {
                    // Old format: flat array of feature keys
                    $selectedFeatures = $decoded;
                }
            }
        }

        return $this->_render('cms/packages/form', [
            'package' => $package,
            'integrationFields' => $integrationFields,
            'selectedFeatures' => $selectedFeatures,
            'detailItems' => $detailItems,
            'multiTokoValue' => $multiTokoValue,
        ]);
    }

    public function packageCreate()
    {
        $this->_auth();

        $input = $this->request->getPost();
        $name = $input['name'] ?? '';
        $price = $input['price'] ?? 0;
        $duration = $input['duration_months'] ?? 1;
        $productQuota = $input['product_quota'] ?? 0;
        $transactionQuota = $input['transaction_monthly_quota'] ?? 0;

        if (!$name) {
            return redirect()->back()->withInput()->with('error', 'Nama paket wajib diisi');
        }

        $features = $input['features'] ?? [];
        $details = array_values(array_filter($input['detail_items'] ?? [], fn($v) => trim($v) !== ''));
        $multiToko = max(1, (int) ($input['multi_toko'] ?? 1));
        $description = json_encode([
            'features' => $features,
            'details' => $details,
            'multi_toko' => $multiToko,
        ]);

        $code = strtoupper(str_replace(' ', '_', $name)) . '_' . rand(100, 999);

        $this->db->table('subscription_packages')->insert([
            'code' => $code,
            'name' => $name,
            'price' => (float) $price,
            'currency' => 'IDR',
            'duration_months' => (int) $duration,
            'product_quota' => (int) $productQuota,
            'transaction_monthly_quota' => (int) $transactionQuota,
            'description' => $description,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to('/cms/packages')->with('success', 'Paket berhasil dibuat');
    }

    public function packageUpdate($id)
    {
        $this->_auth();

        $package = $this->db->table('subscription_packages')->where('id', $id)->get()->getRowArray();
        if (!$package) {
            return redirect()->to('/cms/packages')->with('error', 'Paket tidak ditemukan');
        }

        $input = $this->request->getPost();
        $features = $input['features'] ?? [];
        $details = array_values(array_filter($input['detail_items'] ?? [], fn($v) => trim($v) !== ''));
        $multiToko = max(1, (int) ($input['multi_toko'] ?? 1));
        $description = json_encode([
            'features' => $features,
            'details' => $details,
            'multi_toko' => $multiToko,
        ]);

        $this->db->table('subscription_packages')->where('id', $id)->update([
            'name' => $input['name'] ?? $package['name'],
            'price' => isset($input['price']) ? (float) $input['price'] : $package['price'],
            'duration_months' => isset($input['duration_months']) ? (int) $input['duration_months'] : $package['duration_months'],
            'product_quota' => isset($input['product_quota']) ? (int) $input['product_quota'] : $package['product_quota'],
            'transaction_monthly_quota' => isset($input['transaction_monthly_quota']) ? (int) $input['transaction_monthly_quota'] : $package['transaction_monthly_quota'],
            'description' => $description,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to('/cms/packages')->with('success', 'Paket berhasil diupdate');
    }

    public function packageToggle($id)
    {
        $this->_auth();

        $package = $this->db->table('subscription_packages')->where('id', $id)->get()->getRowArray();
        if (!$package) {
            return redirect()->to('/cms/packages')->with('error', 'Paket tidak ditemukan');
        }

        $newStatus = $package['is_active'] ? 0 : 1;
        $this->db->table('subscription_packages')->where('id', $id)->update([
            'is_active' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()->with('success', 'Status paket berhasil diubah');
    }

    // --- Subscriptions ---

    public function subscriptions()
    {
        $this->_auth();

        $subscriptions = $this->db->table('tenant_subscriptions ts')
            ->select('ts.*, sp.name as package_name, sp.price, t.name as tenant_name, t.code as tenant_code')
            ->join('subscription_packages sp', 'sp.id = ts.package_id', 'left')
            ->join('tenants t', 't.id = ts.tenant_id', 'left')
            ->orderBy('ts.created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->_render('cms/subscriptions/index', ['subscriptions' => $subscriptions]);
    }

    // --- Orders ---

    public function orders()
    {
        $this->_auth();

        $orders = $this->db->table('subscription_orders so')
            ->select('so.*, sp.name as package_name, sp.price, t.name as tenant_name, t.code as tenant_code')
            ->join('subscription_packages sp', 'sp.id = so.package_id', 'left')
            ->join('tenants t', 't.id = so.tenant_id', 'left')
            ->orderBy('so.created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->_render('cms/orders/index', ['orders' => $orders]);
    }

    public function orderApprove($id)
    {
        $this->_auth();

        $order = $this->db->table('subscription_orders')->where('id', $id)->get()->getRowArray();
        if (!$order) {
            return redirect()->back()->with('error', 'Order tidak ditemukan');
        }

        $this->db->transBegin();
        try {
            $this->db->table('subscription_orders')->where('id', $id)->update([
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $package = $this->db->table('subscription_packages')->where('id', $order['package_id'])->get()->getRowArray();
            $now = date('Y-m-d H:i:s');
            $endAt = date('Y-m-d H:i:s', strtotime('+' . ($package['duration_months'] ?? 1) . ' months'));

            $this->db->table('tenant_subscriptions')->insert([
                'tenant_id' => $order['tenant_id'],
                'package_id' => $order['package_id'],
                'status' => 'active',
                'start_at' => $now,
                'end_at' => $endAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $existingQuota = $this->db->table('tenant_quota')
                ->where('tenant_id', $order['tenant_id'])
                ->where('month_start', date('Y-m-01'))
                ->get()
                ->getRowArray();

            if ($existingQuota) {
                $this->db->table('tenant_quota')
                    ->where('id', $existingQuota['id'])
                    ->update([
                        'product_quota' => ($existingQuota['product_quota'] ?? 0) + ($package['product_quota'] ?? 0),
                        'transaction_monthly_quota' => ($existingQuota['transaction_monthly_quota'] ?? 0) + ($package['transaction_monthly_quota'] ?? 0),
                        'updated_at' => $now,
                    ]);
            } else {
                $this->db->table('tenant_quota')->insert([
                    'tenant_id' => $order['tenant_id'],
                    'month_start' => date('Y-m-01'),
                    'product_quota' => $package['product_quota'] ?? 0,
                    'product_used' => 0,
                    'transaction_monthly_quota' => $package['transaction_monthly_quota'] ?? 0,
                    'transaction_monthly_used' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->db->transCommit();
            return redirect()->back()->with('success', 'Order berhasil disetujui');
        } catch (\Throwable $e) {
            $this->db->transRollback();
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function orderCancel($id)
    {
        $this->_auth();

        $order = $this->db->table('subscription_orders')->where('id', $id)->get()->getRowArray();
        if (!$order) {
            return redirect()->back()->with('error', 'Order tidak ditemukan');
        }

        $this->db->table('subscription_orders')->where('id', $id)->update([
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()->with('success', 'Order berhasil dibatalkan');
    }

    // --- Private Helpers ---

    private function _auth()
    {
        if (!session()->get('cms_logged_in')) {
            redirect()->to('/cms/login')
                ->with('error', 'Silakan login terlebih dahulu')
                ->send();
            exit;
        }
    }

    private function _render($view, $data = [])
    {
        $data['content'] = view($view, $data);
        return view('cms/layouts/main', $data);
    }

    private function _parseDescription($package)
    {
        if (empty($package['description'])) {
            return [];
        }
        $decoded = json_decode($package['description'], true);
        if (!is_array($decoded)) {
            return [];
        }
        $fields = $this->_integrationFields();
        $result = [];
        foreach ($decoded as $key) {
            if (isset($fields[$key])) {
                $result[$key] = $fields[$key];
            }
        }
        return $result;
    }

    private function _integrationFields()
    {
        return [
            'whatsapp' => 'WhatsApp API',
            'email' => 'Email Notifikasi',
            'shopee' => 'Shopee',
            'tiktok' => 'TikTok Shop',
            'moota' => 'Moota (Auto Detect Bank)',
            'laporan' => 'Laporan Keuangan',
            'service_on' => 'Service',
        ];
    }

    private function _generateTenantCode()
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

        if ($this->db->table('tenants')->where('code', $generatedCode)->get()->getRowArray()) {
            return $this->_generateTenantCode();
        }

        return $generatedCode;
    }
}
