<?php
namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\UserModel;
use App\Models\Jwtoken;

class JwtMiddleware implements FilterInterface
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

            // 🔹 Cek apakah token sudah kedaluwarsa
            if ($decoded->exp < time()) {
                return service('response')->setJSON([
                    'status' => 401,
                    'message' => 'Unauthorized: Token has expired'
                ])->setStatusCode(401);
            }

            // Ambil user_id dari token
            $userId = $decoded->user_id;
            
            // Cari user di database
            $userModel = new UserModel();
            $user = $userModel->select('user_id, name, email, access, permissions')->find($userId);

            if (!$user) {
                return service('response')->setJSON([
                    'status' => 401,
                    'message' => 'Unauthorized: User not found'
                ])->setStatusCode(401);
            }

            // Simpan user ke request agar bisa digunakan di controller
            $request->user = $user;
        } catch (\Exception $e) {
            return service('response')->setJSON([
                'status' => 401,
                'message' => 'Unauthorized: ' . $e->getMessage()
            ])->setStatusCode(401);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Tidak perlu melakukan apa-apa setelah request selesai
    }
}
