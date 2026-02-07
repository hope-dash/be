<?php
namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\CustomerModel;
use App\Models\Jwtoken;

class CustomerJwtMiddleware implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');

        if (!$header || !preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            return service('response')->setJSON([
                'status' => 401,
                'message' => 'Unauthorized: Token not provided'
            ])->setStatusCode(401);
        }

        $token = $matches[1];

        try {
            $jwt = new Jwtoken();
            $decoded = $jwt->validateToken($token);

            if (!$decoded) {
                return service('response')->setJSON([
                    'status' => 401,
                    'message' => 'Unauthorized: Invalid token'
                ])->setStatusCode(401);
            }

            // Check if token has expired
            if ($decoded->exp < time()) {
                return service('response')->setJSON([
                    'status' => 401,
                    'message' => 'Unauthorized: Token has expired'
                ])->setStatusCode(401);
            }

            // Check if this is a customer token
            if (!isset($decoded->customer_id)) {
                return service('response')->setJSON([
                    'status' => 401,
                    'message' => 'Unauthorized: Invalid customer token'
                ])->setStatusCode(401);
            }

            // Get customer from database
            $customerId = $decoded->customer_id;
            
            $customerModel = new CustomerModel();
            $customer = $customerModel->select('id, nama_customer, email, no_hp_customer, alamat, provinsi, kota_kabupaten, kode_pos, discount_type, discount_value, email_verified_at')->find($customerId);

            if (!$customer) {
                return service('response')->setJSON([
                    'status' => 401,
                    'message' => 'Unauthorized: Customer not found'
                ])->setStatusCode(401);
            }

            if (!$customer['email_verified_at']) {
                return service('response')->setJSON([
                    'status' => 403,
                    'message' => 'Forbidden: Email not verified'
                ])->setStatusCode(403);
            }

            // Store customer in request for use in controllers
            $request->customer = $customer;
        } catch (\Exception $e) {
            return service('response')->setJSON([
                'status' => 401,
                'message' => 'Unauthorized: ' . $e->getMessage()
            ])->setStatusCode(401);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after request
    }
}
