<?php

namespace App\Libraries;

use App\Models\TokoModel;
use App\Models\ProductModel;
use App\Models\StockModel;
use Exception;

class TiktokService
{
    private TokoModel $tokoModel;
    private $token = null;
    private $shopCipher = null;
    private $idToko = null;
    private $appKey = '';
    private $appSecret = '';
    private $baseUrl = "https://open-api.tiktokglobalshop.com";

    public function __construct()
    {
        $this->tokoModel = new TokoModel();
        $this->appKey = env('TIKTOK_APP_KEY') ?: '';
        $this->appSecret = env('TIKTOK_APP_SECRET') ?: '';
    }

    /**
     * Initialize TikTok config for a specific shop/toko
     */
    public function initializeForToko(int $idToko): self
    {
        $toko = $this->tokoModel->find($idToko);
        if (!$toko) {
            throw new Exception("Toko with ID {$idToko} not found.");
        }

        $this->idToko = $idToko;
        $tokoMetaModel = new \App\Models\TokoMetaModel();
        $this->token = $tokoMetaModel->getMeta($idToko, 'tiktok_access_token');
        $this->shopCipher = $tokoMetaModel->getMeta($idToko, 'tiktok_shop_cipher');

        return $this;
    }

    /**
     * Send signed request to TikTok V2 API
     */
    public function request(string $method, string $path, array $params = [], array $body = []): array
    {
        if (empty($this->token) || empty($this->shopCipher)) {
            throw new Exception("Toko is not integrated or missing TikTok credentials.");
        }

        $params['app_key'] = $this->appKey;
        $params['shop_cipher'] = $this->shopCipher;
        $params['timestamp'] = time();

        $signature = $this->generateSign2($path, $params, $body, $this->appSecret);
        $params['sign'] = $signature;

        $url = $this->baseUrl . $path . "?" . http_build_query($params);
        $curl = curl_init();

        $headers = [
            "Content-Type: application/json",
            "x-tts-access-token: " . $this->token
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 30,
        ];

        if (in_array(strtoupper($method), ['POST', 'PUT'])) {
            $jsonBody = empty($body) ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_POSTFIELDS] = $jsonBody;
        }

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            throw new Exception("TikTok API Connection Error: " . $err);
        }

        $decoded = json_decode($response, true);
        
        // Log API failures
        if (($decoded['code'] ?? 0) !== 0) {
            log_message('error', "[TikTok API Error] Path: {$path}. Response: " . $response);
        }

        return $decoded ?: [];
    }

    /**
     * Generate V2 Signature
     */
    public function generateSign2(string $path, array $params, ?array $body, string $appSecret): string
    {
        $excludeKeys = ["access_token", "sign"];

        $signParams = array_filter($params, function ($key) use ($excludeKeys) {
            return !in_array($key, $excludeKeys);
        }, ARRAY_FILTER_USE_KEY);
        ksort($signParams);

        $signString = $path;
        foreach ($signParams as $key => $value) {
            $signString .= $key . $value;
        }

        if ($body !== null && !empty($body)) {
            $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signString .= $jsonBody;
        }

        $finalString = $appSecret . $signString . $appSecret;
        return hash_hmac('sha256', $finalString, $appSecret);
    }

    /**
     * Sync stock for a specific local product SKU
     */
    public function syncProductStock(int $idProduct, int $idToko): array
    {
        $productModel = new ProductModel();
        $product = $productModel->find($idProduct);
        if (!$product || empty($product['tiktok_product_id'])) {
            return ['success' => false, 'message' => 'Product is not mapped to TikTok Shop yet'];
        }

        $stockModel = new StockModel();
        $stockRecord = $stockModel->where('id_barang', $product['id_barang'])
                                  ->where('id_toko', $idToko)
                                  ->first();

        $currentStock = $stockRecord ? (int)$stockRecord['stock'] : 0;
        
        $tiktokSkuId = $product['tiktok_sku'];
        $warehouseId = 'default';

        if (!empty($product['tiktok_meta'])) {
            $meta = json_decode($product['tiktok_meta'], true);
            $tiktokSkuId = $meta['skus'][0]['id'] ?? $product['tiktok_sku'];
            $warehouseId = $meta['skus'][0]['stock_infos'][0]['warehouse_id'] ?? 'default';
        }

        $this->initializeForToko($idToko);

        $path = "/product/202502/products/stocks";
        $params = ['version' => '202502'];
        $body = [
            'product_id' => $product['tiktok_product_id'],
            'skus' => [
                [
                    'id' => $tiktokSkuId,
                    'stock_infos' => [
                        [
                            'available_stock' => $currentStock,
                            'warehouse_id' => $warehouseId
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = $this->request('PUT', $path, $params, $body);
            return [
                'success' => ($response['code'] ?? -1) === 0,
                'response' => $response
            ];
        } catch (Exception $ex) {
            log_message('error', "[TiktokService] Failed to sync stock for product {$idProduct}: " . $ex->getMessage());
            return ['success' => false, 'message' => $ex->getMessage()];
        }
    }
}
