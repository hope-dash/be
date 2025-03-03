<?php

namespace App\Models;

use CodeIgniter\Model;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
class Jwtoken
{
    private $secretKey;

    public function __construct(){
        $this->secretKey = getenv("JWT_KEY");
    }

    public function generateToken($data, $expiry = 86400) {
        $iat = time();
        $exp = time() + $expiry;
        $payload = [
            'exp' => $exp,
            'iat' => $iat,
            'user_id' => $data['user_id'],
        ];
        $token = JWT::encode($payload, $this->secretKey,'HS256');
        return $token;
    }

    public function validateToken($token) {
        try {
            return JWT::decode($token, new Key($this->secretKey, 'HS256'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
