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
     * Fetch the default warehouse ID for a shop
     */
    public function getWarehouseId(int $idToko): string
    {
        try {
            $this->initializeForToko($idToko);
            $response = $this->request('GET', '/logistics/202309/warehouses');
            if (($response['code'] ?? -1) === 0 && !empty($response['data']['warehouses'])) {
                foreach ($response['data']['warehouses'] as $wh) {
                    if (($wh['is_default'] ?? false) === true) {
                        return $wh['id'];
                    }
                }
                return $response['data']['warehouses'][0]['id'];
            }
        } catch (\Exception $e) {
            log_message('error', '[TiktokService getWarehouseId Error] ' . $e->getMessage());
        }
        return 'default';
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
        $warehouseId = null;

        if (!empty($product['tiktok_meta'])) {
            $meta = json_decode($product['tiktok_meta'], true);
            $tiktokSkuId = $meta['skus'][0]['id'] ?? $product['tiktok_sku'];
            $warehouseId = $meta['skus'][0]['inventory'][0]['warehouse_id'] 
                ?? $meta['skus'][0]['stock_infos'][0]['warehouse_id'] 
                ?? null;
        }

        if (empty($warehouseId) || $warehouseId === 'default') {
            $warehouseId = $this->getWarehouseId($idToko);
        }

        $this->initializeForToko($idToko);

        $path = "/product/202309/products/" . $product['tiktok_product_id'] . "/inventory/update";
        $params = [];
        $body = [
            'skus' => [
                [
                    'id' => $tiktokSkuId,
                    'inventory' => [
                        [
                            'quantity' => $currentStock,
                            'warehouse_id' => $warehouseId
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = $this->request('POST', $path, $params, $body);

            // Log activity
            helper('log');
            $userId = null;
            try {
                $request = \Config\Services::request();
                if (isset($request->user['user_id'])) {
                    $userId = $request->user['user_id'];
                }
            } catch (\Exception $e) {}

            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'SYNC_TIKTOK_STOCK',
                'target_table' => 'stock',
                'target_id' => $stockRecord ? $stockRecord['id'] : null,
                'description' => "Sinkronisasi stok produk {$product['id_barang']} ke TikTok Shop (" . ($response['message'] ?? 'SUKSES') . "). Stok: {$currentStock}.",
                'detail' => [
                    'id_product' => $idProduct,
                    'id_toko' => $idToko,
                    'tiktok_product_id' => $product['tiktok_product_id'],
                    'sku' => $tiktokSkuId,
                    'warehouse_id' => $warehouseId,
                    'stock' => $currentStock,
                    'response' => $response
                ]
            ]);

            return [
                'success' => ($response['code'] ?? -1) === 0,
                'response' => $response
            ];
        } catch (Exception $ex) {
            log_message('error', "[TiktokService] Failed to sync stock for product {$idProduct}: " . $ex->getMessage());
            return ['success' => false, 'message' => $ex->getMessage()];
        }
    }

    /**
     * Sync price for a specific local product to a TikTok Shop
     */
    public function syncProductPrice(int $idProduct, int $idToko): array
    {
        $productModel = new ProductModel();
        $product = $productModel->find($idProduct);
        if (!$product || empty($product['tiktok_product_id'])) {
            return ['success' => false, 'message' => 'Product is not mapped to TikTok Shop yet'];
        }

        $price = (float)$product['harga_jual'];

        $tiktokSkuId = $product['tiktok_sku'];
        if (!empty($product['tiktok_meta'])) {
            $meta = json_decode($product['tiktok_meta'], true);
            $tiktokSkuId = $meta['skus'][0]['id'] ?? $product['tiktok_sku'];
        }

        $this->initializeForToko($idToko);

        $path = "/product/202309/products/" . $product['tiktok_product_id'] . "/prices/update";
        $params = [];
        $body = [
            'skus' => [
                [
                    'id' => $tiktokSkuId,
                    'price' => [
                        'amount' => (string)(int)$price,
                        'currency' => 'IDR'
                    ]
                ]
            ]
        ];

        try {
            $response = $this->request('POST', $path, $params, $body);

            // Log activity
            helper('log');
            $userId = null;
            try {
                $request = \Config\Services::request();
                if (isset($request->user['user_id'])) {
                    $userId = $request->user['user_id'];
                }
            } catch (\Exception $e) {}

            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'SYNC_TIKTOK_PRICE',
                'target_table' => 'product',
                'target_id' => $idProduct,
                'description' => "Sinkronisasi harga produk {$product['id_barang']} ke TikTok Shop (" . ($response['message'] ?? 'SUKSES') . "). Harga: IDR " . number_format($price, 0, ',', '.') . ".",
                'detail' => [
                    'id_product' => $idProduct,
                    'id_toko' => $idToko,
                    'price' => $price,
                    'response' => $response
                ]
            ]);

            return [
                'success' => ($response['code'] ?? -1) === 0,
                'response' => $response
            ];
        } catch (Exception $ex) {
            log_message('error', "[TiktokService] Failed to sync price for product {$idProduct} in toko {$idToko}: " . $ex->getMessage());
            return ['success' => false, 'message' => $ex->getMessage()];
        }
    }

    /**
     * Sync price for a local product to all connected TikTok shops
     */
    public function syncPriceToAllShops(int $idProduct): array
    {
        $productModel = new ProductModel();
        $product = $productModel->find($idProduct);
        if (!$product) {
            return ['success' => false, 'message' => 'Product not found'];
        }

        $stockModel = new StockModel();
        $stockRecords = $stockModel->where('id_barang', $product['id_barang'])
                                   ->where('tiktok_product_id !=', '')
                                   ->where('tiktok_product_id !=', null)
                                   ->findAll();

        $results = [];
        foreach ($stockRecords as $sr) {
            $res = $this->syncProductPrice($idProduct, (int)$sr['id_toko']);
            $results[$sr['id_toko']] = $res;
        }

        return [
            'success' => true,
            'results' => $results
        ];
    }
}
