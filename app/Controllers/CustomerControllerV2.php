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

            // Validation (Removed is_unique because we handle it manually for empty password cases)
            $validation = \Config\Services::validation();
            $validation->setRules([
                'nama_customer' => 'required',
                'email' => 'required|valid_email',
                'password' => 'required|min_length[6]',
                'no_hp_customer' => 'required',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            // Check if customer already exists by email
            $existingByEmail = $this->customerModel->where('email', $data->email)->first();
            if ($existingByEmail && !empty($existingByEmail['password'])) {
                return $this->jsonResponse->error("Email sudah terdaftar", 400);
            }

            // Check if customer already exists by phone number
            $existingByPhone = $this->customerModel->where('no_hp_customer', $data->no_hp_customer)->first();
            if ($existingByPhone && !empty($existingByPhone['password'])) {
                return $this->jsonResponse->error("Nomor HP sudah terdaftar", 400);
            }

            // Decide which record to update (Priority: Email match, then Phone match)
            $existingCustomer = $existingByEmail ?? $existingByPhone;

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
                'kecamatan' => $data->kecamatan ?? '',
                'kelurahan' => $data->kelurahan ?? '',
                'kode_pos' => $data->kode_pos ?? '',
                'type' => 'regular',
                'email_verification_token' => $verificationToken,
                'email_verified_at' => null, // Ensure it needs to be verified
            ];

            if ($existingCustomer) {
                // Update existing record if password was empty (Linked via Email or Phone)
                $this->customerModel->update($existingCustomer['id'], $customerData);
                $customerId = $existingCustomer['id'];
            } else {
                // Insert new record
                $customerId = $this->customerModel->insert($customerData);
            }

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

            // Apply store filter if id_toko is provided
            if ($idToko) {
                $builder->join('stock', 'stock.id_barang = product.id_barang')
                    ->join('toko', 'toko.id = stock.id_toko')
                    ->where('stock.id_toko', $idToko)
                    ->where('stock.stock >', 0)
                    ->select('stock.stock as current_stock, toko.toko_name');
            }

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

            if (!empty($productIds)) {
                // If idToko is present, we already have the stock in the $products array
                if ($idToko) {
                    foreach ($products as $p) {
                        $stockMap[$p['id_barang']] = [
                            'total' => (int) $p['current_stock'],
                            'details' => [
                                [
                                    'id_toko' => $idToko,
                                    'toko_name' => $p['toko_name'] ?? 'Unknown Store',
                                    'stock' => (int) $p['current_stock']
                                ]
                            ]
                        ];
                    }
                } else {
                    // Multi store mode: Fetch all CABANG stocks
                    $stockBuilder = $this->db->table('stock')
                        ->select('stock.*, toko.toko_name')
                        ->join('toko', 'toko.id = stock.id_toko')
                        ->where('toko.type', 'CABANG')
                        ->whereIn('id_barang', $productIds);

                    $stocks = $stockBuilder->get()->getResultArray();

                    foreach ($stocks as $s) {
                        if (!isset($stockMap[$s['id_barang']])) {
                            $stockMap[$s['id_barang']] = [
                                'total' => 0,
                                'details' => []
                            ];
                        }

                        $stockMap[$s['id_barang']]['total'] += (int) $s['stock'];
                        $stockMap[$s['id_barang']]['details'][] = [
                            'id_toko' => $s['id_toko'],
                            'toko_name' => $s['toko_name'] ?? 'Unknown Store',
                            'stock' => (int) $s['stock']
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

                $stockInfo = $stockMap[$product['id_barang']] ?? ['total' => 0, 'details' => []];

                $item = [
                    'id' => $product['id'],
                    'id_barang' => $product['id_barang'],
                    'nama_barang' => $product['nama_barang'],
                    'nama_lengkap_barang' => $namaLengkap,
                    'harga_jual' => (int) $basePrice,
                    'customer_price' => (int) $customerPrice,
                    'discount_applied' => (int) $discountApplied,
                    'stock_total' => $stockInfo['total'],
                    'stock_details' => $stockInfo['details'],
                ];

                // Map images
                $item['images'] = $imageMap[$product['id']] ?? [];

                $finalProducts[] = $item;
            }

            return $this->jsonResponse->multiResp('', $finalProducts, $totalData, $totalPage, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Get Product Detail for Customer
     * - Applies customer discount if authenticated
     * - Removes sensitive fields (harga_modal, supplier, etc)
     */
    public function getProductDetail($id = null)
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
                    // Fetch fresh customer data
                    $customer = $this->customerModel
                        ->select('id, discount_type, discount_value')
                        ->find($decoded->customer_id);
                }
            }

            // Fetch product basic info (Select specific fields only!)
            $product = $this->productModel
                ->select([
                    'product.id',
                    'product.id_barang',
                    'product.nama_barang',
                    'product.harga_jual',
                    'product.description',
                    'model_barang.nama_model',
                    'seri.seri'
                ])
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->where('product.id', $id)
                ->first();

            if (!$product) {
                return $this->jsonResponse->error('Product Not Found', 404);
            }

            // Construct full name
            $namaLengkap = trim(implode(' ', array_filter([
                $product['nama_barang'],
                $product['nama_model'] ?? '',
                $product['seri'] ?? ''
            ])));

            // Calculate Customer Price
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

            // Fetch Images
            $images = $this->db->table('image')
                ->select('url')
                ->where('type', 'product')
                ->where('kode', $product['id'])
                ->get()
                ->getResultArray();

            $imageUrls = array_column($images, 'url');

            // Fetch Stock Breakdown
            $stockDetails = $this->db->table('stock')
                ->select('stock.stock, stock.id_toko, toko.toko_name')
                ->join('toko', 'toko.id = stock.id_toko')
                ->where('toko.type', 'CABANG')
                ->where('stock.id_barang', $product['id_barang'])
                ->get()
                ->getResultArray();

            $totalStock = 0;
            $formattedStock = [];
            foreach ($stockDetails as $s) {
                $formattedStock[] = [
                    'id_toko' => $s['id_toko'],
                    'toko_name' => $s['toko_name'] ?? 'Unknown Store',
                    'stock' => (int) $s['stock']
                ];
                $totalStock += (int) $s['stock'];
            }

            // Construct Response
            $response = [
                'id' => $product['id'],
                'id_barang' => $product['id_barang'],
                'nama_barang' => $product['nama_barang'],
                'nama_lengkap_barang' => $namaLengkap,
                'nama_model' => $product['nama_model'] ?? null,
                'seri' => $product['seri'] ?? null,
                'deskripsi' => $product['deskripsi'] ?? null,
                'harga_jual' => (int) $basePrice,
                'customer_price' => (int) $customerPrice,
                'discount_applied' => (int) $discountApplied,
                'stock_total' => $totalStock,
                'stock_details' => $formattedStock,
                'images' => $imageUrls
            ];

            return $this->jsonResponse->oneResp('Data berhasil diambil', $response);

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

            $profile = $this->customerModel->builder()
                ->select('customer.id, customer.nama_customer, customer.email, customer.no_hp_customer, customer.alamat, customer.provinsi, customer.kota_kabupaten, customer.kecamatan, customer.kelurahan, customer.kode_pos, customer.discount_type, customer.discount_value, provincy.name as nama_provinsi, kota_kabupaten.name as nama_kota, kecamatan.name as nama_kecamatan, kelurahan.name as nama_kelurahan')
                ->join('provincy', 'customer.provinsi = provincy.code', 'left')
                ->join('kota_kabupaten', 'customer.kota_kabupaten = kota_kabupaten.code', 'left')
                ->join('kecamatan', 'customer.kecamatan = kecamatan.code', 'left')
                ->join('kelurahan', 'customer.kelurahan = kelurahan.code', 'left')
                ->where('customer.id', $customer['id'])
                ->get()
                ->getRowArray();

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
            if (isset($data->nama_customer))
                $updateData['nama_customer'] = $data->nama_customer;
            if (isset($data->no_hp_customer))
                $updateData['no_hp_customer'] = $data->no_hp_customer;
            if (isset($data->alamat))
                $updateData['alamat'] = $data->alamat;
            if (isset($data->provinsi))
                $updateData['provinsi'] = $data->provinsi;
            if (isset($data->kota_kabupaten))
                $updateData['kota_kabupaten'] = $data->kota_kabupaten;
            if (isset($data->kecamatan))
                $updateData['kecamatan'] = $data->kecamatan;
            if (isset($data->kelurahan))
                $updateData['kelurahan'] = $data->kelurahan;
            if (isset($data->kode_pos))
                $updateData['kode_pos'] = $data->kode_pos;

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
