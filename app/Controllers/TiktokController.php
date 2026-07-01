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
        if (!$idToko) {
            return $this->jsonResponse->error('id_toko wajib diisi', 400);
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');
        $baseUrl = env('app.baseURL');

        // Gunakan redirect URI yang statis (harus match dengan yang ada di TikTok Developer Console)
        $redirectUri = "{$baseUrl}/tiktok_verif";

        $params = [
            'app_key' => $appKey,
            'redirect_uri' => $redirectUri,
            'state' => $idToko, // Gunakan state untuk passing ID Toko
            'timestamp' => time(),
        ];

        // Sign the parameters
        $params['sign'] = $this->createSign($params, $appSecret);

        $url = "https://auth.tiktok-shops.com/oauth/authorize?" . http_build_query($params);

        return $this->jsonResponse->oneResp(
            'Sukses',
            $url,
            200
        );
    }

    /**
     * TikTok Callback Endpoint
     * GET /tiktok_verif
     */
    public function callback()
    {
        $code = $this->request->getGet('code');
        $idToko = $this->request->getGet('state'); // Baca ID Toko dari state

        if (!$code) {
            return view('tiktok/verif', [
                'status' => 'error',
                'message' => 'Integrasi Gagal: Authorization code tidak ditemukan. Silakan coba lagi.',
            ]);
        }

        if (!$idToko) {
            return view('tiktok/verif', [
                'status' => 'error',
                'message' => 'Integrasi Gagal: ID Toko (state) tidak ditemukan. URL Callback tidak valid.',
            ]);
        }

        // 1. Get Toko Data
        $toko = $this->tokoModel->find($idToko);
        if (!$toko) {
            return "Error: Toko with ID {$idToko} not found.";
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');

        // Step 1: Exchange code for token
        $rawBody = [
            'app_key' => $appKey,
            'auth_code' => $code,
            'grant_type' => 'authorized_code',
            'timestamp' => time(),
        ];

        // Auth flow uses createSign
        $sign = $this->createSign($rawBody, $appSecret);

        $finalParams = array_merge($rawBody, [
            'app_secret' => $appSecret,
            'sign' => $sign,
        ]);

        $tokenUrl = "https://auth.tiktok-shops.com/api/v2/token/get?" . http_build_query($finalParams);

        $chToken = curl_init();
        curl_setopt($chToken, CURLOPT_URL, $tokenUrl);
        curl_setopt($chToken, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chToken, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
        $responseTokenJson = curl_exec($chToken);
        curl_close($chToken);

        $responseToken = json_decode($responseTokenJson, true);
        $accessToken = $responseToken['data']['access_token'] ?? null;
        $refreshToken = $responseToken['data']['refresh_token'] ?? null;

        if (!$accessToken) {
            log_message('error', 'TikTok Token Error: ' . $responseTokenJson);
            return view('tiktok/verif', [
                'status' => 'error',
                'message' => 'Integrasi Gagal: Gagal mendapatkan access token. Silakan coba lagi.',
                'response' => $responseToken
            ]);
        }

        // Step 2: Get Shop Cipher
        $shopPath = "/authorization/202309/shops";
        $shopParams = [
            'app_key' => $appKey,
            'timestamp' => time(),
            'version' => '202309',
        ];

        // Sign according to generateSign2 logic (which handles path and body {})
        // Passing null for body because this is a GET request
        $shopSign = $this->generateSign2($shopPath, $shopParams, null, $appSecret);

        $shopFinalParams = array_merge($shopParams, [
            'access_token' => $accessToken,
            'sign' => $shopSign
        ]);

        $shopUrl = "https://open-api.tiktokglobalshop.com" . $shopPath . "?" . http_build_query($shopFinalParams);

        $chShop = curl_init();
        curl_setopt($chShop, CURLOPT_URL, $shopUrl);
        curl_setopt($chShop, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chShop, CURLOPT_HTTPHEADER, ["x-tts-access-token: " . $accessToken]);
        $responseShopJson = curl_exec($chShop);
        curl_close($chShop);

        $responseShop = json_decode($responseShopJson, true);
        $cipher = $responseShop['data']['shops'][0]['cipher'] ?? null;
        $tiktokShopId = $responseShop['data']['shops'][0]['id'] ?? null;

        if (!$cipher) {
            log_message('error', 'TikTok Shops Chiper Error: ' . $responseShopJson);
            return view('tiktok/verif', [
                'status' => 'error',
                'message' => 'Integrasi Gagal: Gagal mendapatkan shop cipher. Silakan coba lagi.',
                'response' => $responseShop
            ]);
        }

        // Step 3: Save to Database
        $tokoMetaModel = new \App\Models\TokoMetaModel();
        $tokoMetaModel->setMeta($idToko, 'tiktok_code', $code);
        $tokoMetaModel->setMeta($idToko, 'tiktok_shop_cipher', $cipher);
        $tokoMetaModel->setMeta($idToko, 'tiktok_shop_id', $tiktokShopId);
        $tokoMetaModel->setMeta($idToko, 'tiktok_access_token', $accessToken);
        $tokoMetaModel->setMeta($idToko, 'tiktok_refresh_token', $refreshToken);

        return view('tiktok/verif', [
            'status' => 'success',
            'message' => 'Integrasi Tokopedia & TikTok Shop Berhasil!',
            'toko' => $toko,
            'code' => $code,
            'cipher' => $cipher
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
                'version' => '202502'
            ];

            // Empty body for search
            $response = $this->makeTiktokRequest($idToko, 'POST', $path, $params, []);

            return $this->jsonResponse->oneResp('Sukses', $response, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Search Products in TikTok Shop
     * POST /api/v2/toko/tiktok/products-search/(:num)
     */
    public function searchProducts($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $payload = $this->request->getJSON(true) ?: [];

            $sellerSku = $payload['seller_sku'] ?? null;
            $pageSize = $payload['page_size'] ?? 10;
            $pageToken = $payload['page_token'] ?? null;

            $path = "/product/202502/products/search";
            $params = [
                'page_size' => (int) $pageSize,
                'version' => '202502'
            ];

            $body = [];

            if (!empty($sellerSku)) {
                if (is_array($sellerSku)) {
                    $body['seller_skus'] = $sellerSku;
                } else {
                    $body['seller_skus'] = [(string) $sellerSku];
                }
            }

            if (!empty($pageToken)) {
                $body['page_token'] = (string) $pageToken;
            }

            $response = $this->makeTiktokRequest($idToko, 'POST', $path, $params, $body);

            return $this->jsonResponse->oneResp('Sukses', $response["data"], 200);
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
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $productData = $this->request->getJSON(true) ?: [];
            $idProduct = $productData['id_product'] ?? null;

            if ($idProduct) {
                $res = $this->uploadProductToTiktok($idProduct, $idToko);
                if ($res['success']) {
                    return $this->jsonResponse->oneResp('Sukses upload produk ke TikTok Shop', [
                        'tiktok_product_id' => $res['tiktok_product_id'],
                        'tiktok_sku' => $res['tiktok_sku'],
                        'tiktok_category_id' => $res['tiktok_category_id'],
                        'response' => $res['response']
                    ], 200);
                } else {
                    return $this->jsonResponse->error($res['message'], 400, $res['response'] ?? null);
                }
            } else {
                // Fallback to sending raw productData as before
                $path = "/product/202309/products";
                $response = $this->makeTiktokRequest($idToko, 'POST', $path, [], $productData);
                return $this->jsonResponse->oneResp('Sukses', $response, 200);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Upload product to TikTok Shop (Internal Helper)
     */
    public function uploadProductToTiktok($idProduct, $idToko)
    {
        try {
            $productModel = new \App\Models\ProductModel();
            $stockModel = new \App\Models\StockModel();

            $product = $productModel->select('product.*, CONCAT(COALESCE(product.nama_barang, ""), " ", COALESCE(mb.nama_model, ""), " ", COALESCE(s.seri, "")) as nama_lengkap_barang')
                ->join('model_barang mb', 'mb.id = product.id_model_barang', 'left')
                ->join('seri s', 's.id = product.id_seri_barang', 'left')
                ->find($idProduct);
            if (!$product) {
                return ['success' => false, 'message' => 'Produk lokal tidak ditemukan'];
            }

            $sku = !empty($product['tiktok_sku']) ? $product['tiktok_sku'] : $product['id_barang'];
            $stockRecord = $stockModel->where('id_barang', $product['id_barang'])
                ->where('id_toko', $idToko)
                ->first();
            $quantity = $stockRecord ? (int) $stockRecord['stock'] : 0;

            $weightKg = !empty($product['berat']) ? (float) $product['berat'] / 1000 : 0.1;
            $weightStr = number_format($weightKg, 2, '.', '');

            $warehouseId = $this->getTiktokWarehouseId($idToko);
            if (!$warehouseId) {
                return ['success' => false, 'message' => 'Gagal mengambil Warehouse ID dari TikTok Shop. Harap pastikan toko Anda memiliki gudang aktif di TikTok.'];
            }

            $length = !empty($product['package_length']) ? (int) $product['package_length'] : 10;
            $width = !empty($product['package_width']) ? (int) $product['package_width'] : 10;
            $height = !empty($product['package_height']) ? (int) $product['package_height'] : 10;

            $categoryId = !empty($product['tiktok_category_id']) ? $product['tiktok_category_id'] : '909832';

            // Fetch and upload main images to TikTok Shop
            $imageModel = new \App\Models\ImageModel();
            $localImages = $imageModel->where('type', 'product')
                ->where('kode', $product['id'])
                ->orderBy('index', 'ASC')
                ->findAll();

            $mainImages = [];
            $debugLogs = [];
            foreach ($localImages as $img) {
                $url = $img['url'];
                $filename = basename($url);
                $filePath = ROOTPATH . 'public/hope/images/' . $filename;
                $tempFile = null;

                if (!file_exists($filePath)) {
                    $debugLogs[] = "Local file not found at: {$filePath}. Trying to download from: {$url}";
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $tempDir = WRITEPATH . 'tmp';
                        if (!is_dir($tempDir)) {
                            @mkdir($tempDir, 0777, true);
                        }
                        $tempFile = $tempDir . '/' . uniqid('img_') . '_' . $filename;

                        $ch = curl_init($url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        $imgData = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlErr = curl_error($ch);
                        curl_close($ch);

                        if ($imgData && $httpCode === 200) {
                            file_put_contents($tempFile, $imgData);
                            $filePath = $tempFile;
                            $debugLogs[] = "Download success. Temp file: {$tempFile}";
                        } else {
                            $debugLogs[] = "Download failed. HTTP Code: {$httpCode}. Curl Error: {$curlErr}";
                        }
                    } else {
                        $debugLogs[] = "Invalid URL: {$url}";
                    }
                } else {
                    $debugLogs[] = "Local file found at: {$filePath}";
                }

                if (file_exists($filePath)) {
                    $debugLogs[] = "File size: " . filesize($filePath) . " bytes";
                    $uploadRes = $this->uploadImageToTiktokDebug($idToko, $filePath);
                    if ($uploadRes['success']) {
                        $mainImages[] = ['uri' => $uploadRes['uri']];
                        $debugLogs[] = "Upload success. TikTok URI: " . $uploadRes['uri'];
                    } else {
                        $debugLogs[] = "Upload failed. TikTok API response: " . $uploadRes['message'];
                    }
                    if ($tempFile && file_exists($tempFile)) {
                        @unlink($tempFile);
                    }
                } else {
                    $debugLogs[] = "File does not exist: {$filePath}";
                }
            }

            if (empty($mainImages)) {
                return ['success' => false, 'message' => 'Produk ini belum memiliki gambar lokal yang valid. (Debug logs: ' . implode(' | ', $debugLogs) . ')'];
            }

            $path = "/product/202309/products";
            $body = [
                'save_mode' => 'LISTING',
                'listing_platforms' => [
                    'TIKTOK_SHOP',
                    'TOKOPEDIA'
                ],
                'title' => !empty($product['nama_lengkap_barang']) ? trim($product['nama_lengkap_barang']) : $product['nama_barang'],
                'description' => !empty($product['description']) ? $product['description'] : $product['nama_barang'],
                'category_id' => $categoryId,
                "category_version" => "v2",
                'brand_id' => '0',
                'main_images' => $mainImages,
                'product_attributes' => [
                    [
                        'id' => '101734',
                        'values' => [
                            [
                                'id' => '1000059'
                            ]
                        ]
                    ]
                ],
                'package_weight' => [
                    'value' => $weightStr,
                    'unit' => 'KILOGRAM'
                ],
                'package_dimensions' => [
                    'length' => (string) $length,
                    'width' => (string) $width,
                    'height' => (string) $height,
                    'unit' => 'CENTIMETER'
                ],
                'skus' => [
                    [
                        'seller_sku' => $sku,
                        'price' => [
                            'amount' => (string) (int) $product['harga_jual'],
                            'currency' => 'IDR'
                        ],
                        'inventory' => [
                            [
                                'quantity' => $quantity,
                                'warehouse_id' => $warehouseId
                            ]
                        ]
                    ]
                ]
            ];

            $response = $this->makeTiktokRequest($idToko, 'POST', $path, [], $body);
            log_message('info', "[TikTok uploadProductToTiktok] Product ID {$product['id']} Response: " . json_encode($response));

            if (($response['code'] ?? 0) === 0 && !empty($response['data']['product_id'])) {
                $productId = $response['data']['product_id'];

                $productModel->update($product['id'], [
                    'tiktok_product_id' => $productId,
                    'tiktok_sku' => $sku,
                    'tiktok_category_id' => $categoryId,
                    'tiktok_meta' => json_encode($response['data'])
                ]);

                // Update or insert Stock table for specific toko
                if ($stockRecord) {
                    $stockModel->update($stockRecord['id'], [
                        'tiktok_product_id' => $productId,
                        'product_tiktok_status' => 'ACTIVE'
                    ]);
                } else {
                    $stockModel->insert([
                        'tenant_id' => $product['tenant_id'],
                        'id_barang' => $product['id_barang'],
                        'id_toko' => $idToko,
                        'stock' => $quantity,
                        'tiktok_product_id' => $productId,
                        'product_tiktok_status' => 'ACTIVE'
                    ]);
                }

                return [
                    'success' => true,
                    'tiktok_product_id' => $productId,
                    'tiktok_sku' => $sku,
                    'tiktok_category_id' => $categoryId,
                    'response' => $response
                ];
            } else {
                $errorMsg = $response['message'] ?? 'Gagal membuat produk di TikTok Shop';
                if (isset($response['code'])) {
                    $errorMsg .= " (Code: " . $response['code'] . ")";
                }
                return ['success' => false, 'message' => $errorMsg, 'response' => $response];
            }
        } catch (\Exception $ex) {
            return ['success' => false, 'message' => $ex->getMessage()];
        }
    }

    /**
     * Sync Product by SKU
     * POST /api/v2/toko/tiktok/product-sync-sku/(:num)
     */
    public function syncProductBySku($idToko = null)
    {
        try {
            $data = $this->request->getJSON(true) ?: [];
            $idProduct = $data['id_product'] ?? null;
            $tiktokSku = $data['tiktok_sku'] ?? null;

            if (!$idProduct || !$tiktokSku) {
                return $this->jsonResponse->error('id_product dan tiktok_sku wajib diisi', 400);
            }

            // 1. Search product in TikTok Shop by seller_sku
            $path = "/product/202502/products/search";
            $params = [
                'version' => '202502'
            ];
            $body = [
                'seller_sku' => [$tiktokSku]
            ];

            $response = $this->makeTiktokRequest($idToko, 'POST', $path, $params, $body);
            log_message('info', '[TikTok syncProductBySku] Search Response: ' . json_encode($response));

            $skuData = null;
            $productId = null;
            $categoryId = null;

            if (($response['code'] ?? 0) === 0 && !empty($response['data']['products'])) {
                $product = $response['data']['products'][0];
                $productId = $product['id'];
                $categoryId = $product['category_chains'][0]['id'] ?? null;

                if (!empty($product['skus'])) {
                    foreach ($product['skus'] as $s) {
                        if ($s['seller_sku'] === $tiktokSku) {
                            $skuData = $s;
                            break;
                        }
                    }
                }
            }

            if (!$productId) {
                return $this->jsonResponse->error('Produk dengan SKU tersebut tidak ditemukan di TikTok Shop', 404);
            }

            // 2. Update local product record
            $productModel = new \App\Models\ProductModel();
            $localProduct = $productModel->find($idProduct);
            if (!$localProduct) {
                return $this->jsonResponse->error('Produk lokal tidak ditemukan', 404);
            }

            $updateData = [
                'tiktok_product_id' => $productId,
                'tiktok_sku' => $tiktokSku,
                'tiktok_category_id' => $categoryId,
                'tiktok_meta' => json_encode($response['data']['products'][0])
            ];

            $productModel->update($idProduct, $updateData);

            // 3. Immediately trigger stock sync to TikTok for this product
            $stockModel = new \App\Models\StockModel();
            $stockRecord = $stockModel->where('id_barang', $localProduct['id_barang'])
                ->where('id_toko', $idToko)
                ->first();

            $currentStock = $stockRecord ? (int) $stockRecord['stock'] : 0;

            $tiktokService = new \App\Libraries\TiktokService();
            $tiktokService->syncProductStock((int) $idProduct, (int) $idToko);

            return $this->jsonResponse->oneResp('Produk berhasil di-sync dengan Tokopedia/TikTok Shop', [
                'tiktok_product_id' => $productId,
                'tiktok_sku' => $tiktokSku,
                'stock_synced' => $currentStock
            ], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Bulk Upload Products to TikTok Shop
     * POST /api/v2/toko/tiktok/products-bulk-upload/(:num)
     */
    public function bulkUploadProducts($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $productModel = new \App\Models\ProductModel();
            $stockModel = new \App\Models\StockModel();

            // Fetch all products that have not been uploaded to TikTok yet
            $products = $productModel->select('product.*, CONCAT(COALESCE(product.nama_barang, ""), " ", COALESCE(mb.nama_model, ""), " ", COALESCE(s.seri, "")) as nama_lengkap_barang')
                ->join('model_barang mb', 'mb.id = product.id_model_barang', 'left')
                ->join('seri s', 's.id = product.id_seri_barang', 'left')
                ->where('tiktok_product_id', null)->findAll();

            if (empty($products)) {
                return $this->jsonResponse->oneResp('Semua produk sudah terunggah ke TikTok Shop', [], 200);
            }

            $warehouseId = $this->getTiktokWarehouseId($idToko);
            if (!$warehouseId) {
                return $this->jsonResponse->error('Gagal mengambil Warehouse ID dari TikTok Shop. Harap pastikan toko Anda memiliki gudang aktif di TikTok.', 400);
            }

            $successCount = 0;
            $failCount = 0;
            $errors = [];

            foreach ($products as $product) {
                try {
                    $sku = !empty($product['tiktok_sku']) ? $product['tiktok_sku'] : $product['id_barang'];
                    $stockRecord = $stockModel->where('id_barang', $product['id_barang'])
                        ->where('id_toko', $idToko)
                        ->first();
                    $quantity = $stockRecord ? (int) $stockRecord['stock'] : 0;

                    $weightKg = !empty($product['berat']) ? (float) $product['berat'] / 1000 : 0.1;
                    $weightStr = number_format($weightKg, 2, '.', '');

                    $length = !empty($product['package_length']) ? (int) $product['package_length'] : 10;
                    $width = !empty($product['package_width']) ? (int) $product['package_width'] : 10;
                    $height = !empty($product['package_height']) ? (int) $product['package_height'] : 10;

                    $categoryId = !empty($product['tiktok_category_id']) ? $product['tiktok_category_id'] : '909832';

                    // Fetch and upload main images to TikTok Shop
                    $imageModel = new \App\Models\ImageModel();
                    $localImages = $imageModel->where('type', 'product')
                        ->where('kode', $product['id'])
                        ->orderBy('index', 'ASC')
                        ->findAll();

                    $mainImages = [];
                    foreach ($localImages as $img) {
                        $url = $img['url'];
                        $filename = basename($url);
                        $filePath = ROOTPATH . 'public/hope/images/' . $filename;
                        $tempFile = null;

                        if (!file_exists($filePath)) {
                            if (filter_var($url, FILTER_VALIDATE_URL)) {
                                $tempDir = WRITEPATH . 'tmp';
                                if (!is_dir($tempDir)) {
                                    @mkdir($tempDir, 0777, true);
                                }
                                $tempFile = $tempDir . '/' . uniqid('img_') . '_' . $filename;

                                $ch = curl_init($url);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                                $imgData = curl_exec($ch);
                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                curl_close($ch);

                                if ($imgData && $httpCode === 200) {
                                    file_put_contents($tempFile, $imgData);
                                    $filePath = $tempFile;
                                }
                            }
                        }

                        if (file_exists($filePath)) {
                            $uri = $this->uploadImageToTiktok($idToko, $filePath);
                            if ($uri) {
                                $mainImages[] = ['uri' => $uri];
                            }
                            if ($tempFile && file_exists($tempFile)) {
                                @unlink($tempFile);
                            }
                        }
                    }

                    if (empty($mainImages)) {
                        $failCount++;
                        $errors[] = "Produk #{$product['id']} ({$product['nama_barang']}): Belum memiliki gambar produk yang valid.";
                        continue;
                    }

                    $path = "/product/202309/products";
                    $body = [
                        'save_mode' => 'LISTING',
                        'listing_platforms' => [
                            'TIKTOK_SHOP',
                            'TOKOPEDIA'
                        ],
                        'title' => !empty($product['nama_lengkap_barang']) ? trim($product['nama_lengkap_barang']) : $product['nama_barang'],
                        'description' => !empty($product['description']) ? $product['description'] : $product['nama_barang'],
                        'category_id' => $categoryId,
                        'brand_id' => '0',
                        'main_images' => $mainImages,
                        'product_attributes' => [
                            [
                                'id' => '101734',
                                'values' => [
                                    [
                                        'id' => '1000059'
                                    ]
                                ]
                            ]
                        ],
                        'package_weight' => [
                            'value' => $weightStr,
                            'unit' => 'KILOGRAM'
                        ],
                        'package_dimensions' => [
                            'length' => (string) $length,
                            'width' => (string) $width,
                            'height' => (string) $height,
                            'unit' => 'CENTIMETER'
                        ],
                        'skus' => [
                            [
                                'seller_sku' => $sku,
                                'price' => [
                                    'amount' => (string) (int) $product['harga_jual'],
                                    'currency' => 'IDR'
                                ],
                                'inventory' => [
                                    [
                                        'quantity' => $quantity,
                                        'warehouse_id' => $warehouseId
                                    ]
                                ]
                            ]
                        ]
                    ];

                    $response = $this->makeTiktokRequest($idToko, 'POST', $path, [], $body);
                    log_message('info', "[TikTok bulkUploadProducts] Product ID {$product['id']} Response: " . json_encode($response));

                    if (($response['code'] ?? 0) === 0 && !empty($response['data']['product_id'])) {
                        $productId = $response['data']['product_id'];

                        $productModel->update($product['id'], [
                            'tiktok_product_id' => $productId,
                            'tiktok_sku' => $sku,
                            'tiktok_category_id' => $categoryId,
                            'tiktok_meta' => json_encode($response['data'])
                        ]);

                        // Update or insert Stock table for specific toko
                        $stockRecord = $stockModel->where('id_barang', $product['id_barang'])
                            ->where('id_toko', $idToko)
                            ->first();

                        if ($stockRecord) {
                            $stockModel->update($stockRecord['id'], [
                                'tiktok_product_id' => $productId,
                                'product_tiktok_status' => 'ACTIVE'
                            ]);
                        } else {
                            $stockModel->insert([
                                'tenant_id' => $product['tenant_id'],
                                'id_barang' => $product['id_barang'],
                                'id_toko' => $idToko,
                                'stock' => $quantity,
                                'tiktok_product_id' => $productId,
                                'product_tiktok_status' => 'ACTIVE'
                            ]);
                        }

                        $successCount++;
                    } else {
                        $failCount++;
                        $errors[] = "Produk #{$product['id']} ({$product['nama_barang']}): " . ($response['message'] ?? 'Unknown API error');
                    }
                } catch (\Exception $ex) {
                    $failCount++;
                    $errors[] = "Produk #{$product['id']} ({$product['nama_barang']}): " . $ex->getMessage();
                }
            }

            return $this->jsonResponse->oneResp('Proses upload selesai', [
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'errors' => $errors
            ], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Sync Stock for a specific product manually
     * POST /api/v2/toko/tiktok/product-sync-stock/(:num)
     */
    public function syncProductStock($idToko = null)
    {
        try {
            $data = $this->request->getJSON(true) ?: [];
            $idProduct = $data['id_product'] ?? null;

            if (!$idProduct) {
                return $this->jsonResponse->error('id_product wajib diisi', 400);
            }

            $tiktokService = new \App\Libraries\TiktokService();
            $result = $tiktokService->syncProductStock((int) $idProduct, (int) $idToko);

            if ($result['success']) {
                return $this->jsonResponse->oneResp('Stok produk berhasil disinkronkan ke TikTok Shop', $result['response'], 200);
            } else {
                return $this->jsonResponse->error($result['message'] ?? 'Failed to sync stock', 400);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Get TikTok Shop Warehouses List
     * GET /api/v2/toko/tiktok/warehouses/(:num)
     */
    public function getWarehouses($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $path = "/logistics/202309/warehouses";
            $response = $this->makeTiktokRequest($idToko, 'GET', $path, [], null);

            return $this->jsonResponse->oneResp('Sukses mengambil daftar gudang dari TikTok Shop', $response, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Get TikTok Shop Categories List
     * GET /api/v2/toko/tiktok/categories/(:num)
     */
    public function getCategories($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $path = "/product/202309/categories";
            $params = [
                'category_version' => 'v1'
            ];
            $response = $this->makeTiktokRequest($idToko, 'GET', $path, $params, null);

            return $this->jsonResponse->oneResp('Sukses mengambil daftar kategori dari TikTok Shop', $response['data'], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Get TikTok Shop Category Attributes
     * GET /api/v2/toko/tiktok/attributes/(:num)/(:num)
     */
    public function getAttributes($idToko = null, $categoryId = null)
    {
        try {
            if (!$idToko || !$categoryId) {
                return $this->jsonResponse->error('id_toko dan category_id wajib diisi', 400);
            }

            $path = "/product/202309/categories/attributes";
            $params = [
                'category_id' => $categoryId,
                'category_version' => 'v2'
            ];
            $response = $this->makeTiktokRequest($idToko, 'GET', $path, $params, null);

            return $this->jsonResponse->oneResp('Sukses mengambil daftar atribut kategori dari TikTok Shop', $response, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Fetch the first enabled Warehouse ID from TikTok Shop API
     */
    private function getTiktokWarehouseId($idToko)
    {
        try {
            $path = "/logistics/202309/warehouses";
            $response = $this->makeTiktokRequest($idToko, 'GET', $path, [], null);
            if (($response['code'] ?? -1) === 0 && !empty($response['data']['warehouses'])) {
                foreach ($response['data']['warehouses'] as $wh) {
                    if (($wh['is_default'] ?? false) === true) {
                        return $wh['id'];
                    }
                }
                return $response['data']['warehouses'][0]['id'];
            }
        } catch (\Exception $e) {
            log_message('error', '[TikTok getTiktokWarehouseId Error] ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Helper to make signed requests to TikTok Shop API
     */
    public function makeTiktokRequest($idToko, $method, $path, $params = [], $body = [])
    {
        $toko = $this->tokoModel->find($idToko);
        if (!$toko) {
            throw new \Exception("Toko tidak ditemukan.");
        }

        $tokoMetaModel = new \App\Models\TokoMetaModel();
        $accessToken = $tokoMetaModel->getMeta($idToko, 'tiktok_access_token');
        $shopCipher = $tokoMetaModel->getMeta($idToko, 'tiktok_shop_cipher');

        if (!$accessToken || !$shopCipher) {
            throw new \Exception("Toko belum terintegrasi TikTok.");
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');
        $params['app_key'] = $appKey;
        if (strpos($path, 'global_warehouses') === false && strpos($path, '/authorization/') === false) {
            $params['shop_cipher'] = $shopCipher;
        }
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

        if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
            $jsonBody = empty($body) ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

        // 4. Always append the stringified body IF body is provided (usually for POST/PUT)
        // TikTok V2 signature requires {} for empty object body in POST
        // For GET requests, the body part should be omitted from the signature string
        if ($body !== null) {
            $jsonBody = empty($body) ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signString .= $jsonBody;
        }

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

    /**
     * Upload binary image to TikTok Shop using multipart/form-data
     */
    private function uploadImageToTiktok($idToko, $filePath)
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $toko = $this->tokoModel->find($idToko);
        if (!$toko) {
            return null;
        }

        $tokoMetaModel = new \App\Models\TokoMetaModel();
        $accessToken = $tokoMetaModel->getMeta($idToko, 'tiktok_access_token');
        $shopCipher = $tokoMetaModel->getMeta($idToko, 'tiktok_shop_cipher');

        if (!$accessToken || !$shopCipher) {
            return null;
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');

        $path = "/product/202309/images/upload";
        $params = [
            'app_key' => $appKey,
            'timestamp' => time(),
        ];

        // Sign for multipart/form-data: pass null as body
        $signature = $this->generateSign2($path, $params, null, $appSecret);
        $params['sign'] = $signature;

        $url = "https://open-api.tiktokglobalshop.com" . $path . "?" . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        $headers = [
            "Content-Type: multipart/form-data",
            "x-tts-access-token: " . $accessToken
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $cfile = new \CURLFile($filePath, mime_content_type($filePath), basename($filePath));
        $postData = [
            'use_case' => 'MAIN_IMAGE',
            'data' => $cfile
        ];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        curl_close($ch);

        $resDecoded = json_decode($response, true);
        if (($resDecoded['code'] ?? -1) === 0 && !empty($resDecoded['data']['uri'])) {
            return $resDecoded['data']['uri'];
        }

        log_message('error', '[TikTok Image Upload Error] Response: ' . $response);
        return null;
    }

    /**
     * Upload binary image to TikTok Shop returning debug status/response
     */
    private function uploadImageToTiktokDebug($idToko, $filePath)
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'File does not exist locally'];
        }

        $toko = $this->tokoModel->find($idToko);
        if (!$toko) {
            return ['success' => false, 'message' => 'Toko not found'];
        }

        $tokoMetaModel = new \App\Models\TokoMetaModel();
        $accessToken = $tokoMetaModel->getMeta($idToko, 'tiktok_access_token');
        $shopCipher = $tokoMetaModel->getMeta($idToko, 'tiktok_shop_cipher');

        if (!$accessToken || !$shopCipher) {
            return ['success' => false, 'message' => 'TikTok credentials not found in metadata'];
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');

        $path = "/product/202309/images/upload";
        $params = [
            'app_key' => $appKey,
            'timestamp' => time(),
        ];

        // Sign for multipart/form-data: pass null as body
        $signature = $this->generateSign2($path, $params, null, $appSecret);
        $params['sign'] = $signature;

        $url = "https://open-api.tiktokglobalshop.com" . $path . "?" . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        $headers = [
            "Content-Type: multipart/form-data",
            "x-tts-access-token: " . $accessToken
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $cfile = new \CURLFile($filePath, mime_content_type($filePath), basename($filePath));
        $postData = [
            'use_case' => 'MAIN_IMAGE',
            'data' => $cfile
        ];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'message' => 'cURL Error: ' . $err];
        }

        $resDecoded = json_decode($response, true);
        if (($resDecoded['code'] ?? -1) === 0 && !empty($resDecoded['data']['uri'])) {
            return ['success' => true, 'uri' => $resDecoded['data']['uri']];
        }

        return ['success' => false, 'message' => 'HTTP ' . $httpCode . ' Response: ' . $response];
    }

    /**
     * Refresh Token for TikTok Shop
     * POST /api/v2/toko/tiktok/refresh-token/(:num)
     */
    public function refreshToken($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $res = $this->performTokenRefresh($idToko);
            if ($res['success']) {
                return $this->jsonResponse->oneResp($res['message'], [
                    'access_token' => $res['access_token'],
                    'refresh_token' => $res['refresh_token']
                ], 200);
            } else {
                return $this->jsonResponse->error($res['message'], 400, $res['response'] ?? null);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Internal helper to refresh token
     */
    public function performTokenRefresh($idToko)
    {
        $tokoMetaModel = new \App\Models\TokoMetaModel();
        $refreshToken = $tokoMetaModel->getMeta($idToko, 'tiktok_refresh_token');

        if (!$refreshToken) {
            return [
                'success' => false,
                'message' => 'Refresh token tidak ditemukan di database.'
            ];
        }

        $appKey = env('TIKTOK_APP_KEY');
        $appSecret = env('TIKTOK_APP_SECRET');

        $rawBody = [
            'app_key' => $appKey,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'timestamp' => time(),
        ];

        $sign = $this->createSign($rawBody, $appSecret);

        $finalParams = array_merge($rawBody, [
            'app_secret' => $appSecret,
            'sign' => $sign,
        ]);

        $tokenUrl = "https://auth.tiktok-shops.com/api/v2/token/refresh?" . http_build_query($finalParams);

        $chToken = curl_init();
        curl_setopt($chToken, CURLOPT_URL, $tokenUrl);
        curl_setopt($chToken, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chToken, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
        $responseTokenJson = curl_exec($chToken);
        curl_close($chToken);

        $responseToken = json_decode($responseTokenJson, true);
        $accessToken = $responseToken['data']['access_token'] ?? null;
        $newRefreshToken = $responseToken['data']['refresh_token'] ?? null;

        if (!$accessToken) {
            log_message('error', "TikTok Refresh Token Error for Shop ID {$idToko}: " . $responseTokenJson);
            return [
                'success' => false,
                'message' => 'Gagal memperbarui access token.',
                'response' => $responseToken
            ];
        }

        $tokoMetaModel->setMeta($idToko, 'tiktok_access_token', $accessToken);
        $tokoMetaModel->setMeta($idToko, 'tiktok_refresh_token', $newRefreshToken);

        return [
            'success' => true,
            'message' => 'Token berhasil diperbarui.',
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken
        ];
    }

    /**
     * Delete Products from TikTok Shop
     * DELETE /api/v2/toko/tiktok/products/delete/(:num)
     */
    public function deleteProducts($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $payload = $this->request->getJSON(true) ?: [];
            $productIds = $payload['product_ids'] ?? null;

            if (empty($productIds)) {
                return $this->jsonResponse->error('product_ids wajib diisi', 400);
            }

            $path = "/product/202309/products";
            $response = $this->makeTiktokRequest($idToko, 'DELETE', $path, [], [
                'product_ids' => $productIds
            ]);

            if (($response['code'] ?? 0) === 0) {
                return $this->jsonResponse->oneResp('Sukses menghapus produk dari TikTok Shop', $response, 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal menghapus produk', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Deactivate Products in TikTok Shop
     * POST /api/v2/toko/tiktok/products/deactivate/(:num)
     */
    public function deactivateProducts($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $payload = $this->request->getJSON(true) ?: [];
            $productIds = $payload['product_ids'] ?? null;
            $listingPlatforms = $payload['listing_platforms'] ?? ['TIKTOK_SHOP'];

            if (empty($productIds)) {
                return $this->jsonResponse->error('product_ids wajib diisi', 400);
            }

            $path = "/product/202309/products/deactivate";
            $response = $this->makeTiktokRequest($idToko, 'POST', $path, [], [
                'product_ids' => $productIds,
                'listing_platforms' => $listingPlatforms
            ]);

            if (($response['code'] ?? 0) === 0) {
                return $this->jsonResponse->oneResp('Sukses menonaktifkan produk di TikTok Shop', $response, 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal menonaktifkan produk', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Activate Products in TikTok Shop
     * POST /api/v2/toko/tiktok/products/activate/(:num)
     */
    public function activateProducts($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $payload = $this->request->getJSON(true) ?: [];
            $productIds = $payload['product_ids'] ?? null;
            $listingPlatforms = $payload['listing_platforms'] ?? ['TIKTOK_SHOP'];

            if (empty($productIds)) {
                return $this->jsonResponse->error('product_ids wajib diisi', 400);
            }

            $path = "/product/202309/products/activate";
            $response = $this->makeTiktokRequest($idToko, 'POST', $path, [], [
                'product_ids' => $productIds,
                'listing_platforms' => $listingPlatforms
            ]);

            if (($response['code'] ?? 0) === 0) {
                return $this->jsonResponse->oneResp('Sukses mengaktifkan produk di TikTok Shop', $response, 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal mengaktifkan produk', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Get Product Details from TikTok Shop
     * GET /api/v2/toko/tiktok/products/get/(:num)/(:any)
     */
    public function getProductDetails($idToko = null, $productId = null)
    {
        try {
            if (!$idToko || !$productId) {
                return $this->jsonResponse->error('id_toko dan product_id wajib diisi', 400);
            }

            $path = "/product/202309/products/" . $productId;

            $params = [];
            if ($this->request->getGet('return_under_review_version') !== null) {
                $params['return_under_review_version'] = $this->request->getGet('return_under_review_version') === 'true' ? 'true' : 'false';
            }
            if ($this->request->getGet('return_draft_version') !== null) {
                $params['return_draft_version'] = $this->request->getGet('return_draft_version') === 'true' ? 'true' : 'false';
            }
            if ($this->request->getGet('locale') !== null) {
                $params['locale'] = $this->request->getGet('locale');
            }

            $response = $this->makeTiktokRequest($idToko, 'GET', $path, $params, null);

            if (($response['code'] ?? 0) === 0) {
                return $this->jsonResponse->oneResp('Sukses mengambil detail produk dari TikTok Shop', $response, 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal mengambil detail produk', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Update Product Price in TikTok Shop
     * POST /api/v2/toko/tiktok/products/price-update/(:num)/(:any)
     */
    public function updateProductPrice($idToko = null, $productId = null)
    {
        try {
            if (!$idToko || !$productId) {
                return $this->jsonResponse->error('id_toko dan product_id wajib diisi', 400);
            }

            $payload = $this->request->getJSON(true) ?: [];
            $skus = $payload['skus'] ?? null;

            if (empty($skus)) {
                return $this->jsonResponse->error('skus wajib diisi', 400);
            }

            $path = "/product/202309/products/" . $productId . "/prices/update";
            $response = $this->makeTiktokRequest($idToko, 'POST', $path, [], [
                'skus' => $skus
            ]);

            if (($response['code'] ?? 0) === 0) {
                return $this->jsonResponse->oneResp('Sukses meng-update harga produk di TikTok Shop', $response, 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal meng-update harga produk', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Update Product Inventory/Stock in TikTok Shop
     * POST /api/v2/toko/tiktok/products/inventory-update/(:num)/(:any)
     */
    public function updateProductInventory($idToko = null, $productId = null)
    {
        try {
            if (!$idToko || !$productId) {
                return $this->jsonResponse->error('id_toko dan product_id wajib diisi', 400);
            }

            $payload = $this->request->getJSON(true) ?: [];
            $skus = $payload['skus'] ?? null;

            if (empty($skus)) {
                return $this->jsonResponse->error('skus wajib diisi', 400);
            }

            $path = "/product/202309/products/" . $productId . "/inventory/update";
            $response = $this->makeTiktokRequest($idToko, 'POST', $path, [], [
                'skus' => $skus
            ]);

            if (($response['code'] ?? 0) === 0) {
                return $this->jsonResponse->oneResp('Sukses meng-update inventori produk di TikTok Shop', $response, 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal meng-update inventori produk', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Edit Product details (Full Update) in TikTok Shop
     * PUT /api/v2/toko/tiktok/products/edit/(:num)/(:any)
     */
    public function editProduct($idToko = null, $productId = null)
    {
        try {
            if (!$idToko || !$productId) {
                return $this->jsonResponse->error('id_toko dan product_id wajib diisi', 400);
            }

            $payload = $this->request->getJSON(true) ?: [];

            // Edit product uses PUT /product/202509/products/{product_id}
            $path = "/product/202509/products/" . $productId;
            $response = $this->makeTiktokRequest($idToko, 'PUT', $path, [], $payload);

            if (($response['code'] ?? 0) === 0) {
                return $this->jsonResponse->oneResp('Sukses meng-update detail produk di TikTok Shop', $response, 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal meng-update detail produk', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Map TikTok Shop ID to local toko ID
     */
    private function getTokoIdByShopId($shopId)
    {
        $tokoMetaModel = new \App\Models\TokoMetaModel();

        // 1. Try to find cached shop ID in toko_meta
        $meta = $tokoMetaModel->where('meta_key', 'tiktok_shop_id')
            ->where('meta_value', $shopId)
            ->first();
        if ($meta) {
            return (int) $meta['toko_id'];
        }

        // 2. If not cached, fetch shops list for each integrated toko
        $integratedTokos = $tokoMetaModel->where('meta_key', 'tiktok_access_token')->findAll();
        foreach ($integratedTokos as $tToken) {
            $tokoId = (int) $tToken['toko_id'];
            try {
                $path = "/authorization/202309/shops";
                $response = $this->makeTiktokRequest($tokoId, 'GET', $path, [
                    'version' => '202309'
                ], null);

                if (($response['code'] ?? -1) === 0 && !empty($response['data']['shops'])) {
                    foreach ($response['data']['shops'] as $shop) {
                        if ($shop['id'] == $shopId) {
                            // Cache it for future requests
                            $tokoMetaModel->setMeta($tokoId, 'tiktok_shop_id', $shopId);
                            // Also save cipher if not present
                            if (!empty($shop['cipher'])) {
                                $tokoMetaModel->setMeta($tokoId, 'tiktok_shop_cipher', $shop['cipher']);
                            }
                            return $tokoId;
                        }
                    }
                }
            } catch (\Exception $e) {
                log_message('error', "[getTokoIdByShopId] Error checking shops for Toko ID {$tokoId}: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * TikTok Shop Webhook Handler
     * POST /api/v2/tiktok/webhook
     */
    public function webhook()
    {
        $rawBody = file_get_contents('php://input');
        log_message('error', '[TikTok Webhook Raw Body] ' . $rawBody);
        $payload = json_decode($rawBody, true, 512, JSON_BIGINT_AS_STRING);
        if (!$payload) {
            return $this->response->setStatusCode(400)->setJSON(['message' => 'Invalid JSON payload']);
        }

        $type = isset($payload['type']) ? (int) $payload['type'] : null;
        $shopId = $payload['shop_id'] ?? null;

        if ($type === 5 || $type === 15) {
            $data = $payload['data'] ?? [];
            $productId = $data['product_id'] ?? null;

            if ($productId && $shopId) {
                // 1. Map shop_id to local toko_id
                $idToko = $this->getTokoIdByShopId($shopId);
                if ($idToko) {
                    try {
                        // 2. Fetch product details from TikTok Shop API
                        $path = "/product/202309/products/" . $productId;
                        $response = $this->makeTiktokRequest($idToko, 'GET', $path, [], null);

                        log_message('info', "[TikTok Webhook Type 5/15] GET Product details response: " . json_encode($response));

                        if (($response['code'] ?? -1) === 0 && !empty($response['data'])) {
                            $productData = $response['data'];
                            $tiktokStatus = $productData['status'] ?? ($data['status'] ?? 'UNKNOWN');

                            // Parse Tokopedia Status
                            $tokopediaStatus = null;
                            if (!empty($productData['integrated_platform_statuses'])) {
                                foreach ($productData['integrated_platform_statuses'] as $platStatus) {
                                    if (strtoupper($platStatus['platform'] ?? '') === 'TOKOPEDIA') {
                                        $tokopediaStatus = $platStatus['status'] ?? null;
                                        break;
                                    }
                                }
                            }

                            // Find matching product locally using seller_sku or product_id
                            $sellerSku = null;
                            if (!empty($productData['skus'])) {
                                $sellerSku = $productData['skus'][0]['seller_sku'] ?? null;
                            }

                            $productModel = new \App\Models\ProductModel();
                            $localProduct = null;

                            if ($sellerSku) {
                                $localProduct = $productModel->where('id_barang', $sellerSku)->first();
                            }

                            if (!$localProduct) {
                                $localProduct = $productModel->where('tiktok_product_id', $productId)->first();
                            }

                            if ($localProduct) {
                                // Update Product table (only tiktok_product_id if empty)
                                if (empty($localProduct['tiktok_product_id'])) {
                                    $productModel->update($localProduct['id'], [
                                        'tiktok_product_id' => $productId
                                    ]);
                                }

                                // Update Stock table
                                $stockModel = new \App\Models\StockModel();
                                $stockRecord = $stockModel->where('id_barang', $localProduct['id_barang'])
                                    ->where('id_toko', $idToko)
                                    ->first();

                                $updateStockData = [
                                    'tiktok_product_id' => $productId,
                                    'product_tiktok_status' => $tiktokStatus
                                ];
                                if ($tokopediaStatus !== null) {
                                    $updateStockData['product_tokopedia_status'] = $tokopediaStatus;
                                }

                                if ($stockRecord) {
                                    $stockModel->update($stockRecord['id'], $updateStockData);
                                } else {
                                    $insertStockData = [
                                        'tenant_id' => $localProduct['tenant_id'],
                                        'id_barang' => $localProduct['id_barang'],
                                        'id_toko' => $idToko,
                                        'stock' => 0,
                                        'tiktok_product_id' => $productId,
                                        'product_tiktok_status' => $tiktokStatus
                                    ];
                                    if ($tokopediaStatus !== null) {
                                        $insertStockData['product_tokopedia_status'] = $tokopediaStatus;
                                    }
                                    $stockModel->insert($insertStockData);
                                }

                                log_aktivitas([
                                    'user_id' => 0, // system
                                    'action_type' => 'TIKTOK_WEBHOOK_UPDATE',
                                    'target_table' => 'product',
                                    'target_id' => $localProduct['id'],
                                    'description' => "Updated TikTok product {$localProduct['id_barang']} status via Webhook to {$tiktokStatus} (Tokopedia: " . ($tokopediaStatus ?? 'N/A') . ")"
                                ]);
                            } else {
                                log_message('warning', "[TikTok Webhook Type 5] No matching local product found for SKU {$sellerSku} / TikTok ID {$productId}");
                            }
                        }
                    } catch (\Exception $ex) {
                        log_message('error', "[TikTok Webhook Type 5] Error fetching/updating product: " . $ex->getMessage());
                    }
                } else {
                    log_message('warning', "[TikTok Webhook Type 5] No local Toko found for shop_id {$shopId}");
                }
            }
        }

        if ($type === 1) {
            $data = $payload['data'] ?? [];
            $orderId = $data['order_id'] ?? null;

            if ($orderId && $shopId) {
                $idToko = $this->getTokoIdByShopId($shopId);
                if ($idToko) {
                    try {
                        // Fetch order details from TikTok Shop API
                        $path = "/order/202507/orders";
                        $params = [
                            'ids' => $orderId,
                            'version' => '202507'
                        ];
                        $response = $this->makeTiktokRequest($idToko, 'GET', $path, $params, null);

                        log_message('info', "[TikTok Webhook Type 1] GET Order details response: " . json_encode($response));

                        if (($response['code'] ?? -1) === 0 && !empty($response['data']['orders'])) {
                            $order = $response['data']['orders'][0];
                            $this->syncTiktokOrder($idToko, $order);
                        }
                    } catch (\Exception $ex) {
                        log_message('error', "[TikTok Webhook Type 1] Error processing order: " . $ex->getMessage());
                    }
                }
            }
        }

        // TikTok webhook expects 200 OK with code:0 / success response
        return $this->response->setJSON([
            'code' => 0,
            'message' => 'success'
        ]);
    }

    /**
     * Synchronize a TikTok order to local transactions
     */
    private function syncTiktokOrder($idToko, $order)
    {
        $toko = $this->tokoModel->find($idToko);
        if (!$toko) {
            log_message('error', "[syncTiktokOrder] Toko not found: {$idToko}");
            return;
        }
        $tenantId = $toko['tenant_id'];

        // Scope queries and inserts to this tenant
        \App\Libraries\TenantContext::set(['id' => $tenantId]);

        $transactionModel = new \App\Models\TransactionModel();
        $salesProductModel = new \App\Models\SalesProductModel();
        $productModel = new \App\Models\ProductModel();
        $stockModel = new \App\Models\StockModel();
        $stockLedgerModel = new \App\Models\StockLedgerModel();
        $transactionMetaModel = new \App\Models\TransactionMetaModel();
        $customerModel = new \App\Models\CustomerModel();

        $orderId = $order['id'];
        $tiktokStatus = strtoupper($order['status'] ?? 'UNPAID');

        // Map status
        $localStatus = 'WAITING_PAYMENT';
        if ($tiktokStatus === 'CANCEL') {
            $localStatus = 'CANCEL';
        } elseif (in_array($tiktokStatus, ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'IN_TRANSIT'])) {
            $localStatus = 'PAID';
        } elseif ($tiktokStatus === 'DELIVERED') {
            $localStatus = 'DELIVERED';
        } elseif ($tiktokStatus === 'COMPLETED') {
            $localStatus = 'COMPLETED';
        }

        $existingTrx = $transactionModel->where('invoice', $orderId)->first();

        if ($existingTrx) {
            // Transaction exists, check if status changed
            $currentStatus = $existingTrx['status'];

            if ($currentStatus !== $localStatus) {
                // If transitioning to CANCEL, restore stock
                if ($localStatus === 'CANCEL' && $currentStatus !== 'CANCEL') {
                    $items = $salesProductModel->where('id_transaction', $existingTrx['id'])->findAll();
                    foreach ($items as $item) {
                        if (!$item['is_service']) {
                            // Restore Stock
                            $stockEntry = $stockModel->where('id_barang', $item['kode_barang'])->where('id_toko', $idToko)->first();
                            if (!$stockEntry) {
                                $stockModel->insert([
                                    'id_barang' => $item['kode_barang'],
                                    'id_toko' => $idToko,
                                    'stock' => 0,
                                    'barang_cacat' => 0
                                ]);
                                $stockEntry = $stockModel->where('id_barang', $item['kode_barang'])->where('id_toko', $idToko)->first();
                            }
                            $newStock = $stockEntry['stock'] + $item['jumlah'];
                            $stockModel->update($stockEntry['id'], ['stock' => $newStock]);

                            $stockLedgerModel->insert([
                                'id_barang' => $item['kode_barang'],
                                'id_toko' => $idToko,
                                'qty' => $item['jumlah'],
                                'balance' => $newStock,
                                'reference_type' => 'RETURN',
                                'reference_id' => $existingTrx['id'],
                                'description' => "TikTok Cancel Order Webhook: {$orderId}"
                            ]);

                            // Removed syncProductStock API call to prevent double sync since it is initiated from TikTok
                        }
                    }
                }

                // If transitioning to PAID, update payment total
                $updateData = ['status' => $localStatus];
                if ($localStatus === 'PAID') {
                    $updateData['total_payment'] = $existingTrx['actual_total'];
                }

                $transactionModel->update($existingTrx['id'], $updateData);

                log_aktivitas([
                    'user_id' => 0, // system
                    'action_type' => 'UPDATE_TRANSACTION_STATUS',
                    'target_table' => 'transaction',
                    'target_id' => $existingTrx['id'],
                    'description' => "Updated TikTok order {$orderId} status from {$currentStatus} to {$localStatus}"
                ]);
            }
        } else {
            // Customer Handling
            $buyerEmail = $order['buyer_email'] ?? '';
            $recipient = $order['recipient_address'] ?? [];
            $customerId = null;

            if (!empty($buyerEmail)) {
                $existingCust = $customerModel->where('email', $buyerEmail)->first();
                if ($existingCust) {
                    $customerId = $existingCust['id'];
                } else {
                    $custData = [
                        'tenant_id' => $tenantId,
                        'nama_customer' => $buyerEmail,
                        'email' => $buyerEmail,
                        'no_hp_customer' => $recipient['phone_number'] ?? '',
                        'alamat' => $recipient['full_address'] ?? '',
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $customerModel->insert($custData);
                    $customerId = $customerModel->getInsertID();
                }
            }

            // Create New Transaction
            $subTotal = (float)($order['payment']['sub_total'] ?? ($order['payment']['original_total_product_price'] ?? 0));
            $grandTotal = (float)($order['payment']['total_amount'] ?? 0);
            $shippingCost = (float)($order['payment']['shipping_fee'] ?? 0);

            // Calculate total COGS
            $cogsTotal = 0;
            $itemsToProcess = [];

            // Process line item values
            $lineItems = $order['line_items'] ?? [];
            foreach ($lineItems as $item) {
                $sellerSku = $item['seller_sku'] ?? null;
                if (!$sellerSku) {
                    continue;
                }

                $product = $productModel->where('id_barang', $sellerSku)->first();
                $modalSystem = $product ? (float)($product['harga_modal'] ?? 0) : 0;
                $qty = isset($item['quantity']) ? (int)$item['quantity'] : (isset($item['qty']) ? (int)$item['qty'] : 1);

                $salePrice = (float)($item['sale_price'] ?? 0);
                $originalPrice = (float)($item['original_price'] ?? 0);

                $itemsToProcess[] = [
                    'seller_sku' => $sellerSku,
                    'product' => $product,
                    'modal_system' => $modalSystem,
                    'qty' => $qty,
                    'sale_price' => $salePrice,
                    'original_price' => $originalPrice
                ];

                $cogsTotal += $modalSystem * $qty;
            }

            $trxData = [
                'tenant_id' => $tenantId,
                'invoice' => $orderId,
                'amount' => $subTotal,
                'actual_total' => $grandTotal,
                'total_payment' => ($localStatus === 'WAITING_PAYMENT' || $localStatus === 'CANCEL') ? 0 : $grandTotal,
                'status' => $localStatus,
                'id_toko' => $idToko,
                'date_time' => date('Y-m-d H:i:s', $order['create_time'] ?? time()),
                'is_service' => 0,
                'source' => $order['commerce_platform'] ?? 'TIKTOK_SHOP',
                'pengiriman' => $order['delivery_option_name'] ?? 'Standard shipping',
                'biaya_pengiriman' => $shippingCost,
                'total_modal' => $cogsTotal,
                'created_by' => 0
            ];

            $trxId = $transactionModel->insert($trxData);

            if ($trxId) {
                // Insert Meta data
                $metaData = [
                    'customer_id' => $customerId,
                    'customer_name' => $buyerEmail,
                    'customer_phone' => $recipient['phone_number'] ?? '',
                    'alamat' => $recipient['full_address'] ?? '',
                    'provinsi' => '',
                    'kota_kabupaten' => '',
                    'kecamatan' => '',
                    'kelurahan' => '',
                    'kode_pos' => $recipient['postal_code'] ?? '',
                    'buyer_email' => $buyerEmail,
                    'buyer_name' => $recipient['name'] ?? '',
                    'buyer_phone' => $recipient['phone_number'] ?? '',
                    'buyer_address' => $recipient['full_address'] ?? '',
                    'payment_method' => $order['payment_method_name'] ?? '',
                    'biaya_pengiriman' => $shippingCost,
                    'shipping_type' => $order['shipping_type'] ?? '',
                    'source' => $order['commerce_platform'] ?? 'TIKTOK_SHOP'
                ];

                foreach ($metaData as $mk => $mv) {
                    $transactionMetaModel->insert([
                        'transaction_id' => $trxId,
                        'key' => $mk,
                        'value' => (string)$mv
                    ]);
                }

                // Process Line Items (Products & Stock deduction)
                foreach ($itemsToProcess as $item) {
                    $sellerSku = $item['seller_sku'];
                    $product = $item['product'];
                    $modalSystem = $item['modal_system'];
                    $qty = $item['qty'];
                    $salePrice = $item['sale_price'];
                    $originalPrice = $item['original_price'];

                    $salesProductModel->insert([
                        'tenant_id' => $tenantId,
                        'id_transaction' => $trxId,
                        'actual_per_piece' => $salePrice,
                        'actual_total' => $salePrice * $qty,
                        'kode_barang' => $sellerSku,
                        'jumlah' => $qty,
                        'harga_system' => $originalPrice,
                        'harga_jual' => $salePrice,
                        'total' => $salePrice * $qty,
                        'modal_system' => $modalSystem,
                        'total_modal' => $modalSystem * $qty,
                        'is_service' => 0
                    ]);

                    // Deduct Stock
                    $stockEntry = $stockModel->where('id_barang', $sellerSku)->where('id_toko', $idToko)->first();
                    if (!$stockEntry) {
                        $stockModel->insert([
                            'id_barang' => $sellerSku,
                            'id_toko' => $idToko,
                            'stock' => 0,
                            'barang_cacat' => 0
                        ]);
                        $stockEntry = $stockModel->where('id_barang', $sellerSku)->where('id_toko', $idToko)->first();
                    }

                    $newStock = $stockEntry['stock'] - $qty;
                    $stockModel->update($stockEntry['id'], ['stock' => $newStock]);

                    $stockLedgerModel->insert([
                        'id_barang' => $sellerSku,
                        'id_toko' => $idToko,
                        'qty' => -$qty,
                        'balance' => $newStock,
                        'reference_type' => 'TRANSACTION',
                        'reference_id' => $trxId,
                        'description' => "TikTok Order Webhook Created: {$orderId}"
                    ]);

                    // Removed syncProductStock API call to prevent double sync since it is initiated from TikTok
                }

                // -- Accounting: Sales Journal --
                $journalId = $this->createJournal('SALES', $trxId, $orderId, date('Y-m-d'), "Invoice #{$orderId}", $idToko);

                // 1. Dr AR (Total Receivables)
                $this->addJournalItem($journalId, '10' . $idToko . '3', $grandTotal, 0, $idToko);

                // 3. Cr Sales Revenue (subTotal)
                if ($subTotal > 0) {
                    $this->addJournalItem($journalId, '40' . $idToko . '1', 0, $subTotal, $idToko);
                }

                // 5. Shipping Logic (buyer pays shipping, Cr Shipping Revenue)
                if ($shippingCost > 0) {
                    $this->addJournalItem($journalId, '40' . $idToko . '1', 0, $shippingCost, $idToko);
                }

                // -- Accounting: COGS Journal --
                if ($cogsTotal > 0) {
                    $cogsJournalId = $this->createJournal('COGS', $trxId, $orderId, date('Y-m-d'), "COGS Invoice {$orderId}", $idToko);
                    $this->addJournalItem($cogsJournalId, '50' . $idToko . '1', $cogsTotal, 0, $idToko); // Dr COGS
                    $this->addJournalItem($cogsJournalId, '10' . $idToko . '4', 0, $cogsTotal, $idToko); // Cr Inventory
                }

                log_aktivitas([
                    'user_id' => 0, // system
                    'action_type' => 'CREATE_TRANSACTION',
                    'target_table' => 'transaction',
                    'target_id' => $trxId,
                    'description' => "Created TikTok order transaction {$orderId} with status {$localStatus}"
                ]);
            }
        }
    }

    private function createJournal($refType, $refId, $refNo, $date, $desc, $tokoId = null)
    {
        $journalModel = new \App\Models\JournalModel();
        $data = [
            'id_toko' => $tokoId,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'reference_no' => $refNo,
            'date' => $date,
            'description' => $desc,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $journalModel->insert($data);
        return $journalModel->getInsertID();
    }

    private function addJournalItem($journalId, $accountCode, $debit, $credit, $tokoId = null)
    {
        $db = \Config\Database::connect();
        $journalItemModel = new \App\Models\JournalItemModel();

        $account = $db->table('accounts')
            ->where('base_code', $accountCode)
            ->where('id_toko', $tokoId)
            ->get()->getRowArray();

        if (!$account) {
            $account = $db->table('accounts')
                ->where('code', $accountCode)
                ->get()->getRowArray();
        }

        if (!$account) {
            return;
        }

        $journalItemModel->insert([
            'journal_id' => $journalId,
            'account_id' => $account['id'],
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get Order Details from TikTok Shop
     * GET /api/v2/toko/tiktok/orders/get/(:num)/(:any)
     */
    public function getOrderDetails($idToko = null, $orderIds = null)
    {
        try {
            if (!$idToko || !$orderIds) {
                return $this->jsonResponse->error('id_toko dan order_ids wajib diisi', 400);
            }

            // Path for orders API (version 202507)
            $path = "/order/202507/orders";
            $params = [
                'ids' => $orderIds,
                'version' => '202507'
            ];

            // GET request to TikTok API
            // Pass null as body since it's a GET request
            $response = $this->makeTiktokRequest($idToko, 'GET', $path, $params, null);

            if (($response['code'] ?? -1) === 0) {
                return $this->jsonResponse->oneResp('Sukses mengambil detail pesanan dari TikTok Shop', $response["data"]["orders"][0], 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal mengambil detail pesanan', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
}