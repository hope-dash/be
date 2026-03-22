<?php

namespace App\Controllers;

use App\Libraries\SubscriptionService;
use App\Libraries\TenantContext;
use App\Models\JsonResponse;
use App\Models\SubscriptionOrderModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\RESTful\ResourceController;

class SubscriptionControllerV2 extends ResourceController
{
    use ResponseTrait;

    protected JsonResponse $jsonResponse;
    protected $db;
    protected SubscriptionOrderModel $orderModel;

    public function __construct()
    {
        helper(['email_helper', 'url']);
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
        $this->orderModel = new SubscriptionOrderModel();
    }

    // GET /api/v2/subscription
    public function detail()
    {
        try {
            $tenantId = TenantContext::id();
            $service = new SubscriptionService($this->db);
            $sub = $service->getActiveSubscriptionWithPackage($tenantId);

            if (!$sub) {
                return $this->jsonResponse->error('Subscription tidak aktif / belum ada.', 404);
            }

            return $this->jsonResponse->oneResp('Sukses', [
                'subscription' => [
                    'id' => (int)$sub['id'],
                    'tenant_id' => (int)$sub['tenant_id'],
                    'package_id' => (int)$sub['package_id'],
                    'status' => $sub['status'],
                    'start_at' => $sub['start_at'],
                    'end_at' => $sub['end_at'],
                ],
                'package' => [
                    'code' => $sub['package_code'],
                    'name' => $sub['package_name'],
                    'duration_months' => (int)$sub['duration_months'],
                    'product_quota' => ($sub['product_quota_snapshot'] ?? $sub['product_quota']) === null ? null : (int)($sub['product_quota_snapshot'] ?? $sub['product_quota']),
                    'transaction_monthly_quota' => ($sub['transaction_monthly_quota_snapshot'] ?? $sub['transaction_monthly_quota']) === null ? null : (int)($sub['transaction_monthly_quota_snapshot'] ?? $sub['transaction_monthly_quota']),
                ],
            ], 200);
        }
        catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // GET /api/v2/subscription/usage
    public function usage()
    {
        try {
            $tenantId = TenantContext::id();
            $service = new SubscriptionService($this->db);
            $sub = $service->getActiveSubscriptionWithPackage($tenantId);

            if (!$sub) {
                return $this->jsonResponse->error('Subscription tidak aktif / belum ada.', 404);
            }

            $quotaRow = $service->syncCurrentTenantQuota($tenantId);
            if (!$quotaRow) {
                return $this->jsonResponse->error('Kuota tenant belum tersedia', 500);
            }

            $productUsed = (int)($quotaRow['product_used'] ?? 0);
            $trxUsed = (int)($quotaRow['transaction_monthly_used'] ?? 0);
            $productLimitRaw = $sub['product_quota_snapshot'] ?? ($sub['product_quota'] ?? null);
            $trxLimitRaw = $sub['transaction_monthly_quota_snapshot'] ?? ($sub['transaction_monthly_quota'] ?? null);
            $productLimit = $productLimitRaw === null ? null : (int)$productLimitRaw;
            $trxLimit = $trxLimitRaw === null ? null : (int)$trxLimitRaw;

            $start = $quotaRow['month_start'] . ' 00:00:00';
            $end = date('Y-m-t 23:59:59', strtotime($start));

            return $this->jsonResponse->oneResp('Sukses', [
                'subscription' => [
                    'start_at' => $sub['start_at'] ?? null,
                    'end_at' => $sub['end_at'] ?? null,
                    'status' => $sub['status'] ?? null,
                ],
                'period' => [
                    'month_start' => $start,
                    'month_end' => $end,
                ],
                'package' => [
                    'code' => $sub['package_code'],
                    'name' => $sub['package_name'],
                    'duration_months' => (int)$sub['duration_months'],
                ],
                'usage' => [
                    'products' => [
                        'used' => $productUsed,
                        'limit' => $productLimit,
                        'remaining' => $productLimit === null ? null : max(0, $productLimit - $productUsed),
                    ],
                    'transactions_monthly' => [
                        'used' => $trxUsed,
                        'limit' => $trxLimit,
                        'remaining' => $trxLimit === null ? null : max(0, $trxLimit - $trxUsed),
                    ],
                ],
            ], 200);
        }
        catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // POST /api/v2/subscription/orders
    public function createOrder()
    {
        try {
            $tenantId = TenantContext::id();
            $data = $this->request->getJSON(true);

            $waiting = $this->orderModel
                ->where('tenant_id', $tenantId)
                ->where('status', 'waiting_payment')
                ->orderBy('created_at', 'DESC')
                ->first();
            if ($waiting) {
                return $this->jsonResponse->error(
                    'Masih ada order yang menunggu pembayaran. Selesaikan / cancel dulu. transaction_id: ' . ($waiting['external_transaction_id'] ?? '-'),
                    409
                );
            }

            $externalId = trim((string)($data['transaction_id'] ?? $data['external_transaction_id'] ?? ''));
            if ($externalId === '') {
                return $this->jsonResponse->error('transaction_id wajib diisi', 400);
            }

            $packageId = (int)($data['package_id'] ?? 0);
            $packageCode = trim((string)($data['package_code'] ?? ''));

            if ($packageId <= 0 && $packageCode === '') {
                return $this->jsonResponse->error('package_id atau package_code wajib diisi', 400);
            }

            $packageBuilder = $this->db->table('subscription_packages')->where('is_active', 1);
            if ($packageId > 0) {
                $packageBuilder->where('id', $packageId);
            }
            else {
                $packageBuilder->where('code', $packageCode);
            }
            $package = $packageBuilder->get()->getRowArray();
            if (!$package) {
                return $this->jsonResponse->error('Package tidak ditemukan / tidak aktif', 404);
            }

            $existing = $this->orderModel->where('external_transaction_id', $externalId)->first();
            if ($existing) {
                return $this->jsonResponse->error('transaction_id sudah pernah dipakai', 409);
            }

            $orderData = [
                'tenant_id' => $tenantId,
                'package_id' => (int)$package['id'],
                'external_transaction_id' => $externalId,
                'status' => 'waiting_payment',
                'amount' => (float)$package['price'],
                'currency' => $package['currency'] ?? 'IDR',
            ];

            $this->orderModel->insert($orderData);
            $orderId = (int)$this->orderModel->getInsertID();

            $order = $this->orderModel->find($orderId);

            // Send Order & Payment Information to Tenant
            $appName = env('APP_NAME', 'UMKM HEBAT');
            $tenant = $this->db->table('tenants')->where('id', $tenantId)->get()->getRowArray();
            $user = $this->db->table('users')
                ->where('tenant_id', $tenantId)
                ->orderBy('created_at', 'ASC')
                ->get()
                ->getRowArray();

            if ($tenant && $user && !empty($user['email'])) {
                $totalAmount = number_format($order['amount'], 0, ',', '.');
                $packageName = $package['name'] ?? 'Subscription Package';
                
                $orderContent = '
                    <h2>Pesanan Subscription Berhasil Dibuat</h2>
                    <p>Halo, ' . htmlspecialchars($tenant['name']) . '.</p>
                    <p>Terima kasih telah melakukan pemesanan di <strong>' . htmlspecialchars($appName) . '</strong>. Berikut adalah rincian pesanan Anda:</p>
                    
                    <div class="info-box">
                        <p><strong>Paket:</strong> ' . htmlspecialchars($packageName) . '</p>
                        <p><strong>Total Tagihan:</strong> ' . htmlspecialchars($order['currency']) . ' ' . $totalAmount . '</p>
                        <p><strong>ID Transaksi:</strong> ' . htmlspecialchars($order['external_transaction_id']) . '</p>
                        <p><strong>Status:</strong> <span style="color: #d97706; font-weight: bold;">MENUNGGU PEMBAYARAN</span></p>
                    </div>
                    
                    <p>Silakan lakukan pembayaran sesuai dengan tagihan di atas. Setelah melakukan pembayaran, Anda wajib mengunggah bukti pembayaran melalui dashboard aplikasi agar pesanan Anda dapat segera kami proses.</p>
                    
                    <p>Jika Anda memiliki pertanyaan, silakan hubungi tim support kami.</p>
                ';
                
                $orderHtml = get_email_template("Informasi Pesanan & Pembayaran - $appName", $orderContent, $appName);
                send_system_email($user['email'], "Pesanan Subscription #" . $order['external_transaction_id'], $orderHtml);
            }

            return $this->jsonResponse->oneResp('Order created', ['order' => $order], 201);
        }
        catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // POST /api/v2/subscription/orders/{id}/cancel
    public function cancelOrder($id = null)
    {
        $id = (int)($id ?? 0);
        if ($id <= 0) {
            return $this->jsonResponse->error('ID wajib diisi', 400);
        }

        $tenantId = TenantContext::id();
        try {
            $order = $this->orderModel->find($id);
            if (!$order) {
                return $this->jsonResponse->error('Order tidak ditemukan', 404);
            }

            if ((int)($order['tenant_id'] ?? 0) !== $tenantId) {
                return $this->jsonResponse->error('Forbidden', 403);
            }

            if (($order['status'] ?? '') !== 'waiting_payment') {
                return $this->jsonResponse->error('Hanya order waiting_payment yang bisa dicancel', 400);
            }

            $this->orderModel->update($id, ['status' => 'canceled']);
            return $this->jsonResponse->oneResp('Order canceled', ['order' => $this->orderModel->find($id)], 200);
        }
        catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // POST /api/v2/subscription/orders/upload-proof (multipart/form-data)
    public function uploadProof()
    {
        try {
            $tenantId = TenantContext::id();
            $tenantCode = TenantContext::code() ?: (string)$tenantId;

            $externalId = trim((string)($this->request->getPost('transaction_id') ?? ''));
            if ($externalId === '') {
                return $this->jsonResponse->error('transaction_id wajib diisi', 400);
            }

            $order = $this->orderModel
                ->where('tenant_id', $tenantId)
                ->where('external_transaction_id', $externalId)
                ->first();
            if (!$order) {
                return $this->jsonResponse->error('Order tidak ditemukan untuk transaction_id tersebut', 404);
            }
            if (($order['status'] ?? '') !== 'waiting_payment') {
                return $this->jsonResponse->error('Order status tidak valid untuk upload bukti', 400);
            }

            $file = $this->request->getFile('image');
            if (!$file || !$file->isValid()) {
                return $this->jsonResponse->error('File image wajib diupload', 400);
            }

            $ext = strtolower($file->getExtension() ?: '');
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            if ($ext === '' || !in_array($ext, $allowed, true)) {
                return $this->jsonResponse->error('Format file tidak didukung (jpg/jpeg/png/webp/pdf)', 400);
            }

            $dir = WRITEPATH . 'uploads/subscription_proofs/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $tenantCode);
            if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
                return $this->jsonResponse->error('Gagal membuat folder upload', 500);
            }

            $safeExternal = preg_replace('/[^a-zA-Z0-9_-]/', '_', $externalId);
            $filename = $safeExternal . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $file->move($dir, $filename);

            $path = rtrim($dir, '/') . '/' . $filename;

            $subject = "[Subscription Payment Proof] tenant={$tenantCode} transaction_id={$externalId}";
            $content = '
                <h2>Bukti Pembayaran Subscription</h2>
                <div class="info-box">
                    <p><strong>Tenant:</strong> ' . htmlspecialchars($tenantCode) . '</p>
                    <p><strong>Order ID:</strong> ' . (int)$order['id'] . '</p>
                    <p><strong>Transaction ID:</strong> ' . htmlspecialchars($externalId) . '</p>
                    <p><strong>Amount:</strong> ' . htmlspecialchars((string)($order['amount'] ?? '')) . ' ' . htmlspecialchars((string)($order['currency'] ?? 'IDR')) . '</p>
                </div>

                <p>Lampiran: bukti pembayaran.</p>
                <center>
                    <a href="' . env('app.baseURL') . '/api/v2/subscription/orders/' . $order['id'] . '/pay?key=' . env('subscription.webhookKey') . '" class="button">APPROVE PAYMENT</a>
                </center>
            ';
            $html = get_email_template('Bukti Pembayaran Subscription', $content);

            $adminEmail = 'nicholasantonius46@gmail.com';
            $sent = send_email_with_attachments($adminEmail, $subject, $html, [$path]);
            if (!$sent) {
                return $this->jsonResponse->error('Gagal mengirim email ke admin', 500);
            }

            return $this->jsonResponse->oneResp('Bukti pembayaran terkirim ke admin', [
                'order_id' => (int)$order['id'],
                'transaction_id' => $externalId,
                'filename' => $filename,
            ], 200);
        }
        catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // POST /api/v2/subscription/orders/{id}/pay
    public function payOrder($id = null)
    {
        $id = (int)($id ?? 0);
        if ($id <= 0) {
            return $this->jsonResponse->error('ID wajib diisi', 400);
        }

        $tenantId = TenantContext::id();
        $service = new SubscriptionService($this->db);

        $this->db->transStart();
        try {
            $order = $this->orderModel->find($id);
            if (!$order) {
                return $this->jsonResponse->error('Order tidak ditemukan', 404);
            }

            if ((int)($order['tenant_id'] ?? 0) !== $tenantId) {
                return $this->jsonResponse->error('Forbidden', 403);
            }

            if (($order['status'] ?? '') !== 'waiting_payment') {
                return $this->jsonResponse->error('Order status tidak valid untuk dibayar', 400);
            }

            $paidAt = date('Y-m-d H:i:s');
            $this->orderModel->update($id, [
                'status' => 'paid',
                'paid_at' => $paidAt,
            ]);

            $result = $service->applyPaidPackagePurchase($tenantId, (int)$order['package_id']);
            $quota = $service->syncCurrentTenantQuota($tenantId);
            $activeSub = $service->getActiveSubscriptionWithPackage($tenantId);
            $effective = null;
            if ($activeSub) {
                $effective = [
                    'product_quota' => ($activeSub['product_quota_snapshot'] ?? ($activeSub['product_quota'] ?? null)) === null
                    ? null
                    : (int)($activeSub['product_quota_snapshot'] ?? $activeSub['product_quota']),
                    'transaction_monthly_quota' => ($activeSub['transaction_monthly_quota_snapshot'] ?? ($activeSub['transaction_monthly_quota'] ?? null)) === null
                    ? null
                    : (int)($activeSub['transaction_monthly_quota_snapshot'] ?? $activeSub['transaction_monthly_quota']),
                    'start_at' => $activeSub['start_at'] ?? null,
                    'end_at' => $activeSub['end_at'] ?? null,
                ];
            }

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new \Exception('DB transaction failed');
            }

            $updatedOrder = $this->orderModel->find($id);

            // Send Confirmation Email to Tenant
            $appName = env('APP_NAME', 'UMKM HEBAT');
            $tenant = $this->db->table('tenants')->where('id', $tenantId)->get()->getRowArray();
            $user = $this->db->table('users')
                ->where('tenant_id', $tenantId)
                ->orderBy('created_at', 'ASC')
                ->get()
                ->getRowArray();

            if ($tenant && $user && !empty($user['email'])) {
                $confirmContent = '
                    <h2>Pembayaran Diterima</h2>
                    <p>Halo, ' . htmlspecialchars($tenant['name']) . '.</p>
                    <p>Informasi bahwa pembayaran untuk subscription kamu sudah kami terima dan paket sudah diaktifkan.</p>
                    <p>Terima kasih telah berlangganan!</p>
                ';
                $confirmHtml = get_email_template('Pembayaran Diterima', $confirmContent, $appName);
                send_system_email($user['email'], 'Informasi Pembayaran Subscription', $confirmHtml);
            }

            return $this->jsonResponse->oneResp('Payment success', [
                'order' => $updatedOrder,
                'subscription' => $result,
                'tenant_quota' => $quota,
                'effective_limits' => $effective,
            ], 200);
        }
        catch (\Throwable $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // POST /api/v2/subscription/orders/{id}/pay (PUBLIC - no JWT / X-Tenant)
    public function publicPayOrder($id = null)
    {
        $id = (int)($id ?? 0);
        if ($id <= 0) {
            return $this->jsonResponse->error('ID wajib diisi', 400);
        }

        // Optional hardening: set subscription.webhookKey in .env to require this key.
        $requiredKey = (string)(env('subscription.webhookKey') ?? '');
        if ($requiredKey !== '') {
            $provided = trim((string)($this->request->getHeaderLine('X-Webhook-Key') ?: $this->request->getGet('key')));
            if ($provided === '' || !hash_equals($requiredKey, $provided)) {
                return $this->jsonResponse->error('Forbidden', 403);
            }
        }

        $service = new SubscriptionService($this->db);

        $this->db->transStart();
        try {
            $order = $this->orderModel->find($id);
            if (!$order) {
                return $this->jsonResponse->error('Order tidak ditemukan', 404);
            }

            if (($order['status'] ?? '') !== 'waiting_payment') {
                return $this->jsonResponse->error('Order status tidak valid untuk dibayar', 400);
            }

            $paidAt = date('Y-m-d H:i:s');
            $this->orderModel->update($id, [
                'status' => 'paid',
                'paid_at' => $paidAt,
            ]);

            $tenantId = (int)($order['tenant_id'] ?? 0);
            $packageId = (int)($order['package_id'] ?? 0);
            if ($tenantId <= 0 || $packageId <= 0) {
                throw new \Exception('Order data invalid');
            }

            $result = $service->applyPaidPackagePurchase($tenantId, $packageId);
            $quota = $service->syncCurrentTenantQuota($tenantId);
            $activeSub = $service->getActiveSubscriptionWithPackage($tenantId);
            $effective = null;
            if ($activeSub) {
                $effective = [
                    'product_quota' => ($activeSub['product_quota_snapshot'] ?? ($activeSub['product_quota'] ?? null)) === null
                    ? null
                    : (int)($activeSub['product_quota_snapshot'] ?? $activeSub['product_quota']),
                    'transaction_monthly_quota' => ($activeSub['transaction_monthly_quota_snapshot'] ?? ($activeSub['transaction_monthly_quota'] ?? null)) === null
                    ? null
                    : (int)($activeSub['transaction_monthly_quota_snapshot'] ?? $activeSub['transaction_monthly_quota']),
                    'start_at' => $activeSub['start_at'] ?? null,
                    'end_at' => $activeSub['end_at'] ?? null,
                ];
            }

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new \Exception('DB transaction failed');
            }

            $updatedOrder = $this->orderModel->find($id);

            // Send Confirmation Email to Tenant (Public Webhook)
            $appName = env('APP_NAME', 'UMKM HEBAT');
            $tenant = $this->db->table('tenants')->where('id', $tenantId)->get()->getRowArray();
            $user = $this->db->table('users')
                ->where('tenant_id', $tenantId)
                ->orderBy('created_at', 'ASC')
                ->get()
                ->getRowArray();

            if ($tenant && $user && !empty($user['email'])) {
                $confirmContent = '
                    <h2>Pembayaran Diterima</h2>
                    <p>Halo, ' . htmlspecialchars($tenant['name']) . '.</p>
                    <p>Informasi bahwa pembayaran untuk subscription kamu sudah kami terima dan paket sudah diaktifkan.</p>
                    <p>Terima kasih telah berlangganan!</p>
                ';
                $confirmHtml = get_email_template('Pembayaran Diterima', $confirmContent, $appName);
                send_system_email($user['email'], 'Informasi Pembayaran Subscription', $confirmHtml);
            }

            return $this->jsonResponse->oneResp('Payment success', [
                'order' => $updatedOrder,
                'subscription' => $result,
                'tenant_quota' => $quota,
                'effective_limits' => $effective,
            ], 200);
        }
        catch (\Throwable $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // GET /api/v2/subscription/packages
    public function packages()
    {
        try {
            $type = $this->request->getGet('type');

            $builder = $this->db->table('subscription_packages')
                ->select('id, code, name, type, wording, description, price, currency, duration_months, product_quota, transaction_monthly_quota, is_active, created_at, updated_at')
                ->where('is_active', 1);

            if ($type) {
                $types = explode(',', $type);
                $types = array_map('trim', $types);
                $builder->whereIn('type', $types);
            }

            $packages = $builder->orderBy('price', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($packages as &$p) {
                $p['id'] = (int)$p['id'];
                $p['duration_months'] = (int)$p['duration_months'];
                $p['is_active'] = (int)$p['is_active'];
                $p['product_quota'] = $p['product_quota'] === null ? null : (int)$p['product_quota'];
                $p['transaction_monthly_quota'] = $p['transaction_monthly_quota'] === null ? null : (int)$p['transaction_monthly_quota'];
                $p['price'] = (float)$p['price'];
                $p['description'] = json_decode($p['description'] ?? '[]', true);
            }
            unset($p);

            return $this->jsonResponse->oneResp('Sukses', $packages, 200);
        }
        catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}