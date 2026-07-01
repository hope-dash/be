<?php

namespace App\Controllers;

use App\Libraries\SubscriptionService;
use App\Models\SubscriptionPackageModel;
use App\Models\TenantModel;

class CmsController extends BaseController
{
    protected TenantModel $tenantModel;
    protected SubscriptionPackageModel $packageModel;
    protected $db;

    public function __construct()
    {
        $this->tenantModel = new TenantModel();
        $this->packageModel = new SubscriptionPackageModel();
        $this->db = \Config\Database::connect();
        helper(['form', 'url']);
    }

    // ---------------------------------------------------------------
    // AUTH
    // ---------------------------------------------------------------

    public function login()
    {
        if (session()->get('cms_logged_in')) {
            return redirect()->to('/cms/dashboard');
        }
        return view('cms/login');
    }

    public function doLogin()
    {
        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        if ($username === 'admin' && $password === 'admin123') {
            session()->set('cms_logged_in', true);
            session()->set('cms_user', [
                'name' => 'Super Admin',
                'username' => 'admin',
            ]);
            return redirect()->to('/cms/dashboard');
        }

        session()->setFlashdata('error', 'Username atau password salah.');
        return redirect()->to('/cms/login');
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/cms/login');
    }

    // ---------------------------------------------------------------
    // DASHBOARD
    // ---------------------------------------------------------------

