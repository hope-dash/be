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
     * Get All Products (Search)
     * POST /api/v2/toko/tiktok/products/(:num)
     */
    public function getProducts($idToko = null)
    {
        try {
            $path = "/product/202502/products/search";
            $params = [
                'page_size' => 10,
                'version'   => '202502'
            ];
            
            // Empty body for search
            $response = $this->makeTiktokRequest($idToko, 'POST', $path, $params, []);
            
            return $this->jsonResponse->oneResp('Sukses', $response, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Create Product
     * POST /api/v2/toko/tiktok/product-create/(:num)
     */
    public function createProduct($idToko = null)
    {
        try {
            $path = "/product/202309/products";
            $productData = $this->request->getJSON(true) ?: [];
            
            $response = $this->makeTiktokRequest($idToko, 'POST', $path, [], $productData);
            
            return $this->jsonResponse->oneResp('Sukses', $response, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Helper to make signed requests to TikTok Shop API
     */
    private function makeTiktokRequest($idToko, $method, $path, $params = [], $body = [])
    {
        $toko = $this->tokoModel->find($idToko);
        if (!$toko || !$toko['tiktok_access_token']) {
            throw new \Exception("Toko tidak ditemukan atau belum terintegrasi TikTok.");
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');
        $accessToken = $toko['tiktok_access_token'];
        $shopCipher = $toko['tiktok_shop_cipher'];

        $params['app_key'] = $appKey;
        $params['shop_cipher'] = $shopCipher;
        $params['timestamp'] = time();

        $signature = $this->generateSign2($path, $params, $body, $appSecret);

        $params['sign'] = $signature;
        
        $url = "https://open-api.tiktokglobalshop.com" . $path . "?" . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        
        $headers = [
            "Content-Type: application/json",
            "x-tts-access-token: " . $accessToken
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST' || $method === 'PUT') {
            $jsonBody = empty($body) ? '{}' : json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * New Signature Generation (V2)
     */
    private function generateSign2($path, $params, $body, $appSecret)
    {
        $excludeKeys = ["access_token", "sign"];

        // 1. Sort keys (excluding access_token and sign)
        $signParams = array_filter($params, function ($key) use ($excludeKeys) {
            return !in_array($key, $excludeKeys);
        }, ARRAY_FILTER_USE_KEY);
        ksort($signParams);

        // 2. Start with the path
        $signString = $path;

        // 3. Append sorted key-value pairs
        foreach ($signParams as $key => $value) {
            $signString .= $key . $value;
        }

        // 4. Always append the stringified body
        // TikTok V2 signature requires {} for empty body
        $jsonBody = empty($body) ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signString .= $jsonBody;

        // 5. Wrap with app secret
        $finalString = $appSecret . $signString . $appSecret;

        // 6. Generate HMAC-SHA256
        return hash_hmac('sha256', $finalString, $appSecret);
    }

    /**
     * Create Signature for TikTok Shop API (Original/Auth flow)
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