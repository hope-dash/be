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
        helper('log');
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

            $this->customerModel->insert($customerData);
            $customerId = $this->customerModel->getInsertID();

            // TODO: Send verification email
            // For now, we'll just return the token (in production, send via email)

            log_aktivitas([
                'user_id' => $customerId,
                'action_type' => 'REGISTER',
                'target_table' => 'customer',
                'target_id' => $customerId,
                'description' => "Customer registered: {$data->email}",
            ]);

            return $this->jsonResponse->oneResp('Registration successful. Please check your email to verify your account.', [
                'customer_id' => $customerId,
                'verification_token' => $verificationToken // Remove this in production
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

            log_aktivitas([
                'user_id' => $customer['id'],
                'action_type' => 'LOGIN',
                'target_table' => 'customer',
                'target_id' => $customer['id'],
                'description' => "Customer logged in: {$customer['email']}",
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
            $customer = $this->request->customer ?? null;
            $idToko = $this->request->getGet('id_toko');
            $limit = (int) $this->request->getGet('limit') ?: 20;
            $page = (int) $this->request->getGet('page') ?: 1;
            $offset = ($page - 1) * $limit;

            $builder = $this->productModel;

            $totalData = $builder->countAllResults(false);
            $totalPage = ceil($totalData / $limit);

            $products = $builder
                ->orderBy('created_at', 'DESC')
                ->limit($limit, $offset)
                ->findAll();

            // Apply customer discount if logged in
            foreach ($products as &$product) {
                $basePrice = $product['harga_jual'];
                $product['original_price'] = $basePrice;
                $product['customer_price'] = $basePrice;
                $product['discount_applied'] = 0;

                if ($customer && $customer['discount_type']) {
                    if ($customer['discount_type'] === 'PERCENTAGE') {
                        $discount = ($basePrice * $customer['discount_value']) / 100;
                        $product['customer_price'] = $basePrice - $discount;
                        $product['discount_applied'] = $discount;
                    } elseif ($customer['discount_type'] === 'FIXED') {
                        $product['customer_price'] = $basePrice - $customer['discount_value'];
                        $product['discount_applied'] = $customer['discount_value'];
                    }
                }

                // Get stock if id_toko provided
                if ($idToko) {
                    $stock = $this->stockModel
                        ->where('id_barang', $product['id_barang'])
                        ->where('id_toko', $idToko)
                        ->first();
                    $product['stock'] = $stock ? $stock['stock'] : 0;
                }
            }

            return $this->jsonResponse->multiResp('', $products, $totalData, $totalPage, $page, $limit, 200);
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

            log_aktivitas([
                'user_id' => $customer['id'],
                'action_type' => 'UPDATE_PROFILE',
                'target_table' => 'customer',
                'target_id' => $customer['id'],
                'description' => "Customer updated profile: {$customer['email']}",
                'detail' => $updateData
            ]);

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