    public function dashboard()
    {
        $this->_auth();

        $totalTenants = $this->tenantModel->countAll();
        $activeTenants = $this->tenantModel->where('status', 'active')->countAllResults();

        $totalPackages = $this->packageModel->countAll();
        $activePackages = $this->packageModel->where('is_active', 1)->countAllResults();

        $activeSubs = $this->db->table('tenant_subscriptions')
            ->where('status', 'active')
            ->countAllResults();

        $paidRevenue = $this->db->table('subscription_orders')
            ->select('COALESCE(SUM(amount), 0) as total')
            ->where('status', 'paid')
            ->get()
            ->getRowArray();

        $pendingOrders = $this->db->table('subscription_orders')
            ->where('status', 'waiting_payment')
            ->countAllResults();

        $recentTenants = $this->tenantModel
            ->orderBy('created_at', 'DESC')
            ->findAll(5);

        $recentOrders = $this->db->table('subscription_orders so')
            ->select('so.*, t.name as tenant_name, t.code as tenant_code, sp.name as package_name')
            ->join('tenants t', 't.id = so.tenant_id', 'left')
            ->join('subscription_packages sp', 'sp.id = so.package_id', 'left')
            ->orderBy('so.created_at', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        return $this->_render('cms/dashboard', [
            'title' => 'Dashboard',
            'totalTenants' => $totalTenants,
            'activeTenants' => $activeTenants,
            'totalPackages' => $totalPackages,
            'activePackages' => $activePackages,
            'activeSubs' => $activeSubs,
            'paidRevenue' => $paidRevenue['total'] ?? 0,
            'pendingOrders' => $pendingOrders,
            'recentTenants' => $recentTenants,
            'recentOrders' => $recentOrders,
        ]);
    }

    // ---------------------------------------------------------------
    // TENANTS
    // ---------------------------------------------------------------

    public function tenants()
    {
        $this->_auth();

        $search = $this->request->getGet('search');
        $status = $this->request->getGet('status');

        $builder = $this->tenantModel->orderBy('created_at', 'DESC');

        if ($search) {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('code', $search)
                ->orLike('email', $search)
                ->groupEnd();
        }
        if ($status && in_array($status, ['active', 'inactive'])) {
            $builder->where('status', $status);
        }

        $tenants = $builder->paginate(20);
        $pager = $this->tenantModel->pager;

        return $this->_render('cms/tenants/index', [
            'title' => 'Tenants',
            'tenants' => $tenants,
            'pager' => $pager,
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function tenantForm($id = null)
    {
        $this->_auth();

        $tenant = null;
        if ($id) {
            $tenant = $this->tenantModel->find($id);
            if (!$tenant) {
                session()->setFlashdata('error', 'Tenant tidak ditemukan.');
                return redirect()->to('/cms/tenants');
            }
        }

        return $this->_render('cms/tenants/form', [
            'title' => $id ? 'Edit Tenant' : 'Buat Tenant',
            'tenant' => $tenant,
        ]);
    }

    public function tenantCreate()
    {
        $this->_auth();

        $data = $this->request->getPost();

        if (empty($data['name']) || empty($data['code'])) {
            session()->setFlashdata('error', 'Nama dan kode tenant wajib diisi.');
            return redirect()->back()->withInput();
        }

        if (empty($data['username']) || empty($data['password'])) {
            session()->setFlashdata('error', 'Username dan password admin wajib diisi.');
            return redirect()->back()->withInput();
        }

        $existing = $this->tenantModel->where('code', $data['code'])->first();
        if ($existing) {
            session()->setFlashdata('error', 'Kode tenant sudah digunakan.');
            return redirect()->back()->withInput();
        }

        $userCheck = $this->db->table('users')
            ->where('username', $data['username'])
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($userCheck) {
            session()->setFlashdata('error', 'Username sudah digunakan oleh tenant lain.');
            return redirect()->back()->withInput();
        }

        $this->db->transStart();
        try {
            $tenantId = $this->tenantModel->insert([
                'code' => $data['code'],
                'name' => $data['name'],
                'email' => $data['email'] ?? '',
                'url' => $data['url'] ?? '',
                'logo_url' => $data['logo_url'] ?? '',
                'status' => $data['status'] ?? 'active',
            ]);

            $this->db->table('toko')->insert([
                'tenant_id' => $tenantId,
                'toko_name' => $data['name'],
                'alamat' => $data['alamat'] ?? '',
                'phone_number' => $data['phone_number'] ?? '',
                'email_toko' => $data['email'] ?? '',
                'type' => 'CABANG',
            ]);
            $tokoId = (int)$this->db->insertID();

            $picName = $data['pic_name'] ?? $data['name'];
            $defaultPermissions = [
                "dashboard.stats", "dashboard.performance", "dashboard.top_products",
                "inventory.products.view", "inventory.products.create", "inventory.products.update", "inventory.products.delete",
                "inventory.categories.view", "inventory.categories.create", "inventory.categories.update", "inventory.categories.delete",
                "inventory.models.view", "inventory.models.create", "inventory.models.update", "inventory.models.delete",
                "inventory.transfer.execute", "sales.invoice.view", "sales.invoice.create", "sales.invoice.payment",
                "sales.invoice.cancel", "sales.invoice.refund", "sales.invoice.retur",
                "sales.shipping.view", "sales.shipping.update", "purchase.view", "purchase.create",
                "expense.view", "expense.create", "finance.view", "finance.journal.create",
                "reports.view", "settings.view", "settings.toko", "settings.admin", "settings.pelanggan", "settings.suplier"
            ];
            $this->db->table('users')->insert([
                'tenant_id' => $tenantId,
                'name' => $picName,
                'username' => $data['username'],
                'email' => $data['email'] ?? '',
                'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'access' => json_encode([(string)$tokoId]),
                'permissions' => json_encode($defaultPermissions),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->db->transComplete();
            session()->setFlashdata('success', 'Tenant, toko, dan akun admin berhasil dibuat.');
        } catch (\Throwable $e) {
            $this->db->transRollback();
            session()->setFlashdata('error', 'Gagal membuat tenant: ' . $e->getMessage());
        }

        return redirect()->to('/cms/tenants');
    }

    public function tenantUpdate($id)
    {
        $this->_auth();

        $tenant = $this->tenantModel->find($id);
        if (!$tenant) {
            session()->setFlashdata('error', 'Tenant tidak ditemukan.');
            return redirect()->to('/cms/tenants');
        }

        $data = $this->request->getPost();

        if (empty($data['name'])) {
            session()->setFlashdata('error', 'Nama tenant wajib diisi.');
            return redirect()->back()->withInput();
        }

        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'] ?? '',
            'url' => $data['url'] ?? '',
            'logo_url' => $data['logo_url'] ?? '',
        ];

        if (!empty($data['code']) && $data['code'] !== $tenant['code']) {
            $existing = $this->tenantModel->where('code', $data['code'])->first();
            if ($existing) {
                session()->setFlashdata('error', 'Kode tenant sudah digunakan oleh tenant lain.');
                return redirect()->back()->withInput();
            }
            $updateData['code'] = $data['code'];
        }

        $this->tenantModel->update($id, $updateData);

        session()->setFlashdata('success', 'Tenant berhasil diperbarui.');
        return redirect()->to('/cms/tenants');
    }

    public function tenantDetail($id)
    {
        $this->_auth();

        $tenant = $this->tenantModel->find($id);
        if (!$tenant) {
            session()->setFlashdata('error', 'Tenant tidak ditemukan.');
            return redirect()->to('/cms/tenants');
        }

        $subscriptions = $this->db->table('tenant_subscriptions ts')
            ->select('ts.*, sp.name as package_name, sp.code as package_code, sp.price as package_price')
            ->join('subscription_packages sp', 'sp.id = ts.package_id', 'left')
            ->where('ts.tenant_id', $id)
            ->orderBy('ts.created_at', 'DESC')
            ->get()
            ->getResultArray();

        $activeSub = null;
        foreach ($subscriptions as $sub) {
            if ($sub['status'] === 'active') {
                $activeSub = $sub;
                break;
            }
        }

        $orders = $this->db->table('subscription_orders so')
            ->select('so.*, sp.name as package_name')
            ->join('subscription_packages sp', 'sp.id = so.package_id', 'left')
            ->where('so.tenant_id', $id)
            ->orderBy('so.created_at', 'DESC')
            ->get()
            ->getResultArray();

        $quotaRows = $this->db->table('tenant_quota')
            ->where('tenant_id', $id)
            ->orderBy('month_start', 'DESC')
            ->limit(12)
            ->get()
            ->getResultArray();

        $users = $this->db->table('users')
            ->where('tenant_id', $id)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        $tokos = $this->db->table('toko')
            ->where('tenant_id', $id)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->_render('cms/tenants/detail', [
            'title' => 'Tenant: ' . $tenant['name'],
            'tenant' => $tenant,
            'subscriptions' => $subscriptions,
            'activeSub' => $activeSub,
            'orders' => $orders,
            'quotaRows' => $quotaRows,
            'users' => $users,
            'tokos' => $tokos,
        ]);
    }

    public function tenantToggleStatus($id)
    {
        $this->_auth();

        $tenant = $this->tenantModel->find($id);
        if (!$tenant) {
            session()->setFlashdata('error', 'Tenant tidak ditemukan.');
            return redirect()->to('/cms/tenants');
        }

        $newStatus = $tenant['status'] === 'active' ? 'inactive' : 'active';
        $this->tenantModel->update($id, ['status' => $newStatus]);

        session()->setFlashdata('success', "Status tenant diubah menjadi {$newStatus}.");
        return redirect()->back();
    }

    // ---------------------------------------------------------------
    // TENANT USERS
    // ---------------------------------------------------------------

    public function tenantUserCreate($tenantId)
    {
        $this->_auth();

        $tenant = $this->tenantModel->find($tenantId);
        if (!$tenant) {
            session()->setFlashdata('error', 'Tenant tidak ditemukan.');
            return redirect()->to('/cms/tenants');
        }

        $data = $this->request->getPost();

        if (empty($data['name']) || empty($data['username']) || empty($data['password'])) {
            session()->setFlashdata('error', 'Nama, username, dan password wajib diisi.');
            return redirect()->to('/cms/tenants/' . $tenantId);
        }

        $existing = $this->db->table('users')
            ->where('username', $data['username'])
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($existing) {
            session()->setFlashdata('error', 'Username sudah digunakan.');
            return redirect()->to('/cms/tenants/' . $tenantId);
        }

        $toko = $this->db->table('toko')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();

        $tokoId = $toko ? (string)$toko['id'] : '1';

        $defaultPermissions = [
            "dashboard.stats", "dashboard.performance", "dashboard.top_products",
            "inventory.products.view", "inventory.products.create", "inventory.products.update", "inventory.products.delete",
            "inventory.categories.view", "inventory.categories.create", "inventory.categories.update", "inventory.categories.delete",
            "inventory.models.view", "inventory.models.create", "inventory.models.update", "inventory.models.delete",
            "inventory.transfer.execute", "sales.invoice.view", "sales.invoice.create", "sales.invoice.payment",
            "sales.invoice.cancel", "sales.invoice.refund", "sales.invoice.retur",
            "sales.shipping.view", "sales.shipping.update", "purchase.view", "purchase.create",
            "expense.view", "expense.create", "finance.view", "finance.journal.create",
            "reports.view", "settings.view", "settings.toko", "settings.admin", "settings.pelanggan", "settings.suplier"
        ];

        $this->db->table('users')->insert([
            'tenant_id' => (int)$tenantId,
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? '',
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'access' => json_encode([$tokoId]),
            'permissions' => json_encode($defaultPermissions),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        session()->setFlashdata('success', 'Akun user berhasil dibuat.');
        return redirect()->to('/cms/tenants/' . $tenantId);
    }

    public function tenantUserResetPassword($tenantId, $userId)
    {
        $this->_auth();

        $tenant = $this->tenantModel->find($tenantId);
        if (!$tenant) {
            session()->setFlashdata('error', 'Tenant tidak ditemukan.');
            return redirect()->to('/cms/tenants');
        }

        $user = $this->db->table('users')
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if (!$user) {
            session()->setFlashdata('error', 'User tidak ditemukan.');
            return redirect()->to('/cms/tenants/' . $tenantId);
        }

        $newPassword = 'password123';
        $this->db->table('users')
            ->where('user_id', $userId)
            ->update(['password' => password_hash($newPassword, PASSWORD_DEFAULT)]);

        session()->setFlashdata('success', "Password user {$user['name']} direset menjadi <strong>{$newPassword}</strong>.");
        return redirect()->to('/cms/tenants/' . $tenantId);
    }

    // ---------------------------------------------------------------
    // TENANT TOKO
    // ---------------------------------------------------------------

    public function tenantTokoCreate($tenantId)
    {
        $this->_auth();

        $tenant = $this->tenantModel->find($tenantId);
        if (!$tenant) {
            session()->setFlashdata('error', 'Tenant tidak ditemukan.');
            return redirect()->to('/cms/tenants');
        }

        $data = $this->request->getPost();

        if (empty($data['toko_name'])) {
            session()->setFlashdata('error', 'Nama toko wajib diisi.');
            return redirect()->to('/cms/tenants/' . $tenantId);
        }

        $this->db->table('toko')->insert([
            'tenant_id' => (int)$tenantId,
            'toko_name' => $data['toko_name'],
            'alamat' => $data['alamat'] ?? '',
            'phone_number' => $data['phone_number'] ?? '',
            'email_toko' => $data['email_toko'] ?? '',
            'provinsi' => $data['provinsi'] ?? '',
            'kota_kabupaten' => $data['kota_kabupaten'] ?? '',
            'kecamatan' => $data['kecamatan'] ?? '',
            'kelurahan' => $data['kelurahan'] ?? '',
            'kode_pos' => $data['kode_pos'] ?? '',
            'type' => 'CABANG',
        ]);

        session()->setFlashdata('success', 'Toko berhasil ditambahkan.');
        return redirect()->to('/cms/tenants/' . $tenantId);
    }

    // ---------------------------------------------------------------
    // PACKAGES
    // ---------------------------------------------------------------

    public function packages()
    {
        $this->_auth();

        $packages = $this->db->table('subscription_packages')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        foreach ($packages as &$p) {
            $p['description'] = json_decode($p['description'] ?? '[]', true);
        }
        unset($p);

        return $this->_render('cms/packages/index', [
            'title' => 'Packages',
            'packages' => $packages,
        ]);
    }

    public function packageForm($id = null)
    {
        $this->_auth();

        $package = null;
        if ($id) {
            $package = $this->packageModel->find($id);
            if (!$package) {
                session()->setFlashdata('error', 'Package tidak ditemukan.');
                return redirect()->to('/cms/packages');
            }
            $rawDesc = $package['description'] ?? '[]';
            $decoded = json_decode($rawDesc, true);
            $package['description'] = is_array($decoded) ? $decoded : (is_string($rawDesc) ? explode("\n", $rawDesc) : []);
        }

        return $this->_render('cms/packages/form', [
            'title' => $id ? 'Edit Package' : 'Buat Package',
            'package' => $package,
        ]);
    }

    public function packageCreate()
    {
        $this->_auth();

        $data = $this->request->getPost();

        if (empty($data['code']) || empty($data['name'])) {
            session()->setFlashdata('error', 'Kode dan nama package wajib diisi.');
            return redirect()->back()->withInput();
        }

        $existing = $this->packageModel->where('code', $data['code'])->first();
        if ($existing) {
            session()->setFlashdata('error', 'Kode package sudah digunakan.');
            return redirect()->back()->withInput();
        }

        $description = $this->_parseDescription($data);

        $this->packageModel->insert(array_merge([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'] ?? null,
            'wording' => $data['wording'] ?? '',
            'description' => json_encode($description),
            'price' => (float) ($data['price'] ?? 0),
            'currency' => $data['currency'] ?? 'IDR',
            'duration_months' => (int) ($data['duration_months'] ?? 1),
            'product_quota' => ($data['product_quota'] ?? '') !== '' ? (int) $data['product_quota'] : null,
            'transaction_monthly_quota' => ($data['transaction_monthly_quota'] ?? '') !== '' ? (int) $data['transaction_monthly_quota'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ], $this->_integrationFields($data)));

        session()->setFlashdata('success', 'Package berhasil dibuat.');
        return redirect()->to('/cms/packages');
    }

    public function packageUpdate($id)
    {
        $this->_auth();

        $package = $this->packageModel->find($id);
        if (!$package) {
            session()->setFlashdata('error', 'Package tidak ditemukan.');
            return redirect()->to('/cms/packages');
        }

        $data = $this->request->getPost();

        if (empty($data['code']) || empty($data['name'])) {
            session()->setFlashdata('error', 'Kode dan nama package wajib diisi.');
            return redirect()->back()->withInput();
        }

        if ($data['code'] !== $package['code']) {
            $existing = $this->packageModel->where('code', $data['code'])->first();
            if ($existing) {
                session()->setFlashdata('error', 'Kode package sudah digunakan oleh package lain.');
                return redirect()->back()->withInput();
            }
        }

        $description = $this->_parseDescription($data);

        $this->packageModel->update($id, array_merge([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'] ?? null,
            'wording' => $data['wording'] ?? '',
            'description' => json_encode($description),
            'price' => (float) ($data['price'] ?? 0),
            'currency' => $data['currency'] ?? 'IDR',
            'duration_months' => (int) ($data['duration_months'] ?? 1),
            'product_quota' => ($data['product_quota'] ?? '') !== '' ? (int) $data['product_quota'] : null,
            'transaction_monthly_quota' => ($data['transaction_monthly_quota'] ?? '') !== '' ? (int) $data['transaction_monthly_quota'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ], $this->_integrationFields($data)));

        session()->setFlashdata('success', 'Package berhasil diperbarui.');
        return redirect()->to('/cms/packages');
    }

    public function packageToggle($id)
    {
        $this->_auth();

        $package = $this->packageModel->find($id);
        if (!$package) {
            session()->setFlashdata('error', 'Package tidak ditemukan.');
            return redirect()->to('/cms/packages');
        }

        $newStatus = $package['is_active'] ? 0 : 1;
        $this->packageModel->update($id, ['is_active' => $newStatus]);

        session()->setFlashdata('success', 'Status package berhasil diubah.');
        return redirect()->back();
    }

    // ---------------------------------------------------------------
    // SUBSCRIPTIONS
    // ---------------------------------------------------------------

    public function subscriptions()
    {
        $this->_auth();

        $status = $this->request->getGet('status');

        $builder = $this->db->table('tenant_subscriptions ts')
            ->select('ts.*, t.name as tenant_name, t.code as tenant_code, t.status as tenant_status, sp.name as package_name, sp.code as package_code, sp.price as package_price')
            ->join('tenants t', 't.id = ts.tenant_id', 'left')
            ->join('subscription_packages sp', 'sp.id = ts.package_id', 'left')
            ->orderBy('ts.created_at', 'DESC');

        if ($status && in_array($status, ['active', 'expired', 'canceled'])) {
            $builder->where('ts.status', $status);
        }

        $subscriptions = $builder->get()->getResultArray();

        return $this->_render('cms/subscriptions/index', [
            'title' => 'Subscriptions',
            'subscriptions' => $subscriptions,
            'status' => $status,
        ]);
    }

    // ---------------------------------------------------------------
    // ORDERS
    // ---------------------------------------------------------------

    public function orders()
    {
        $this->_auth();

        $status = $this->request->getGet('status');

        $builder = $this->db->table('subscription_orders so')
            ->select('so.*, t.name as tenant_name, t.code as tenant_code, sp.name as package_name')
            ->join('tenants t', 't.id = so.tenant_id', 'left')
            ->join('subscription_packages sp', 'sp.id = so.package_id', 'left')
            ->orderBy('so.created_at', 'DESC');

        if ($status && in_array($status, ['waiting_payment', 'paid', 'failed', 'canceled'])) {
            $builder->where('so.status', $status);
        }

        $orders = $builder->get()->getResultArray();

        return $this->_render('cms/orders/index', [
            'title' => 'Orders',
            'orders' => $orders,
            'status' => $status,
        ]);
    }

    public function orderApprove($id)
    {
        $this->_auth();

        $order = $this->db->table('subscription_orders')->where('id', $id)->get()->getRowArray();
        if (!$order) {
            session()->setFlashdata('error', 'Order tidak ditemukan.');
            return redirect()->to('/cms/orders');
        }

        if ($order['status'] !== 'waiting_payment') {
            session()->setFlashdata('error', 'Hanya order dengan status waiting_payment yang bisa diapprove.');
            return redirect()->to('/cms/orders');
        }

        $this->db->transStart();
        try {
            $this->db->table('subscription_orders')->where('id', $id)->update([
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
            ]);

            $service = new SubscriptionService($this->db);
            $service->applyPaidPackagePurchase((int) $order['tenant_id'], (int) $order['package_id']);
            $service->syncCurrentTenantQuota((int) $order['tenant_id']);

            $this->db->transComplete();
            session()->setFlashdata('success', 'Order berhasil diapprove. Subscription telah diaktifkan.');
        } catch (\Throwable $e) {
            $this->db->transRollback();
            session()->setFlashdata('error', 'Gagal approve order: ' . $e->getMessage());
        }

        return redirect()->to('/cms/orders');
    }

    public function orderCancel($id)
    {
        $this->_auth();

        $order = $this->db->table('subscription_orders')->where('id', $id)->get()->getRowArray();
        if (!$order) {
            session()->setFlashdata('error', 'Order tidak ditemukan.');
            return redirect()->to('/cms/orders');
        }

        if ($order['status'] !== 'waiting_payment') {
            session()->setFlashdata('error', 'Hanya order waiting_payment yang bisa dicancel.');
            return redirect()->to('/cms/orders');
        }

        $this->db->table('subscription_orders')->where('id', $id)->update([
            'status' => 'canceled',
        ]);

        session()->setFlashdata('success', 'Order berhasil dicancel.');
        return redirect()->to('/cms/orders');
    }

    // ---------------------------------------------------------------
    // PRIVATE HELPERS
    // ---------------------------------------------------------------

    private function _auth()
    {
        if (!session()->get('cms_logged_in')) {
            redirect()->to('/cms/login')->send();
            exit;
        }
    }

    private function _render(string $view, array $data = []): string
    {
        $data['content'] = view($view, $data);
        $data['cmsUser'] = session()->get('cms_user');
        $data['currentUri'] = service('uri')->getPath();

        return view('cms/layouts/main', $data);
    }

    private function _parseDescription(array $data): array
    {
        if (!empty($data['description_json'])) {
            $decoded = json_decode($data['description_json'], true);
            return is_array($decoded) ? $decoded : [];
        }
        if (!empty($data['description'])) {
            if (is_array($data['description'])) {
                return $data['description'];
            }
            $lines = preg_split('/\r\n|\n|\r/', $data['description']);
            $lines = array_map('trim', $lines);
            return array_values(array_filter($lines, fn($v) => $v !== ''));
        }
        return [];
    }

    private function _integrationFields(array $data): array
    {
        $fields = [];
        foreach (['integration_tiktok', 'integration_shopee', 'integration_email', 'integration_moota', 'integration_whatsapp'] as $col) {
            $fields[$col] = !empty($data[$col]) ? 1 : 0;
        }
        return $fields;
    }
}
