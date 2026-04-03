<?php
namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use App\Models\UserModel;
use App\Models\CustomerModel;
use App\Models\Jwtoken;

class AnyJwtMiddleware implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');
        $token = null;

        if ($header && preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            $token = $matches[1];
        } else {
            // Check for token in query parameter (useful for SSE/EventSource)
            $token = $request->getGet('token') ?? $request->getGet('access_token');
        }

        if (!$token) {
            return service('response')->setJSON([
                'status' => 401,
                'message' => 'Unauthorized: Token not provided'
            ])->setStatusCode(401);
        }

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

            // Check for User
            if (isset($decoded->user_id)) {
                $userModel = new UserModel();
                $user = $userModel->select('user_id, name, email, access, permissions')->find($decoded->user_id);
                if ($user) {
                    $request->user = $user;
                    return;
                }
            }

            // Check for Customer
            if (isset($decoded->customer_id)) {
                $customerModel = new CustomerModel();
                $customer = $customerModel->select('id, nama_customer, email, no_hp_customer, discount_type, discount_value, email_verified_at')->find($decoded->customer_id);
                if ($customer) {
                    $request->customer = $customer;
                    return;
                }
            }

            return service('response')->setJSON([
                'status' => 401,
                'message' => 'Unauthorized: User/Customer not found'
            ])->setStatusCode(401);

        } catch (\Exception $e) {
            return service('response')->setJSON([
                'status' => 401,
                'message' => 'Unauthorized: ' . $e->getMessage()
            ])->setStatusCode(401);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
