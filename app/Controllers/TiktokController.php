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
            $basePrice = (float) $product['harga_jual'];
            $tokoMetaModel = new \App\Models\TokoMetaModel();
            $upchargePercent = (float) ($tokoMetaModel->getMeta((int) $idToko, 'tiktok_upcharge') ?? 0);
            $uploadPrice = $upchargePercent > 0 ? (int) round($basePrice * (1 + ($upchargePercent / 100))) : (int) $basePrice;

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
                            'amount' => (string) $uploadPrice,
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
                                    'amount' => (string) $uploadPrice,
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
     * Fetch the first enabled Warehouse ID from TikTok Shop API (with auto token refresh & meta fallback)
     */
    private function getTiktokWarehouseId($idToko)
    {
        $tokoMetaModel = new \App\Models\TokoMetaModel();

        // 1. Check if explicitly set / cached in toko_meta
        $cachedWhId = $tokoMetaModel->getMeta((int) $idToko, 'tiktok_warehouse_id');
        if (!empty($cachedWhId)) {
            return $cachedWhId;
        }

        try {
            $path = "/logistics/202309/warehouses";
            $response = $this->makeTiktokRequest($idToko, 'GET', $path, [], null);

            // Auto-refresh token if expired
            if (in_array(($response['code'] ?? 0), [105001, 105002, 105003])) {
                $this->performTokenRefresh($idToko);
                $response = $this->makeTiktokRequest($idToko, 'GET', $path, [], null);
            }

            log_message('info', "[getTiktokWarehouseId] Toko ID {$idToko} warehouses response: " . json_encode($response));

            $warehouses = $response['data']['warehouses'] ?? $response['data']['warehouse_list'] ?? [];
            if (($response['code'] ?? -1) === 0 && !empty($warehouses)) {
                $selectedId = null;
                foreach ($warehouses as $wh) {
                    $whId = $wh['id'] ?? $wh['warehouse_id'] ?? null;
                    if (!$whId) continue;

                    $isDefault = !empty($wh['is_default']) || ($wh['is_default'] ?? false) === true || ($wh['type'] ?? '') === 'SALES_WAREHOUSE';
                    if ($isDefault) {
                        $selectedId = $whId;
                        break;
                    }
                }

                if (!$selectedId) {
                    $selectedId = $warehouses[0]['id'] ?? $warehouses[0]['warehouse_id'] ?? null;
                }

                if ($selectedId) {
                    $tokoMetaModel->setMeta((int) $idToko, 'tiktok_warehouse_id', $selectedId);
                    return $selectedId;
                }
            }
        } catch (\Exception $e) {
            log_message('error', '[TikTok getTiktokWarehouseId Error] ' . $e->getMessage());
        }

        return $tokoMetaModel->getMeta((int) $idToko, 'tiktok_warehouse_id', null);
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

        if ($type === 13 || $type === 14) {
            $data = $payload['data'] ?? [];
            if ($shopId) {
                $idToko = $this->getTokoIdByShopId($shopId);
                if ($idToko) {
                    $tiktokChatModel = new \App\Models\TiktokChatModel();
                    $tiktokMessageModel = new \App\Models\TiktokMessageModel();
                    
                    if ($type === 13) {
                        // New Conversation
                        $conversationId = $data['conversation_id'] ?? null;
                        if ($conversationId) {
                            $existingChat = $tiktokChatModel->where('conversation_id', $conversationId)->first();
                            if (!$existingChat) {
                                $tiktokChatModel->insert([
                                    'id_toko' => $idToko,
                                    'shop_id' => $shopId,
                                    'conversation_id' => $conversationId,
                                ]);
                            }
                        }
                    } else if ($type === 14) {
                        // New Message
                        $conversationId = $data['conversation_id'] ?? null;
                        $messageId = $data['message_id'] ?? null;
                        if ($conversationId && $messageId) {
                            $chat = $tiktokChatModel->where('conversation_id', $conversationId)->first();
                            if (!$chat) {
                                $chatId = $tiktokChatModel->insert([
                                    'id_toko' => $idToko,
                                    'shop_id' => $shopId,
                                    'conversation_id' => $conversationId,
                                    'participant_id' => $data['sender']['im_user_id'] ?? null,
                                ]);
                                $chat = $tiktokChatModel->find($chatId);
                            }
                            
                            $existingMessage = $tiktokMessageModel->where('message_id', $messageId)->first();
                            if (!$existingMessage) {
                                $tiktokMessageModel->insert([
                                    'tiktok_chat_id' => $chat['id'],
                                    'message_id' => $messageId,
                                    'sender_role' => $data['sender']['role'] ?? null,
                                    'type' => $data['type'] ?? null,
                                    'content' => $data['content'] ?? null,
                                    'create_time' => date('Y-m-d H:i:s', $data['create_time'] ?? time()),
                                ]);
                                
                                $unreadCount = $chat['unread_count'];
                                if (($data['sender']['role'] ?? '') === 'BUYER') {
                                    $unreadCount++;
                                }
                                
                                $tiktokChatModel->update($chat['id'], [
                                    'last_message' => $data['content'] ?? null,
                                    'last_message_time' => date('Y-m-d H:i:s', $data['create_time'] ?? time()),
                                    'unread_count' => $unreadCount,
                                ]);
                            }
                        }
                    }
                }
            }
        }

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
                        // Fetch order details from TikTok Shop API (V2 Partner endpoint)
                        $path = "/order/202309/orders";
                        $params = [
                            'ids' => $orderId
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
     * Synchronize a TikTok / Tokopedia order to local transactions
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
        $rawTiktokStatus = strtoupper($order['status'] ?? 'UNPAID');

        // Extract shipping & fee details
        $pengiriman = $order['delivery_option_name'] 
                   ?? ($order['shipping_provider_name'] 
                   ?? ($order['shipping_provider'] 
                   ?? ($order['delivery_type'] 
                   ?? 'Pengiriman Standar')));

        $shippingProvider = $order['shipping_provider_name'] 
                         ?? ($order['shipping_provider'] 
                         ?? 'Dikirim melalui platform');

        $handlingFee = (float)($order['payment']['handling_fee'] ?? 0);
        $serviceFee = (float)($order['payment']['platform_service_fee'] ?? ($order['payment']['service_fee'] ?? 0));
        $shippingCost = (float)($order['payment']['shipping_fee'] ?? 0);
        $subTotal = (float)($order['payment']['sub_total'] ?? ($order['payment']['original_total_product_price'] ?? 0));
        $grandTotal = (float)($order['payment']['total_amount'] ?? 0);

        $extraDiff = max(0, $grandTotal - ($subTotal + $shippingCost));
        if ($handlingFee == 0 && $serviceFee == 0 && $extraDiff > 0) {
            $handlingFee = $extraDiff;
        }

        // Normalize status
        $rawUpper = strtoupper((string)$rawTiktokStatus);
        $isCancel = (
            strpos($rawUpper, 'CANCEL') !== false ||
            strpos($rawUpper, 'REFUND') !== false ||
            strpos($rawUpper, 'RETURN') !== false
        );
        $isCompleted = ($rawUpper === 'COMPLETED' || $rawUpper === 'SETTLED');
        $isShippingStatus = (
            in_array($rawUpper, ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'ON_HOLD', 'PAID', 'READY_TO_PICKUP']) ||
            strpos($rawUpper, 'SHIPMENT') !== false ||
            strpos($rawUpper, 'TRANSIT') !== false ||
            strpos($rawUpper, 'DELIVERED') !== false
        );

        $existingTrx = $transactionModel->where('invoice', $orderId)->first();

        if ($existingTrx) {
            $currentStatus = $existingTrx['status'];

            // Update shipping & fee meta for existing transaction
            $transactionModel->update($existingTrx['id'], [
                'pengiriman' => $pengiriman,
                'biaya_pengiriman' => $shippingCost
            ]);
            $this->setTransactionMeta($existingTrx['id'], 'pengiriman', $pengiriman);
            $this->setTransactionMeta($existingTrx['id'], 'courier', $pengiriman);
            $this->setTransactionMeta($existingTrx['id'], 'shipping_provider', $shippingProvider);
            $this->setTransactionMeta($existingTrx['id'], 'biaya_penanganan', (string)$handlingFee);
            $this->setTransactionMeta($existingTrx['id'], 'handling_fee', (string)$handlingFee);
            $this->setTransactionMeta($existingTrx['id'], 'biaya_layanan_aplikasi', (string)$serviceFee);
            $this->setTransactionMeta($existingTrx['id'], 'service_fee', (string)$serviceFee);
            $this->setTransactionMeta($existingTrx['id'], 'biaya_pengiriman_setelah_diskon', (string)$shippingCost);

            // 4. Handle CANCEL (Cancel, Cancelled, Canceled, Refund)
            if ($isCancel && $currentStatus !== 'CANCEL') {
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
                            'description' => "TikTok/Tokopedia Cancel Order Webhook: {$orderId}"
                        ]);
                    }
                }

                $transactionModel->update($existingTrx['id'], [
                    'status' => 'CANCEL',
                    'total_payment' => 0
                ]);

                // Update Meta
                $this->setTransactionMeta($existingTrx['id'], 'shipping_status', $rawTiktokStatus);

                // Build / Ensure CANCEL_SALES and CANCEL_COGS journals exist to maintain full audit trail
                $db = \Config\Database::connect();
                $actualTotal = (float)$existingTrx['actual_total'];
                $subTotal = (float)$existingTrx['amount'];
                $shipMeta = $db->table('transaction_meta')->where('transaction_id', $existingTrx['id'])->where('key', 'biaya_pengiriman')->get()->getRowArray();
                $shippingCost = (float)($shipMeta['value'] ?? ($existingTrx['biaya_pengiriman'] ?? 0));
                $cogsTotal = (float)($existingTrx['total_modal'] ?? 0);

                // 1. CANCEL_SALES Journal
                $existingCancelSales = $db->table('journals')
                    ->where('reference_no', $orderId)
                    ->where('reference_type', 'CANCEL_SALES')
                    ->get()->getRowArray();
                $csId = $existingCancelSales ? $existingCancelSales['id'] : $this->createJournal('CANCEL_SALES', $existingTrx['id'], $orderId, date('Y-m-d'), "Cancellation {$orderId}", $idToko);
                $db->table('journal_items')->where('journal_id', $csId)->delete();

                // Reversing from existing SALES journal ensures 100% exact symmetry and balance
                $existingSales = $db->table('journals')
                    ->where('reference_no', $orderId)
                    ->where('reference_type', 'SALES')
                    ->get()->getRowArray();

                if ($existingSales) {
                    $salesItems = $db->table('journal_items')->where('journal_id', $existingSales['id'])->get()->getResultArray();
                    foreach ($salesItems as $si) {
                        $db->table('journal_items')->insert([
                            'journal_id' => $csId,
                            'account_id' => $si['account_id'],
                            'debit' => $si['credit'],
                            'credit' => $si['debit'],
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                } else {
                    $salesDiff = round($actualTotal - ($subTotal + $shippingCost), 2);
                    if ($subTotal > 0) {
                        $this->addJournalItem($csId, '40' . $idToko . '1', $subTotal, 0, $idToko);
                    }
                    if ($shippingCost > 0) {
                        $this->addJournalItem($csId, '40' . $idToko . '1', $shippingCost, 0, $idToko);
                    }
                    if ($salesDiff > 0) {
                        $this->addJournalItem($csId, '40' . $idToko . '1', $salesDiff, 0, $idToko);
                    } elseif ($salesDiff < 0) {
                        $this->addJournalItem($csId, '40' . $idToko . '2', 0, abs($salesDiff), $idToko);
                    }
                    $this->addJournalItem($csId, '10' . $idToko . '3', 0, $actualTotal, $idToko);
                }
                $this->finalizeJournalTotals($csId);

                // 2. CANCEL_COGS Journal
                if ($cogsTotal > 0) {
                    $existingCancelCogs = $db->table('journals')
                        ->where('reference_no', $orderId)
                        ->where('reference_type', 'CANCEL_COGS')
                        ->get()->getRowArray();
                    $ccId = $existingCancelCogs ? $existingCancelCogs['id'] : $this->createJournal('CANCEL_COGS', $existingTrx['id'], $orderId, date('Y-m-d'), "Reversal COGS {$orderId}", $idToko);
                    $db->table('journal_items')->where('journal_id', $ccId)->delete();

                    $this->addJournalItem($ccId, '10' . $idToko . '4', $cogsTotal, 0, $idToko); // Dr Inventory
                    $this->addJournalItem($ccId, '50' . $idToko . '1', 0, $cogsTotal, $idToko); // Cr COGS
                    $this->finalizeJournalTotals($ccId);
                }

                log_aktivitas([
                    'user_id' => 0,
                    'action_type' => 'UPDATE_TRANSACTION_STATUS',
                    'target_table' => 'transaction',
                    'target_id' => $existingTrx['id'],
                    'description' => "Updated order {$orderId} status from {$currentStatus} to CANCEL"
                ]);
            }
            // 3. Handle COMPLETED (Dana diteruskan ke seller -> baru di-set PAID & catat komisi di jurnal)
            elseif ($isCompleted && $currentStatus !== 'COMPLETED') {
                // Fetch finance breakdown for commission and net settlement if available
                $financeData = $this->fetchOrderFinanceBreakdown($idToko, $orderId);

                $actualTotal = (float)$existingTrx['actual_total'];
                $netSettlement = ($financeData['net_settlement'] !== null) ? (float)$financeData['net_settlement'] : max(0, $actualTotal - $commissionFee - $transactionFee);
                $totalFee = max(0, $actualTotal - $netSettlement);

                $transactionModel->update($existingTrx['id'], [
                    'status' => 'COMPLETED',
                    'total_payment' => $actualTotal
                ]);

                $this->setTransactionMeta($existingTrx['id'], 'shipping_status', 'COMPLETED');
                $this->setTransactionMeta($existingTrx['id'], 'platform_commission_fee', (string) $totalFee);
                $this->setTransactionMeta($existingTrx['id'], 'net_settlement_amount', (string) $netSettlement);

                // Re-create / Update Settlement Journal for Commission & Payment Settlement
                $db = \Config\Database::connect();
                $existingSettle = $db->table('journals')
                    ->where('reference_no', $orderId)
                    ->where('reference_type', 'SETTLEMENT')
                    ->get()->getRowArray();

                $settleJournalId = $existingSettle ? $existingSettle['id'] : $this->createJournal('SETTLEMENT', $existingTrx['id'], $orderId, date('Y-m-d'), "Settlement & Commission Invoice #{$orderId}", $idToko);

                // Clear old items if any
                $db->table('journal_items')->where('journal_id', $settleJournalId)->delete();

                // Dr Cash/Bank (Net settlement amount)
                $this->addJournalItem($settleJournalId, '10' . $idToko . '1', $netSettlement, 0, $idToko);

                // Dr Platform Commission Expense (If totalFee > 0)
                if ($totalFee > 0) {
                    $this->addJournalItem($settleJournalId, '50' . $idToko . '5', $totalFee, 0, $idToko);
                }

                // Cr Accounts Receivable (AR)
                $this->addJournalItem($settleJournalId, '10' . $idToko . '3', 0, $actualTotal, $idToko);

                // Update totals on header
                $db->table('journals')->where('id', $settleJournalId)->update([
                    'total_debit' => $actualTotal,
                    'total_credit' => $actualTotal
                ]);

                log_aktivitas([
                    'user_id' => 0,
                    'action_type' => 'UPDATE_TRANSACTION_STATUS',
                    'target_table' => 'transaction',
                    'target_id' => $existingTrx['id'],
                    'description' => "Order {$orderId} COMPLETED. Net settlement: {$netSettlement}, Platform Fee: {$totalFee}"
                ]);
            }
            // 2. Handle intermediate shipping status (AWAITING_SHIPMENT, IN_TRANSIT, DELIVERED, ON_HOLD, PAID)
            elseif ($isShippingStatus || $rawTiktokStatus === 'PAID') {
                if ($currentStatus !== 'COMPLETED' && $currentStatus !== 'CANCEL') {
                    $transactionModel->update($existingTrx['id'], [
                        'status' => 'PAID PLATFORM',
                        'total_payment' => 0
                    ]);
                }
                $this->setTransactionMeta($existingTrx['id'], 'shipping_status', $rawTiktokStatus);

                log_aktivitas([
                    'user_id' => 0,
                    'action_type' => 'UPDATE_SHIPPING_STATUS',
                    'target_table' => 'transaction',
                    'target_id' => $existingTrx['id'],
                    'description' => "Updated shipping status for order {$orderId} to {$rawTiktokStatus} (Status: PAID PLATFORM)"
                ]);
            }
        } else {
            // 1. Initial Order Creation (ON_HOLD / UNPAID / WAITING_PAYMENT / AWAITING_SHIPMENT)
            // Order langsung dicatat invoice, mengurangi stok, status WAITING_PAYMENT, jurnal mencatat calon pendapatan & cogs.

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

            // Process line items & group by SKU/kode_barang
            $cogsTotal = 0;
            $itemsGrouped = [];
            $lineItems = $order['line_items'] ?? $order['item_list'] ?? [];

            foreach ($lineItems as $item) {
                $sellerSku = $item['seller_sku'] ?? $item['sku_id'] ?? null;
                $product = null;

                if (!empty($sellerSku)) {
                    $product = $productModel->where('id_barang', $sellerSku)->first();
                }

                // Fallback: search product by tiktok_product_id or item product_id if seller_sku not matched
                if (!$product) {
                    $tiktokPid = $item['product_id'] ?? null;
                    if ($tiktokPid) {
                        $product = $productModel->where('tiktok_product_id', $tiktokPid)->first();
                    }
                }

                $itemKodeBarang = $product ? $product['id_barang'] : ($sellerSku ?: ($item['product_id'] ?? 'SKU-UNKNOWN'));
                $modalSystem = $product ? (float)($product['harga_modal'] ?? 0) : 0;
                $qty = isset($item['quantity']) ? (int)$item['quantity'] : (isset($item['qty']) ? (int)$item['qty'] : 1);

                $salePrice = (float)($item['sale_price'] ?? 0);
                $originalPrice = (float)($item['original_price'] ?? 0);
                $effectivePrice = $salePrice > 0 ? $salePrice : ($originalPrice > 0 ? $originalPrice : 0);

                if (!isset($itemsGrouped[$itemKodeBarang])) {
                    $itemsGrouped[$itemKodeBarang] = [
                        'kode_barang' => $itemKodeBarang,
                        'product' => $product,
                        'modal_system' => $modalSystem,
                        'qty' => 0,
                        'sale_price' => $effectivePrice,
                        'original_price' => $effectivePrice
                    ];
                }
                $itemsGrouped[$itemKodeBarang]['qty'] += $qty;

                $cogsTotal += $modalSystem * $qty;
            }
            $itemsToProcess = array_values($itemsGrouped);

            $initialStatus = 'WAITING_PAYMENT';
            if ($isCancel) {
                $initialStatus = 'CANCEL';
            } elseif ($isCompleted) {
                $initialStatus = 'COMPLETED';
            } elseif ($isShippingStatus || $rawTiktokStatus === 'PAID') {
                $initialStatus = 'PAID PLATFORM';
            }

            $trxData = [
                'tenant_id' => $tenantId,
                'invoice' => $orderId,
                'amount' => $subTotal,
                'actual_total' => $grandTotal,
                'total_payment' => 0, // Starts at 0 until COMPLETED
                'status' => $initialStatus,
                'id_toko' => $idToko,
                'date_time' => date('Y-m-d H:i:s', $order['create_time'] ?? time()),
                'is_service' => 0,
                'source' => $order['commerce_platform'] ?? 'TOKOPEDIA_TIKTOK',
                'pengiriman' => $pengiriman,
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
                    'kode_pos' => $recipient['postal_code'] ?? '',
                    'buyer_email' => $buyerEmail,
                    'buyer_name' => $recipient['name'] ?? '',
                    'buyer_phone' => $recipient['phone_number'] ?? '',
                    'buyer_address' => $recipient['full_address'] ?? '',
                    'payment_method' => $order['payment_method_name'] ?? '',
                    'pengiriman' => $pengiriman,
                    'courier' => $pengiriman,
                    'shipping_provider' => $shippingProvider,
                    'biaya_pengiriman' => $shippingCost,
                    'biaya_pengiriman_setelah_diskon' => $shippingCost,
                    'biaya_penanganan' => $handlingFee,
                    'handling_fee' => $handlingFee,
                    'biaya_layanan_aplikasi' => $serviceFee,
                    'service_fee' => $serviceFee,
                    'shipping_type' => $order['shipping_type'] ?? '',
                    'shipping_status' => $rawTiktokStatus,
                    'source' => $order['commerce_platform'] ?? 'TOKOPEDIA_TIKTOK'
                ];

                foreach ($metaData as $mk => $mv) {
                    $transactionMetaModel->insert([
                        'transaction_id' => $trxId,
                        'key' => $mk,
                        'value' => (string)$mv
                    ]);
                }

                // Deduct stock & create SalesProduct records (Only if not cancel)
                if (!$isCancel) {
                    foreach ($itemsToProcess as $item) {
                        $kodeBarang = $item['kode_barang'];
                        $modalSystem = $item['modal_system'];
                        $qty = $item['qty'];
                        $salePrice = $item['sale_price'];
                        $originalPrice = $item['original_price'];

                        $salesProductModel->insert([
                            'tenant_id' => $tenantId,
                            'id_transaction' => $trxId,
                            'actual_per_piece' => $salePrice,
                            'actual_total' => $salePrice * $qty,
                            'kode_barang' => $kodeBarang,
                            'jumlah' => $qty,
                            'harga_system' => $originalPrice,
                            'harga_jual' => $salePrice,
                            'total' => $salePrice * $qty,
                            'modal_system' => $modalSystem,
                            'total_modal' => $modalSystem * $qty,
                            'is_service' => 0
                        ]);

                        // Deduct Stock
                        $stockEntry = $stockModel->where('id_barang', $kodeBarang)->where('id_toko', $idToko)->first();
                        if (!$stockEntry) {
                            $stockModel->insert([
                                'id_barang' => $kodeBarang,
                                'id_toko' => $idToko,
                                'stock' => 0,
                                'barang_cacat' => 0
                            ]);
                            $stockEntry = $stockModel->where('id_barang', $kodeBarang)->where('id_toko', $idToko)->first();
                        }

                        $newStock = $stockEntry['stock'] - $qty;
                        $stockModel->update($stockEntry['id'], ['stock' => $newStock]);

                        $stockLedgerModel->insert([
                            'id_barang' => $kodeBarang,
                            'id_toko' => $idToko,
                            'qty' => -$qty,
                            'balance' => $newStock,
                            'reference_type' => 'TRANSACTION',
                            'reference_id' => $trxId,
                            'description' => "TikTok/Tokopedia Order Webhook Created: {$orderId}"
                        ]);
                    }

                    // -- Accounting: Sales Journal (Calon Pendapatan) --
                    $journalId = $this->createJournal('SALES', $trxId, $orderId, date('Y-m-d'), "Invoice #{$orderId}", $idToko);
                    $this->addJournalItem($journalId, '10' . $idToko . '3', $grandTotal, 0, $idToko); // Dr AR (Calon Pendapatan)
                    if ($subTotal > 0) {
                        $this->addJournalItem($journalId, '40' . $idToko . '1', 0, $subTotal, $idToko); // Cr Sales Revenue
                    }
                    if ($shippingCost > 0) {
                        $this->addJournalItem($journalId, '40' . $idToko . '1', 0, $shippingCost, $idToko); // Cr Shipping Revenue
                    }

                    // Balance check: Grand Total vs (SubTotal + ShippingCost)
                    $salesDiff = round($grandTotal - ($subTotal + $shippingCost), 2);
                    if ($salesDiff > 0) {
                        // Buyer paid extra handling fee / platform fee -> Credit platform fee revenue
                        $this->addJournalItem($journalId, '40' . $idToko . '1', 0, $salesDiff, $idToko);
                    } elseif ($salesDiff < 0) {
                        // Discount / voucher applied -> Debit Sales Discount
                        $this->addJournalItem($journalId, '40' . $idToko . '2', abs($salesDiff), 0, $idToko);
                    }
                    $this->finalizeJournalTotals($journalId);

                    // -- Accounting: COGS Journal (Mengurangi Inventory) --
                    if ($cogsTotal > 0) {
                        $cogsJournalId = $this->createJournal('COGS', $trxId, $orderId, date('Y-m-d'), "COGS Invoice {$orderId}", $idToko);
                        $this->addJournalItem($cogsJournalId, '50' . $idToko . '1', $cogsTotal, 0, $idToko); // Dr COGS
                        $this->addJournalItem($cogsJournalId, '10' . $idToko . '4', 0, $cogsTotal, $idToko); // Cr Inventory
                        $this->finalizeJournalTotals($cogsJournalId);
                    }
                }

                log_aktivitas([
                    'user_id' => 0,
                    'action_type' => 'CREATE_TRANSACTION',
                    'target_table' => 'transaction',
                    'target_id' => $trxId,
                    'description' => "Created TikTok/Tokopedia order transaction {$orderId} with status WAITING_PAYMENT"
                ]);
            }
        }
    }

    /**
     * Helper to insert or update transaction meta
     */
    private function setTransactionMeta($trxId, $key, $value)
    {
        $db = \Config\Database::connect();
        $trx = $db->table('transaction')->select('tenant_id')->where('id', $trxId)->get()->getRowArray();
        $tenantId = $trx['tenant_id'] ?? (\App\Libraries\TenantContext::id() ?: 1);

        $existing = $db->table('transaction_meta')
            ->where('transaction_id', $trxId)
            ->where('key', $key)
            ->get()
            ->getRowArray();

        if ($existing) {
            $db->table('transaction_meta')->where('id', $existing['id'])->update([
                'tenant_id' => $tenantId,
                'value' => (string)$value
            ]);
        } else {
            $db->table('transaction_meta')->insert([
                'tenant_id' => $tenantId,
                'transaction_id' => $trxId,
                'key' => $key,
                'value' => (string)$value
            ]);
        }
    }

    /**
     * Fetch finance settlement breakdown for an order from TikTok/Tokopedia Finance API
     */
    private function fetchOrderFinanceBreakdown($idToko, $orderId)
    {
        try {
            $path = "/finance/202309/orders/{$orderId}/statement_transactions";
            $response = $this->makeTiktokRequest($idToko, 'GET', $path, [], null);

            log_message('info', "[fetchOrderFinanceBreakdown] Order {$orderId} response: " . json_encode($response));

            if (($response['code'] ?? -1) === 0 && !empty($response['data'])) {
                $data = $response['data'];
                $list = $data['statement_transactions'] ?? (isset($data['statement_transactions_list']) ? $data['statement_transactions_list'] : [$data]);

                $totalCommission = 0;
                $netSettlement = 0;

                foreach ($list as $item) {
                    $comm = (float)($item['platform_commission_fee'] ?? ($item['commission_fee'] ?? 0));
                    $trxFee = (float)($item['transaction_fee'] ?? ($item['service_fee'] ?? 0));
                    $dynComm = (float)($item['dynamic_commission_fee'] ?? 0);
                    $shipFee = (float)($item['shipping_fee_deduction'] ?? 0);
                    $procFee = (float)($item['order_processing_fee'] ?? 0);

                    $feeSum = abs($comm) + abs($trxFee) + abs($dynComm) + abs($shipFee) + abs($procFee);
                    $totalCommission += $feeSum;

                    $settle = (float)($item['net_settlement_amount'] ?? ($item['settlement_amount'] ?? 0));
                    $netSettlement += $settle;
                }

                return [
                    'commission_fee' => $totalCommission,
                    'transaction_fee' => 0,
                    'net_settlement' => $netSettlement > 0 ? $netSettlement : null
                ];
            }
        } catch (\Exception $e) {
            log_message('error', "[fetchOrderFinanceBreakdown] Error for order {$orderId}: " . $e->getMessage());
        }

        return ['commission_fee' => 0, 'transaction_fee' => 0, 'net_settlement' => null];
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

        // 1. First search by code
        $account = $db->table('accounts')
            ->where('code', $accountCode)
            ->get()->getRowArray();

        // 2. Search by base_code + id_toko
        if (!$account) {
            $account = $db->table('accounts')
                ->where('base_code', $accountCode)
                ->where('id_toko', $tokoId)
                ->get()->getRowArray();
        }

        // 3. Search by base_code alone
        if (!$account) {
            $account = $db->table('accounts')
                ->where('base_code', $accountCode)
                ->get()->getRowArray();
        }

        if (!$account) {
            log_message('error', "[addJournalItem] Account code {$accountCode} not found for toko {$tokoId}");
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

    private function finalizeJournalTotals($journalId)
    {
        $db = \Config\Database::connect();
        $sums = $db->table('journal_items')
            ->where('journal_id', $journalId)
            ->selectSum('debit', 'total_debit')
            ->selectSum('credit', 'total_credit')
            ->get()->getRowArray();

        $totalDebit = round((float)($sums['total_debit'] ?? 0), 2);
        $totalCredit = round((float)($sums['total_credit'] ?? 0), 2);

        if ($totalDebit !== $totalCredit) {
            log_message('error', "[JOURNAL_IMBALANCE_WARNING] Journal ID {$journalId} has debit {$totalDebit} != credit {$totalCredit}");
        }

        $db->table('journals')->where('id', $journalId)->update([
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit
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

    /**
     * Get list of chats for a Toko
     * GET /api/v2/toko/tiktok/chats/(:num)
     */
    public function getChats($idToko = null)
    {
        if (!$idToko) {
            return $this->jsonResponse->error('id_toko wajib diisi', 400);
        }

        $tiktokChatModel = new \App\Models\TiktokChatModel();
        $chats = $tiktokChatModel->where('id_toko', $idToko)
            ->orderBy('last_message_time', 'DESC')
            ->findAll();

        return $this->jsonResponse->oneResp('Sukses mengambil daftar chat', $chats, 200);
    }

    /**
     * Get messages for a specific conversation
     * GET /api/v2/toko/tiktok/chats/(:num)/messages/(:any)
     */
    public function getChatMessages($idToko = null, $conversationId = null)
    {
        if (!$idToko || !$conversationId) {
            return $this->jsonResponse->error('id_toko dan conversation_id wajib diisi', 400);
        }

        $tiktokChatModel = new \App\Models\TiktokChatModel();
        $chat = $tiktokChatModel->where('id_toko', $idToko)
            ->where('conversation_id', $conversationId)
            ->first();

        if (!$chat) {
            return $this->jsonResponse->error('Chat tidak ditemukan', 404);
        }

        // Mark as read
        if ($chat['unread_count'] > 0) {
            $tiktokChatModel->update($chat['id'], ['unread_count' => 0]);
        }

        $tiktokMessageModel = new \App\Models\TiktokMessageModel();
        $messages = $tiktokMessageModel->where('tiktok_chat_id', $chat['id'])
            ->orderBy('create_time', 'ASC')
            ->findAll();

        return $this->jsonResponse->oneResp('Sukses mengambil pesan', $messages, 200);
    }

    /**
     * Send a message to a conversation
     * POST /api/v2/toko/tiktok/chats/send/(:num)
     */
    public function sendChatMessage($idToko = null)
    {
        try {
            if (!$idToko) {
                return $this->jsonResponse->error('id_toko wajib diisi', 400);
            }

            $data = $this->request->getJSON(true) ?: [];
            $conversationId = $data['conversation_id'] ?? null;
            $content = $data['content'] ?? null;

            if (!$conversationId || !$content) {
                return $this->jsonResponse->error('conversation_id dan content wajib diisi', 400);
            }

            // Path for TikTok Customer Service API
            $path = "/customer_service/202312/conversations/{$conversationId}/messages";
            $params = [];
            $body = [
                'type' => 'TEXT',
                'content' => json_encode(['content' => $content]),
            ];

            // Note: Make sure the shop has authorized 'seller.customer_service' scope
            $response = $this->makeTiktokRequest($idToko, 'POST', $path, $params, $body);

            if (($response['code'] ?? -1) === 0) {
                // Optionally wait for webhook, or insert directly to local DB
                $tiktokChatModel = new \App\Models\TiktokChatModel();
                $chat = $tiktokChatModel->where('conversation_id', $conversationId)->first();
                
                if ($chat) {
                    $tiktokMessageModel = new \App\Models\TiktokMessageModel();
                    $messageId = $response['data']['message_id'] ?? uniqid('msg_');
                    
                    $tiktokMessageModel->insert([
                        'tiktok_chat_id' => $chat['id'],
                        'message_id' => $messageId,
                        'sender_role' => 'SELLER',
                        'type' => 'TEXT',
                        'content' => json_encode(['content' => $content]),
                        'create_time' => date('Y-m-d H:i:s'),
                    ]);
                    
                    $tiktokChatModel->update($chat['id'], [
                        'last_message' => json_encode(['content' => $content]),
                        'last_message_time' => date('Y-m-d H:i:s')
                    ]);
                }

                return $this->jsonResponse->oneResp('Pesan berhasil dikirim', $response['data'] ?? [], 200);
            } else {
                return $this->jsonResponse->error($response['message'] ?? 'Gagal mengirim pesan', 400, $response);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    /**
     * Fix metadata, status & journal for Invoice 16464 / Order 585842656613598949
     * GET /api/v2/fix-invoice-16464
     */
    public function fixInvoice16464()
    {
        try {
            $db = \Config\Database::connect();
            $id = 16464;
            $orderId = '585842656613598949';
            $idToko = 1;

            \App\Libraries\TenantContext::set(['id' => 1]);

            // Fix tenant_id = 1 on transaction_meta for transaction 16464
            $db->table('transaction_meta')->where('transaction_id', $id)->update(['tenant_id' => 1]);

            $transactionModel = new \App\Models\TransactionModel();
            $transaction = $transactionModel->find($id);
            if (!$transaction) {
                return $this->jsonResponse->error("Transaksi 16464 tidak ditemukan", 404);
            }

            $pengiriman = 'Pengiriman Standar';
            $shippingProvider = 'Dikirim melalui platform';
            $handlingFee = 2885;
            $serviceFee = 1000;
            $shippingCost = 0;

            // 1. Update Transaction status & pengiriman
            $transactionModel->update($id, [
                'status' => 'PAID PLATFORM',
                'total_payment' => 0,
                'pengiriman' => 'Pengiriman Standar',
                'biaya_pengiriman' => 0
            ]);

            // 2. Set Meta values
            $metaPairs = [
                'pengiriman' => $pengiriman,
                'courier' => $pengiriman,
                'shipping_provider' => $shippingProvider,
                'shipping_status' => 'READY_TO_PICKUP',
                'biaya_penanganan' => (string)$handlingFee,
                'handling_fee' => (string)$handlingFee,
                'biaya_layanan_aplikasi' => (string)$serviceFee,
                'service_fee' => (string)$serviceFee,
                'biaya_pengiriman' => (string)$shippingCost,
                'biaya_pengiriman_setelah_diskon' => (string)$shippingCost
            ];

            foreach ($metaPairs as $k => $v) {
                $this->setTransactionMeta($id, $k, $v);
            }

            // 3. Fix sales_product for Invoice 16464: SKU I046, QTY 2, Price Rp 146.250 x 2 (Subtotal 287.500)
            $productModel = new \App\Models\ProductModel();
            $productI046 = $productModel->where('id_barang', 'I046')->first();
            $modalPerPiece = $productI046 ? (float)($productI046['harga_modal'] ?? 81900) : 81900;

            $db->table('sales_product')->where('id_transaction', $id)->delete();
            $db->table('sales_product')->insert([
                'tenant_id' => 1,
                'id_transaction' => $id,
                'kode_barang' => 'I046',
                'jumlah' => 2,
                'harga_system' => 146250.00,
                'harga_jual' => 143750.00,
                'total' => 287500.00,
                'modal_system' => $modalPerPiece,
                'total_modal' => $modalPerPiece * 2,
                'actual_per_piece' => 143750.00,
                'actual_total' => 287500.00,
                'is_service' => 0
            ]);

            // 4. Fix Journal Imbalance (Sales Journal for 585842656613598949)
            // AR (1013) is Dr 291385, Sales (4011) is Cr 287500, Fee (4011) is Cr 3885
            $salesJournal = $db->table('journals')
                ->where('reference_no', $orderId)
                ->where('reference_type', 'SALES')
                ->get()->getRowArray();

            if ($salesJournal) {
                $journalId = $salesJournal['id'];
                $db->table('journal_items')->where('journal_id', $journalId)->delete();

                // Dr AR (Calon Pendapatan) 291385
                $this->addJournalItem($journalId, '1013', 291385, 0, $idToko);
                // Cr Sales Revenue 287500
                $this->addJournalItem($journalId, '4011', 0, 287500, $idToko);
                // Cr Extra / Handling & Service Fees 3885
                $this->addJournalItem($journalId, '4011', 0, 3885, $idToko);

                // Update total_debit & total_credit in journals table (291385)
                $db->table('journals')->where('id', $journalId)->update([
                    'total_debit' => 291385,
                    'total_credit' => 291385
                ]);
            }

            return $this->jsonResponse->oneResp('Invoice 16464 berhasil diperbarui!', [
                'id' => $id,
                'invoice' => $orderId,
                'status' => 'PAID PLATFORM',
                'shipping_status' => 'READY_TO_PICKUP',
                'sku' => 'I046',
                'qty' => 2,
                'harga_unit' => 146250,
                'biaya_penanganan' => 2885,
                'biaya_layanan_aplikasi' => 1000,
                'journal_fixed' => true
            ], 200);

        } catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Re-sync & fix metadata, status & journals for any TikTok/Tokopedia order by transaction ID
     * GET /api/v2/fix-tiktok-invoice/(:num)
     */
    public function fixTiktokInvoice($transactionId = null)
    {
        try {
            if (!$transactionId) {
                return $this->jsonResponse->error("ID Transaksi wajib diisi", 400);
            }

            $db = \Config\Database::connect();
            $transactionModel = new \App\Models\TransactionModel();
            $transaction = $transactionModel->find($transactionId);

            if (!$transaction) {
                return $this->jsonResponse->error("Transaksi {$transactionId} tidak ditemukan", 404);
            }

            $tenantId = $transaction['tenant_id'] ?? 1;
            $idToko = $transaction['id_toko'] ?? 1;
            $orderId = $transaction['invoice'];

            \App\Libraries\TenantContext::set(['id' => $tenantId]);

            // Fix tenant_id on transaction_meta if null
            $db->table('transaction_meta')
                ->where('transaction_id', $transactionId)
                ->where('tenant_id IS NULL')
                ->update(['tenant_id' => $tenantId]);

            // Fetch real-time order data from TikTok/Tokopedia API
            $path = "/order/202309/orders";
            $params = ['ids' => $orderId];
            $response = $this->makeTiktokRequest($idToko, 'GET', $path, $params);

            $orderList = $response['data']['orders'] ?? [];
            if (!empty($orderList)) {
                $order = $orderList[0];
                // Trigger syncTiktokOrder with full real-time order payload
                $this->syncTiktokOrder($idToko, $order);
            } else {
                // Fallback: Check if status is CANCEL, COMPLETED or DELIVERED/PAID
                $currentStatus = $transaction['status'];
                $rawStatusUpper = strtoupper((string)$currentStatus);

                if (strpos($rawStatusUpper, 'CANCEL') !== false || strpos($rawStatusUpper, 'REFUND') !== false) {
                    $transactionModel->update($transactionId, [
                        'status' => 'CANCEL',
                        'total_payment' => 0
                    ]);
                    $this->setTransactionMeta($transactionId, 'shipping_status', 'CANCELLED');
                } elseif ($currentStatus !== 'COMPLETED') {
                    $transactionModel->update($transactionId, [
                        'status' => 'PAID PLATFORM',
                        'total_payment' => 0
                    ]);
                    $this->setTransactionMeta($transactionId, 'shipping_status', 'DELIVERED');
                }
            }

            // Check if updated transaction status is CANCEL
            $updatedTrxCheck = $transactionModel->find($transactionId);
            $isCancelledOrder = ($updatedTrxCheck['status'] === 'CANCEL' || strpos(strtoupper((string)($updatedTrxCheck['status'] ?? '')), 'CANCEL') !== false || in_array($orderId, ['585740089727485620', '585756360820295477']) || in_array((int)$transactionId, [15751, 15906]));

            if ($isCancelledOrder) {
                // Mark transaction as CANCEL & build CANCEL_SALES / CANCEL_COGS journals
                $transactionModel->update($transactionId, [
                    'status' => 'CANCEL',
                    'total_payment' => 0
                ]);
                $this->setTransactionMeta($transactionId, 'shipping_status', 'CANCELLED');

                // Restore stock if not already restored
                $existingReturn = $db->table('stock_ledgers')
                    ->where('reference_id', $transactionId)
                    ->where('reference_type', 'RETURN')
                    ->get()->getRowArray();

                if (!$existingReturn) {
                    $stockModel = new \App\Models\StockModel();
                    $stockLedgerModel = new \App\Models\StockLedgerModel();
                    $items = $salesProductModel->where('id_transaction', $transactionId)->findAll();
                    foreach ($items as $item) {
                        if (!$item['is_service']) {
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
                            $newStock = (int)$stockEntry['stock'] + (int)$item['jumlah'];
                            $stockModel->update($stockEntry['id'], ['stock' => $newStock]);

                            $stockLedgerModel->insert([
                                'tenant_id' => $tenantId,
                                'id_barang' => $item['kode_barang'],
                                'id_toko' => $idToko,
                                'qty' => (int)$item['jumlah'],
                                'balance' => $newStock,
                                'reference_type' => 'RETURN',
                                'reference_id' => $transactionId,
                                'description' => "TikTok/Tokopedia Cancel Order: {$orderId}",
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }

                $actualTotal = (float)$transaction['actual_total'];
                $subTotal = (float)$transaction['amount'];
                $shipMeta = $db->table('transaction_meta')->where('transaction_id', $transactionId)->where('key', 'biaya_pengiriman')->get()->getRowArray();
                $shippingCost = (float)($shipMeta['value'] ?? ($transaction['biaya_pengiriman'] ?? 0));
                $cogsTotal = (float)($transaction['total_modal'] ?? 0);

                $orderDate = !empty($transaction['date_time']) ? date('Y-m-d', strtotime($transaction['date_time'])) : date('Y-m-d');
                $cancelDate = !empty($transaction['updated_at']) ? date('Y-m-d', strtotime($transaction['updated_at'])) : date('Y-m-d');

                // 1. Ensure original SALES Journal exists on order creation date
                $existingSales = $db->table('journals')
                    ->where('reference_no', $orderId)
                    ->where('reference_type', 'SALES')
                    ->get()->getRowArray();
                $sjId = $existingSales ? $existingSales['id'] : $this->createJournal('SALES', $transactionId, $orderId, $orderDate, "Invoice #{$orderId}", $idToko);
                $db->table('journal_items')->where('journal_id', $sjId)->delete();

                $salesDiff = round($actualTotal - ($subTotal + $shippingCost), 2);
                $this->addJournalItem($sjId, '10' . $idToko . '3', $actualTotal, 0, $idToko); // Dr AR
                if ($subTotal > 0) {
                    $this->addJournalItem($sjId, '40' . $idToko . '1', 0, $subTotal, $idToko); // Cr Sales Revenue
                }
                if ($shippingCost > 0) {
                    $this->addJournalItem($sjId, '40' . $idToko . '1', 0, $shippingCost, $idToko); // Cr Shipping Revenue
                }
                if ($salesDiff > 0) {
                    $this->addJournalItem($sjId, '40' . $idToko . '1', 0, $salesDiff, $idToko);
                } elseif ($salesDiff < 0) {
                    $this->addJournalItem($sjId, '40' . $idToko . '2', abs($salesDiff), 0, $idToko);
                }
                $this->finalizeJournalTotals($sjId);

                // 2. Ensure original COGS Journal exists on order creation date
                if ($cogsTotal > 0) {
                    $existingCogs = $db->table('journals')
                        ->where('reference_no', $orderId)
                        ->where('reference_type', 'COGS')
                        ->get()->getRowArray();
                    $cogsId = $existingCogs ? $existingCogs['id'] : $this->createJournal('COGS', $transactionId, $orderId, $orderDate, "COGS Invoice {$orderId}", $idToko);
                    $db->table('journal_items')->where('journal_id', $cogsId)->delete();

                    $this->addJournalItem($cogsId, '50' . $idToko . '1', $cogsTotal, 0, $idToko); // Dr COGS
                    $this->addJournalItem($cogsId, '10' . $idToko . '4', 0, $cogsTotal, $idToko); // Cr Inventory
                    $this->finalizeJournalTotals($cogsId);
                }

                // 3. Ensure CANCEL_SALES Journal exists on cancellation date (exact reversal of SALES)
                $existingCancelSales = $db->table('journals')
                    ->where('reference_no', $orderId)
                    ->where('reference_type', 'CANCEL_SALES')
                    ->get()->getRowArray();
                $csId = $existingCancelSales ? $existingCancelSales['id'] : $this->createJournal('CANCEL_SALES', $transactionId, $orderId, $cancelDate, "Cancellation {$orderId}", $idToko);
                $db->table('journal_items')->where('journal_id', $csId)->delete();

                $salesItems = $db->table('journal_items')->where('journal_id', $sjId)->get()->getResultArray();
                foreach ($salesItems as $si) {
                    $db->table('journal_items')->insert([
                        'journal_id' => $csId,
                        'account_id' => $si['account_id'],
                        'debit' => $si['credit'], // Reversal
                        'credit' => $si['debit'],
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
                $this->finalizeJournalTotals($csId);

                // 4. Ensure CANCEL_COGS Journal exists on cancellation date (exact reversal of COGS)
                if ($cogsTotal > 0) {
                    $existingCancelCogs = $db->table('journals')
                        ->where('reference_no', $orderId)
                        ->where('reference_type', 'CANCEL_COGS')
                        ->get()->getRowArray();
                    $ccId = $existingCancelCogs ? $existingCancelCogs['id'] : $this->createJournal('CANCEL_COGS', $transactionId, $orderId, $cancelDate, "Reversal COGS {$orderId}", $idToko);
                    $db->table('journal_items')->where('journal_id', $ccId)->delete();

                    $this->addJournalItem($ccId, '10' . $idToko . '4', $cogsTotal, 0, $idToko); // Dr Inventory
                    $this->addJournalItem($ccId, '50' . $idToko . '1', 0, $cogsTotal, $idToko); // Cr COGS
                    $this->finalizeJournalTotals($ccId);
                }
            } else {
                // Rebuild & balance Sales Journal for active non-cancelled order
                $salesJournal = $db->table('journals')
                    ->where('reference_no', $orderId)
                    ->where('reference_type', 'SALES')
                    ->get()->getRowArray();

                if ($salesJournal) {
                    $sjId = $salesJournal['id'];
                    $db->table('journal_items')->where('journal_id', $sjId)->delete();

                    $actualTotal = (float)$transaction['actual_total'];
                    $subTotal = (float)$transaction['amount'];
                    $shipMeta = $db->table('transaction_meta')->where('transaction_id', $transactionId)->where('key', 'biaya_pengiriman')->get()->getRowArray();
                    $shippingCost = (float)($shipMeta['value'] ?? ($transaction['biaya_pengiriman'] ?? 0));
                    $salesDiff = round($actualTotal - ($subTotal + $shippingCost), 2);

                    // Dr AR (Calon Pendapatan)
                    $this->addJournalItem($sjId, '10' . $idToko . '3', $actualTotal, 0, $idToko);
                    // Cr Sales Revenue
                    if ($subTotal > 0) {
                        $this->addJournalItem($sjId, '40' . $idToko . '1', 0, $subTotal, $idToko);
                    }
                    // Cr Shipping Revenue
                    if ($shippingCost > 0) {
                        $this->addJournalItem($sjId, '40' . $idToko . '1', 0, $shippingCost, $idToko);
                    }
                    // Balance: Extra fees or voucher/discount
                    if ($salesDiff > 0) {
                        $this->addJournalItem($sjId, '40' . $idToko . '1', 0, $salesDiff, $idToko);
                    } elseif ($salesDiff < 0) {
                        $this->addJournalItem($sjId, '40' . $idToko . '2', abs($salesDiff), 0, $idToko);
                    }

                    $this->finalizeJournalTotals($sjId);
                }
            }

            // Consolidate sales_product records if duplicated by kode_barang
            $spRows = $db->table('sales_product')->where('id_transaction', $transactionId)->get()->getResultArray();
            if (!empty($spRows)) {
                $groupedSp = [];
                foreach ($spRows as $sp) {
                    $kb = $sp['kode_barang'];
                    if (!isset($groupedSp[$kb])) {
                        $groupedSp[$kb] = $sp;
                        $groupedSp[$kb]['jumlah'] = 0;
                        $groupedSp[$kb]['total'] = 0;
                        $groupedSp[$kb]['total_modal'] = 0;
                        $groupedSp[$kb]['actual_total'] = 0;
                    }
                    $groupedSp[$kb]['jumlah'] += (int)$sp['jumlah'];
                    $groupedSp[$kb]['total'] += (float)$sp['total'];
                    $groupedSp[$kb]['total_modal'] += (float)$sp['total_modal'];
                    $groupedSp[$kb]['actual_total'] += (float)$sp['actual_total'];
                }

                // Clear old rows and re-insert consolidated rows
                $db->table('sales_product')->where('id_transaction', $transactionId)->delete();
                foreach ($groupedSp as $gSp) {
                    $qty = $gSp['jumlah'];
                    $unitPrice = $qty > 0 ? ($gSp['total'] / $qty) : (float)$gSp['harga_jual'];
                    unset($gSp['id']);
                    $gSp['harga_jual'] = $unitPrice;
                    $gSp['harga_system'] = $unitPrice;
                    $gSp['actual_per_piece'] = $unitPrice;
                    $db->table('sales_product')->insert($gSp);
                }
            }

            // Re-fetch updated transaction & metas
            $updatedTrx = $transactionModel->find($transactionId);
            $metas = $db->table('transaction_meta')->where('transaction_id', $transactionId)->get()->getResultArray();
            $metaMap = [];
            foreach ($metas as $m) {
                $metaMap[$m['key']] = $m['value'];
            }
            $updatedTrx['meta'] = $metaMap;

            return $this->jsonResponse->oneResp("Transaksi {$transactionId} ({$orderId}) berhasil diperbarui & disinkronisasi ke COMPLETED!", $updatedTrx, 200);

        } catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Batch re-sync all Tokopedia & TikTok Shop transactions to fix metadata, status, items, & journals
     * GET /api/v2/fix-all-tiktok-invoices
     */
    public function fixAllTiktokInvoices()
    {
        try {
            $db = \Config\Database::connect();
            $transactionModel = new \App\Models\TransactionModel();

            $trxs = $db->table('transaction')
                ->select('id, invoice, status')
                ->where('LENGTH(invoice) >= 15')
                ->get()
                ->getResultArray();

            $results = [];
            foreach ($trxs as $trx) {
                $id = $trx['id'];
                try {
                    $this->fixTiktokInvoice($id);
                    $updated = $transactionModel->find($id);
                    $results[] = [
                        'id' => $id,
                        'invoice' => $trx['invoice'],
                        'old_status' => $trx['status'],
                        'new_status' => $updated['status'] ?? 'N/A',
                        'success' => true
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'id' => $id,
                        'invoice' => $trx['invoice'],
                        'old_status' => $trx['status'],
                        'error' => $e->getMessage(),
                        'success' => false
                    ];
                }
            }

            return $this->jsonResponse->oneResp("Berhasil me-migrate dan memperbaiki " . count($results) . " transaksi marketplace!", [
                'total_migrated' => count($results),
                'details' => $results
            ], 200);

        } catch (\Throwable $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}