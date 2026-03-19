<?php

namespace App\Controllers;

use App\Models\JsonResponse;
use App\Models\TokoModel;
use CodeIgniter\RESTful\ResourceController;

class TiktokController extends ResourceController
{
    protected $jsonResponse;
    protected $tokoModel;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->tokoModel = new TokoModel();
    }

    /**
     * Generate TikTok Authorization URL
     * GET /api/toko/tiktok-auth-url?id_toko={id}
     */
    public function getAuthUrl($idToko = null)
    {
        //$idToko = $this->request->getGet('id_toko');
        if (!$idToko) {
            return $this->jsonResponse->error('id_toko wajib diisi', 400);
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');
        $baseUrl = env('app.baseURL');

        // Target redirect URI specified by user
        $redirectUri = "{$baseUrl}/tiktok_verif/{$idToko}";

        $params = [
            'app_key' => $appKey,
            'redirect_uri' => $redirectUri,
            'timestamp' => time(),
        ];

        // Sign the parameters
        $params['sign'] = $this->createSign($params, $appSecret);

        $url = "https://auth.tiktok-shops.com/oauth/authorize?" . http_build_query($params);

        return $this->jsonResponse->oneResp('Sukses',
            $url,
            200);
    }

    /**
     * TikTok Callback Endpoint
     * GET /tiktok_verif/(:num)
     */
    public function callback($idToko = null)
    {
        $code = $this->request->getGet('code');
        $shopId = $this->request->getGet('shop_id'); // Some versions send this
        $cipher = $this->request->getGet('cipher'); // User mentioned tiktok-shop_chiper

        // TikTok can send cipher in different param names depending on version
        $cipher = $cipher ?? $this->request->getGet('shop_cipher');

        if (!$code) {
            return "Error: Authorization code not found.";
        }

        // Save progress to Toko first
        $toko = $this->tokoModel->find($idToko);
        if (!$toko) {
            return "Error: Toko with ID {$idToko} not found.";
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');

        // Exchange code for token
        $rawBody = [
            'app_key' => $appKey,
            'auth_code' => $code,
            'grant_type' => 'authorized_code',
            'timestamp' => time(),
        ];

        // sign TANPA app_secret di dalam body dulu
        $sign = $this->createSign($rawBody, $appSecret);

        $finalParams = array_merge($rawBody, [
            'app_secret' => $appSecret,
            'sign' => $sign,
        ]);

        $tokenUrl = "https://auth.tiktok-shops.com/api/v2/token/get?" . http_build_query($finalParams);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/x-www-form-urlencoded",
        ]);
        $responseJson = curl_exec($ch);
        curl_close($ch);

        $responseData = json_decode($responseJson, true);
        $accessToken = null;
        $refreshToken = null;

        if (isset($responseData['data']['access_token'])) {
            $accessToken = $responseData['data']['access_token'];
            $refreshToken = $responseData['data']['refresh_token'] ?? null;
        }
        else {
            // Log error or handle failure
            log_message('error', 'TikTok Token Error: ' . ($responseJson ?: 'No response'));
        }

        $this->tokoModel->update($idToko, [
            'tiktok_code' => $code,
            'tiktok_shop_cipher' => $cipher,
            'tiktok_access_token' => $accessToken,
            'tiktok_refresh_token' => $refreshToken,
        ]);

        return view('tiktok/verif', [
            'status' => $accessToken ? 'success' : 'partial',
            'message' => $accessToken ? 'Integrasi TikTok Shop Berhasil!' : 'Berhasil mendapatkan code, namun gagal mendapatkan token.',
            'toko' => $toko,
            'code' => $code,
            'cipher' => $cipher,
            'response' => $responseData
        ]);
    }

    /**
     * Create Signature for TikTok Shop API
     */
    private function createSign($params, $secret)
    {
        // 1. Sort parameters by key
        ksort($params);

        // 2. Concatenate as keyvaluekeyvalue
        $string = $secret;
        foreach ($params as $key => $value) {
            if ($key != "sign" && $key != "access_token") {
                $string .= $key . $value;
            }
        }
        $string .= $secret;

        // 3. HMAC-SHA256 (Common for TikTok)
        return hash_hmac('sha256', $string, $secret);
    }
}