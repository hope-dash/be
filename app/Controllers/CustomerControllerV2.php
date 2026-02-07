<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\CustomerModel;
use App\Models\ProductModel;
use App\Models\StockModel;
use App\Models\VoucherModel;
use App\Models\Jwtoken;
use App\Models\JsonResponse;
use CodeIgniter\API\ResponseTrait;

class CustomerControllerV2 extends ResourceController
{
    use ResponseTrait;

    protected $customerModel;
    protected $productModel;
    protected $stockModel;
    protected $voucherModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        helper(['log', 'email']);
        $this->customerModel = new CustomerModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->voucherModel = new VoucherModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // Customer Registration
    public function register()
    {
        try {
            $data = $this->request->getJSON();

            // Validation
            $validation = \Config\Services::validation();
            $validation->setRules([
                'nama_customer' => 'required',
                'email' => 'required|valid_email|is_unique[customer.email]',
                'password' => 'required|min_length[6]',
                'no_hp_customer' => 'required',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            // Generate email verification token
            $verificationToken = bin2hex(random_bytes(32));

            $customerData = [
                'nama_customer' => $data->nama_customer,
                'email' => $data->email,
                'password' => password_hash($data->password, PASSWORD_DEFAULT),
                'no_hp_customer' => $data->no_hp_customer,
                'alamat' => $data->alamat ?? '',
                'provinsi' => $data->provinsi ?? '',
                'kota_kabupaten' => $data->kota_kabupaten ?? '',
                'kode_pos' => $data->kode_pos ?? '',
                'type' => 'regular',
                'email_verification_token' => $verificationToken,
            ];

            $customerId = $this->customerModel->insert($customerData);

            if (!$customerId) {
                return $this->jsonResponse->error("Registration failed", 500);
            }

            // Send registration email with credentials and verification link
            $emailSent = send_registration_email(
                $data->email,
                $data->nama_customer,
                $data->password, // Plain text password for email
                $verificationToken
            );

            return $this->jsonResponse->oneResp('Registration successful. Please check your email to verify your account.', [
                'customer_id' => $customerId,
                'email_sent' => $emailSent
            ], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Email Verification
    public function verifyEmail()
    {
        try {
            $data = $this->request->getJSON();

            if (empty($data->token)) {
                return $this->jsonResponse->error("Verification token required", 400);
            }

            $customer = $this->customerModel
                ->where('email_verification_token', $data->token)
                ->first();

            if (!$customer) {
                return $this->jsonResponse->error("Invalid verification token", 404);
            }

            if ($customer['email_verified_at']) {
                return $this->jsonResponse->error("Email already verified", 400);
            }

            $this->customerModel->update($customer['id'], [
                'email_verified_at' => date('Y-m-d H:i:s'),
                'email_verification_token' => null
            ]);

            return $this->jsonResponse->oneResp('Email verified successfully', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Email Verification Page (GET request from email link)
    public function verifyEmailPage()
    {
        try {
            $token = $this->request->getGet('token');

            if (empty($token)) {
                return view('customer/verification_error', [
                    'message' => 'Token verifikasi tidak ditemukan.'
                ]);
            }

            $customer = $this->customerModel
                ->where('email_verification_token', $token)
                ->first();

            if (!$customer) {
                return view('customer/verification_error', [
                    'message' => 'Token verifikasi tidak valid atau sudah kadaluarsa.'
                ]);
            }

            if ($customer['email_verified_at']) {
                return view('customer/verification_success', [
                    'message' => 'Email Anda sudah terverifikasi sebelumnya.',
                    'already_verified' => true
                ]);
            }

            $this->customerModel->update($customer['id'], [
                'email_verified_at' => date('Y-m-d H:i:s'),
                'email_verification_token' => null
            ]);

            return view('customer/verification_success', [
                'message' => 'Email Anda berhasil diverifikasi!',
                'customer_name' => $customer['nama_customer'],
                'already_verified' => false
            ]);
        } catch (\Exception $e) {
            return view('customer/verification_error', [
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ]);
        }
    }

    // Customer Login
    public function login()
    {
        try {
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                'email' => 'required|valid_email',
                'password' => 'required',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $customer = $this->customerModel->where('email', $data->email)->first();

            if (!$customer) {
                return $this->jsonResponse->error("Invalid credentials", 401);
            }

            if (!password_verify($data->password, $customer['password'])) {
                return $this->jsonResponse->error("Invalid credentials", 401);
            }

            if (!$customer['email_verified_at']) {
                return $this->jsonResponse->error("Please verify your email first", 403);
            }

            // Generate JWT token
            $jwt = new Jwtoken();
            $token = $jwt->generateToken([
                'customer_id' => $customer['id'],
                'email' => $customer['email'],
                'type' => 'customer'
            ]);

            return $this->jsonResponse->oneResp('Login successful', [
                'token' => $token,
                'customer' => [
                    'id' => $customer['id'],
                    'nama_customer' => $customer['nama_customer'],
                    'email' => $customer['email'],
                    'no_hp_customer' => $customer['no_hp_customer'],
                    'discount_type' => $customer['discount_type'],
                    'discount_value' => $customer['discount_value'],
                ]
            ], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Get Product List with Customer Discount
    public function getProducts()
    {
        try {
            // Optional: Get customer from token if provided
            $authHeader = $this->request->getHeaderLine('Authorization');
            $customer = null;

            if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
                $jwt = new Jwtoken();
                $decoded = $jwt->validateToken($token);
                if ($decoded && isset($decoded->customer_id)) {
                    // Fetch fresh customer data from DB to ensure latest discount rules
                    $customerModel = new \App\Models\CustomerModel();
                    $customer = $customerModel
                        ->select('id, discount_type, discount_value')
                        ->find($decoded->customer_id);
                }
            }
            
           

            $idToko = $this->request->getGet('id_toko');
            $search = trim($this->request->getGet('search') ?? ''); // Search query
            $limit = (int) $this->request->getGet('limit') ?: 20;
            $page = (int) $this->request->getGet('page') ?: 1;
            $offset = ($page - 1) * $limit;

            $builder = $this->productModel->select([
                'product.id',
                'product.id_barang',
                'product.nama_barang',
                'product.harga_jual',
                'product.created_at',
                'model_barang.nama_model',
                'seri.seri'
            ])
            ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
            ->join('seri', 'seri.id = product.id_seri_barang', 'left');

            // Apply search filter
            if (!empty($search)) {
                $builder->groupStart()
                    ->like("CONCAT_WS(' ', product.nama_barang, model_barang.nama_model, seri.seri)", $search)
                    ->orLike("product.id_barang", $search)
                    ->groupEnd();
            }

            $totalData = $builder->countAllResults(false);
            $totalPage = ceil($totalData / $limit);

            $products = $builder
                ->orderBy('product.created_at', 'DESC')
                ->limit($limit, $offset)
                ->findAll();

            if (empty($products)) {
                 return $this->jsonResponse->multiResp('', [], $totalData, $totalPage, $page, $limit, 200);
            }

            // === OPTIMIZED STOCK FETCHING ===
            $productIds = array_unique(array_column($products, 'id_barang'));
            $stockMap = [];
            
            // Load toko details if needed map
            $tokoMap = [];
            if (!$idToko) {
                $tokoModel = new \App\Models\TokoModel();
                $allTokos = $tokoModel->findAll();
                foreach ($allTokos as $t) {
                    $tokoMap[$t['id']] = $t['toko_name'];
                }
            }

            if (!empty($productIds)) {
                $stockBuilder = $this->db->table('stock')->whereIn('id_barang', $productIds);
                
                if ($idToko) {
                    $stockBuilder->where('id_toko', $idToko);
                } 
                // Remove stock > 0 filter to show ALL stock records including 0
                
                $stocks = $stockBuilder->get()->getResultArray();

                foreach ($stocks as $s) {
                    if ($idToko) {
                        // Single store mode
                        $stockMap[$s['id_barang']] = (int)$s['stock'];
                    } else {
                        // Multi store mode
                        if (!isset($stockMap[$s['id_barang']])) {
                            $stockMap[$s['id_barang']] = [
                                'total' => 0,
                                'details' => []
                            ];
                        }
                        
                        $stockMap[$s['id_barang']]['total'] += (int)$s['stock'];
                        $stockMap[$s['id_barang']]['details'][] = [
                            'id_toko' => $s['id_toko'],
                            'toko_name' => $tokoMap[$s['id_toko']] ?? 'Unknown Store',
                            'stock' => (int)$s['stock']
                        ];
                    }
                }
            }

            // === FETCH IMAGES ===
            $productTableIds = array_column($products, 'id');
            $imageMap = [];
            if (!empty($productTableIds)) {
                // Remove duplicates just in case
                $productTableIds = array_unique($productTableIds);
                
                $images = $this->db->table('image')
                    ->select('kode, url')
                    ->where('type', 'product')
                    ->whereIn('kode', $productTableIds)
                    ->get()
                    ->getResultArray();
                
                foreach ($images as $img) {
                    $imageMap[$img['kode']][] = $img['url'];
                }
            }

            // Apply customer discount & map stock & format response
            $finalProducts = [];
            foreach ($products as $product) {
                $namaLengkap = trim(implode(' ', array_filter([
                    $product['nama_barang'],
                    $product['nama_model'] ?? '',
                    $product['seri'] ?? ''
                ])));

                $basePrice = (float) $product['harga_jual'];
                $customerPrice = $basePrice;
                $discountApplied = 0;

                if ($customer && !empty($customer['discount_type'])) {
                    $discountType = strtolower($customer['discount_type']);
                    $discountValue = (float) $customer['discount_value'];

                    if ($discountType === 'percentage') {
                        $discount = ($basePrice * $discountValue) / 100;
                        $customerPrice = max(0, $basePrice - $discount);
                        $discountApplied = $discount;
                    } elseif ($discountType === 'fixed') {
                        $discountApplied = min($basePrice, $discountValue);
                        $customerPrice = max(0, $basePrice - $discountValue);
                    }
                }

                $item = [
                    'id' => $product['id'],
                    'id_barang' => $product['id_barang'],
                    'nama_barang' => $product['nama_barang'],
                    'nama_lengkap_barang' => $namaLengkap,
                    'harga_jual' => (int) $basePrice,
                    'customer_price' => (int) $customerPrice,
                    'discount_applied' => (int) $discountApplied,
                ];

                // Map images
                $item['images'] = $imageMap[$product['id']] ?? [];

                // Map stock
                if ($idToko) {
                    $item['stock'] = $stockMap[$product['id_barang']] ?? 0;
                } else {
                    $stockInfo = $stockMap[$product['id_barang']] ?? ['total' => 0, 'details' => []];
                    $item['stock_total'] = $stockInfo['total'];
                    $item['stock_details'] = $stockInfo['details'];
                }

                $finalProducts[] = $item;
            }

            return $this->jsonResponse->multiResp('', $finalProducts, $totalData, $totalPage, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Validate Voucher
    public function validateVoucher()
    {
        try {
            $data = $this->request->getJSON();

            if (empty($data->code)) {
                return $this->jsonResponse->error("Voucher code required", 400);
            }

            $voucher = $this->voucherModel
                ->where('code', $data->code)
                ->where('is_active', 1)
                ->first();

            if (!$voucher) {
                return $this->jsonResponse->error("Invalid voucher code", 404);
            }

            $now = date('Y-m-d H:i:s');

            // Check validity period
            if ($voucher['valid_from'] && $voucher['valid_from'] > $now) {
                return $this->jsonResponse->error("Voucher not yet valid", 400);
            }

            if ($voucher['valid_until'] && $voucher['valid_until'] < $now) {
                return $this->jsonResponse->error("Voucher has expired", 400);
            }

            // Check usage limit
            if ($voucher['usage_limit'] && $voucher['usage_count'] >= $voucher['usage_limit']) {
                return $this->jsonResponse->error("Voucher usage limit reached", 400);
            }

            // Check minimum purchase
            $purchaseAmount = $data->purchase_amount ?? 0;
            if ($voucher['min_purchase'] && $purchaseAmount < $voucher['min_purchase']) {
                return $this->jsonResponse->error("Minimum purchase amount not met. Required: " . $voucher['min_purchase'], 400);
            }

            // Calculate discount
            $discount = 0;
            if ($voucher['discount_type'] === 'PERCENTAGE') {
                $discount = ($purchaseAmount * $voucher['discount_value']) / 100;
                if ($voucher['max_discount'] && $discount > $voucher['max_discount']) {
                    $discount = $voucher['max_discount'];
                }
            } else {
                $discount = $voucher['discount_value'];
            }

            return $this->jsonResponse->oneResp('Voucher is valid', [
                'voucher' => $voucher,
                'discount_amount' => $discount,
                'final_amount' => $purchaseAmount - $discount
            ], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Apply Voucher (increment usage count)
    public function applyVoucher($voucherId)
    {
        try {
            $voucher = $this->voucherModel->find($voucherId);
            if (!$voucher) {
                return $this->jsonResponse->error("Voucher not found", 404);
            }

            $this->voucherModel->update($voucherId, [
                'usage_count' => $voucher['usage_count'] + 1
            ]);

            return $this->jsonResponse->oneResp('Voucher applied successfully', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Get Customer Profile
    public function getProfile()
    {
        try {
            $customer = $this->request->customer;

            $profile = [
                'id' => $customer['id'],
                'nama_customer' => $customer['nama_customer'],
                'email' => $customer['email'],
                'no_hp_customer' => $customer['no_hp_customer'],
                'alamat' => $customer['alamat'] ?? '',
                'provinsi' => $customer['provinsi'] ?? '',
                'kota_kabupaten' => $customer['kota_kabupaten'] ?? '',
                'kode_pos' => $customer['kode_pos'] ?? '',
                'discount_type' => $customer['discount_type'],
                'discount_value' => $customer['discount_value'],
            ];

            return $this->jsonResponse->oneResp('', $profile, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Update Customer Profile
    public function updateProfile()
    {
        try {
            $customer = $this->request->customer;
            $data = $this->request->getJSON();

            $updateData = [];

            // Allow updating these fields
            if (isset($data->nama_customer)) $updateData['nama_customer'] = $data->nama_customer;
            if (isset($data->no_hp_customer)) $updateData['no_hp_customer'] = $data->no_hp_customer;
            if (isset($data->alamat)) $updateData['alamat'] = $data->alamat;
            if (isset($data->provinsi)) $updateData['provinsi'] = $data->provinsi;
            if (isset($data->kota_kabupaten)) $updateData['kota_kabupaten'] = $data->kota_kabupaten;
            if (isset($data->kode_pos)) $updateData['kode_pos'] = $data->kode_pos;

            // Handle password change
            if (isset($data->password) && !empty($data->password)) {
                // Require current password for security
                if (empty($data->current_password)) {
                    return $this->jsonResponse->error("Current password required to change password", 400);
                }

                $customerFull = $this->customerModel->find($customer['id']);
                if (!password_verify($data->current_password, $customerFull['password'])) {
                    return $this->jsonResponse->error("Current password is incorrect", 401);
                }

                if (strlen($data->password) < 6) {
                    return $this->jsonResponse->error("New password must be at least 6 characters", 400);
                }

                $updateData['password'] = password_hash($data->password, PASSWORD_DEFAULT);
            }

            // Handle email change (requires re-verification)
            if (isset($data->email) && $data->email !== $customer['email']) {
                // Check if email is already used
                $existingCustomer = $this->customerModel
                    ->where('email', $data->email)
                    ->where('id !=', $customer['id'])
                    ->first();

                if ($existingCustomer) {
                    return $this->jsonResponse->error("Email already in use", 400);
                }

                $verificationToken = bin2hex(random_bytes(32));
                $updateData['email'] = $data->email;
                $updateData['email_verified_at'] = null;
                $updateData['email_verification_token'] = $verificationToken;

                // TODO: Send verification email to new email address
            }

            if (empty($updateData)) {
                return $this->jsonResponse->error("No data to update", 400);
            }

            $this->customerModel->update($customer['id'], $updateData);

            $message = 'Profile updated successfully';
            if (isset($updateData['email'])) {
                $message .= '. Please verify your new email address.';
            }

            return $this->jsonResponse->oneResp($message, [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
