<?php

namespace App\Controllers;

use App\Models\CustomerModel;
use App\Models\ModelBarangModel;
use App\Models\ProductModel;
use App\Models\ImageModel;
use App\Models\SalesProductModel;
use App\Models\StockModel;
use App\Models\TokoModel;
use App\Models\TransactionModel;
use App\Libraries\SubscriptionService;
use App\Libraries\TenantContext;
use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * @property \CodeIgniter\HTTP\IncomingRequest $request
 */
class ProductController extends ResourceController
{
    protected $modelBarangModel;
    protected $productModel;
    protected $stockModel;
    protected $imageModel;
    protected $jsonResponse;
    protected $db;
    protected $modelToko;
    protected $customer;
    protected $SalesProductModel;
    protected $transactions;
    protected $journalModel;
    protected $journalItemModel;
    protected $accountModel;
    protected $stockLedgerModel;

    public function __construct()
    {
        helper(['log', 'url']);
        $this->modelBarangModel = new ModelBarangModel();
        $this->imageModel = new ImageModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
        $this->modelToko = new TokoModel();
        $this->customer = new CustomerModel();
        $this->transactions = new TransactionModel();
        $this->SalesProductModel = new SalesProductModel();
        $this->journalModel = new \App\Models\JournalModel();
        $this->journalItemModel = new \App\Models\JournalItemModel();
        $this->accountModel = new \App\Models\AccountModel();
        $this->stockLedgerModel = new \App\Models\StockLedgerModel();
    }

    public function createProduct()
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();

        $tenantId = TenantContext::id();
        $subscriptionService = new SubscriptionService($this->db);
        $quotaCheck = $subscriptionService->canCreateProducts($tenantId, 1);
        if (!($quotaCheck['ok'] ?? false)) {
            return $this->jsonResponse->error($quotaCheck['message'] ?? 'Kuota product habis', $quotaCheck['code'] ?? 403);
        }

        $validation = \Config\Services::validation();
        $validation->setRules([
            'id_model' => 'required',
            'harga_modal' => 'required',
            'harga_jual' => 'required',
            'harga_jual_toko' => 'permit_empty',
            'id_seri_barang' => 'permit_empty',
            'suplier' => 'permit_empty',
            'description' => 'permit_empty',
            'dropship' => 'permit_empty',
            'berat' => 'permit_empty',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $model = $this->modelBarangModel->find($data->id_model);
        if (!$model) {
            return $this->jsonResponse->error("Kategori barang tidak valid", 400);
        }

        $kodeAwal = $model['kode_awal'];

        $lastProduct = $this->productModel->withDeleted()->orderBy('id', 'DESC')->first();
        $nextId = $lastProduct ? (int) $lastProduct['id'] + 1 : 1;
        $productId = $kodeAwal . str_pad($nextId, 3, '0', STR_PAD_LEFT);

        $productData = [
            'id_barang' => $productId,
            'nama_barang' => $data->nama_barang ?? "",
            'description' => $data->description ?? NULL,
            'id_seri_barang' => $data->id_seri_barang ?? null,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
            'harga_jual_toko' => $data->harga_jual_toko,
            'suplier' => $data->suplier ?? null,
            'id_model_barang' => $data->id_model,
            'notes' => $data->notes ?? null,
            'dropship' => $data->dropship ?? 0,
            'berat' => $data->berat ?? 0,
            "created_by" => $token['user_id'],
        ];

        $this->productModel->insert($productData);
        $subscriptionService->incrementProductUsed($tenantId, 1);
        log_aktivitas([
            'user_id' => $token['user_id'],
            'action_type' => 'CREATE',
            'target_table' => 'product',
            'target_id' => $nextId,
            'description' => 'Membuat produk baru',
            'detail' => [
                'new' => $data
            ],
        ]);

        $stockData = [];
        if (isset($data->stock) && is_array($data->stock)) {
            foreach ($data->stock as $toko) {
                if (isset($toko->id_toko) && $toko->id_toko !== "" && $toko->id_toko !== "0") {
                    $stockData[] = [
                        'id_barang' => $productId,
                        'id_toko' => $toko->id_toko,
                        'stock' => $toko->stock,
                        'barang_cacat' => $toko->barang_cacat,
                        'dropship' => isset($toko->dropship) ? (int) $toko->dropship : 0,
                    ];
                }
            }
        }

        if (!empty($stockData)) {
            $this->stockModel->insertBatch($stockData);
        }

        return $this->jsonResponse->oneResp('Add ' . ($data->nama_barang ?? 'product') . ' successfully', ['id' => $nextId], 201);
    }

    // V2: Create Product with 0 Stock (Inventory Managed via Purchase)
    public function createProductV2()
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();

        $tenantId = TenantContext::id();
        $subscriptionService = new SubscriptionService($this->db);
        $quotaCheck = $subscriptionService->canCreateProducts($tenantId, 1);
        if (!($quotaCheck['ok'] ?? false)) {
            return $this->jsonResponse->error($quotaCheck['message'] ?? 'Kuota product habis', $quotaCheck['code'] ?? 403);
        }

        $validation = \Config\Services::validation();
        $validation->setRules([
            'id_model' => 'required',
            'harga_modal' => 'required',
            'harga_jual' => 'required',
            'harga_jual_toko' => 'permit_empty',
            'id_seri_barang' => 'permit_empty',
            'suplier' => 'permit_empty',
            'description' => 'permit_empty',
            'dropship' => 'permit_empty',
            'berat' => 'permit_empty',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $model = $this->modelBarangModel->find($data->id_model);
        if (!$model) {
            return $this->jsonResponse->error("Kategori barang tidak valid", 400);
        }

        $kodeAwal = $model['kode_awal'];

        $lastProduct = $this->productModel->withDeleted()->orderBy('id', 'DESC')->first();
        $nextId = $lastProduct ? (int) $lastProduct['id'] + 1 : 1;
        $productId = $kodeAwal . str_pad($nextId, 3, '0', STR_PAD_LEFT);

        $productData = [
            'id_barang' => $productId,
            'nama_barang' => $data->nama_barang ?? "",
            'description' => $data->description ?? NULL,
            'id_seri_barang' => $data->id_seri_barang ?? null,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
            'harga_jual_toko' => $data->harga_jual_toko,
            'suplier' => $data->suplier ?? null,
            'id_model_barang' => $data->id_model,
            'notes' => $data->notes ?? null,
            'berat' => $data->berat ?? 0,
            'dropship' => $data->dropship ?? 0,
            "created_by" => $token['user_id'],
        ];

        $this->productModel->insert($productData);
        $subscriptionService->incrementProductUsed($tenantId, 1);
        log_aktivitas([
            'user_id' => $token['user_id'],
            'action_type' => 'CREATE_V2',
            'target_table' => 'product',
            'target_id' => $nextId,
            'description' => 'Membuat produk baru (V2)',
            'detail' => [
                'new' => $data
            ],
        ]);

        return $this->jsonResponse->oneResp('Add ' . ($data->nama_barang ?? 'product') . ' successfully', ['id' => $nextId, 'id_barang' => $productId], 201);
    }
    public function uploadImages()
    {
        $kode = $this->request->getPost('kode');
        $images = $this->request->getFiles();
        $imagePaths = $this->request->getPost('image');

        $uploadedImagePaths = [];
        $existingImages = $this->imageModel->where('type', 'product')
            ->where('kode', $kode)
            ->findAll();

        $existingImageUrls = array_column($existingImages, 'url');

        $uploadPath = ROOTPATH . 'public/hope/images';
        if (!is_dir($uploadPath)) {
            if (!mkdir($uploadPath, 0777, true)) {
                return $this->jsonResponse->error("Gagal membuat direktori upload: " . $uploadPath, 500);
            }
        }

        if (!is_writable($uploadPath)) {
            return $this->jsonResponse->error("Direktori upload tidak dapat ditulis: " . $uploadPath, 500);
        }

        if (!empty($images['image'])) {
            foreach ($images['image'] as $image) {
                if ($image->isValid()) {
                    $mimeType = $image->getMimeType();
                    $originalName = $image->getName();
                    $tempPath = $image->getTempName();
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                    if ($ext === 'webp') {
                        // File sudah webp, langsung pindahkan ke tujuan
                        $webpName = bin2hex(random_bytes(10)) . '.webp';
                        $finalPath = ROOTPATH . 'public/hope/images/' . $webpName;
                        $image->move(ROOTPATH . 'public/hope/images', $webpName);

                        $finalImagePath = base_url('hope/images/' . $webpName);
                        $uploadedImagePaths[] = $finalImagePath;

                        if (!in_array($finalImagePath, $existingImageUrls)) {
                            $this->imageModel->insert([
                                'type' => "product",
                                'kode' => $kode,
                                'url' => $finalImagePath,
                            ]);
                        }

                        continue;
                    }

                    // Convert ke WebP
                    switch ($mimeType) {
                        case 'image/jpeg':
                            $source = imagecreatefromjpeg($tempPath);
                            break;
                        case 'image/png':
                            $source = imagecreatefrompng($tempPath);
                            imagepalettetotruecolor($source);
                            imagealphablending($source, true);
                            imagesavealpha($source, true);
                            break;
                        case 'image/avif':
                            if (function_exists('imagecreatefromavif')) {
                                $source = imagecreatefromavif($tempPath);
                            } else {
                                return $this->jsonResponse->oneResp('AVIF not supported on this server', [], 400);
                            }
                            break;
                        default:
                            return $this->jsonResponse->oneResp('Unsupported image format', [], 400);
                    }

                    if (!$source) {
                        return $this->jsonResponse->oneResp('Failed to process image file', [], 400);
                    }

                    $webpName = bin2hex(random_bytes(10)) . '.webp';
                    $webpPath = ROOTPATH . 'public/hope/images/' . $webpName;

                    imagewebp($source, $webpPath, 80);
                    imagedestroy($source);

                    $finalImagePath = base_url('hope/images/' . $webpName);
                    $uploadedImagePaths[] = $finalImagePath;

                    if (!in_array($finalImagePath, $existingImageUrls)) {
                        $this->imageModel->insert([
                            'type' => "product",
                            'kode' => $kode,
                            'url' => $finalImagePath,
                        ]);
                    }
                } else {
                    return $this->jsonResponse->oneResp('Invalid image file', [], 400);
                }
            }
        }

        if (!empty($imagePaths)) {
            foreach ($imagePaths as $imagePath) {
                if (file_exists(ROOTPATH . 'public/' . $imagePath)) {
                    $uploadedImagePaths[] = $imagePath;
                    if (!in_array($imagePath, $existingImageUrls)) {
                        $this->imageModel->insert([
                            'type' => "product",
                            'kode' => $kode,
                            'url' => $imagePath,
                        ]);
                    }
                } else {
                    return $this->jsonResponse->oneResp('Invalid image path: ' . $imagePath, [], 400);
                }
            }
        }

        foreach ($existingImages as $existingImage) {
            if (!in_array($existingImage['url'], $uploadedImagePaths)) {
                $this->imageModel->delete($existingImage['id']);
            }
        }

        if (empty($uploadedImagePaths)) {
            return $this->jsonResponse->oneResp('No images or paths provided', [], 400);
        }

        return $this->jsonResponse->oneResp('Images uploaded successfully', ['image_paths' => $uploadedImagePaths], 201);
    }

    private function generateChangeLogString(array $oldData, object $newData): string
    {
        $changes = [];

        $fields = [
            'nama_barang',
            'description',
            'id_seri_barang',
            'harga_modal',
            'harga_jual',
            'harga_jual_toko',
            'suplier',
            'id_model',
            'notes'
        ];

        foreach ($fields as $field) {
            $oldValue = $oldData[$field] ?? null;
            if ($field === 'suplier' && is_array($oldValue)) {
                $oldValue = implode(',', $oldValue);
            }
            $newValue = $newData->$field ?? null;
            if ($field === 'suplier' && is_array($newValue)) {
                $newValue = implode(',', $newValue);
            }

            if ($oldValue != $newValue) {
                $oldValStr = $oldValue === null ? 'null' : (string) $oldValue;
                $newValStr = $newValue === null ? 'null' : (string) $newValue;
                $changes[] = "Data $field diubah dari '$oldValStr' menjadi '$newValStr'";
            }
        }

        $oldStockIndex = [];
        foreach ($oldData['stock'] ?? [] as $stock) {

            $key = $stock['id'] ?? $stock['id_toko'] ?? uniqid('stock_');
            $oldStockIndex[$key] = $stock;
        }


        foreach ($newData->stock ?? [] as $newStock) {
            $key = $newStock->id ?? $newStock->id_toko ?? uniqid('stock_');

            $oldStock = $oldStockIndex[$key] ?? null;
            if (!$oldStock) {

                $changes[] = "Stock baru ditambahkan untuk toko ID {$newStock->id_toko} dengan stock {$newStock->stock}, barang cacat {$newStock->barang_cacat}, dropship " . ($newStock->dropship ? 'true' : 'false');
            } else {

                foreach (['stock', 'barang_cacat', 'dropship'] as $stockField) {
                    $oldVal = $oldStock[$stockField] ?? null;
                    $newVal = $newStock->$stockField ?? null;


                    if ($stockField === 'dropship') {
                        $oldVal = (bool) $oldVal;
                        $newVal = (bool) $newVal;
                    }

                    if ($oldVal != $newVal) {
                        $changes[] = "Stock untuk toko ID {$newStock->id_toko} diubah pada '$stockField' dari '$oldVal' menjadi '$newVal'";
                    }
                }
            }


            unset($oldStockIndex[$key]);
        }


        if (empty($changes)) {
            return "Tidak ada perubahan data.";
        }

        return implode(", ", $changes) . ".";
    }

    public function updateProduct($id = null)
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'id_model' => 'required',
            'harga_modal' => 'required',
            'harga_jual' => 'required',
            'harga_jual_toko' => 'permit_empty',
            'id_seri_barang' => 'permit_empty',
            'suplier' => 'permit_empty',
            'description' => 'permit_empty',
            'dropship' => 'permit_empty',
            'berat' => 'permit_empty',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $model = $this->modelBarangModel->find($data->id_model);
        if (!$model) {
            return $this->jsonResponse->error("Kategori barang tidak valid", 400);
        }

        $oldProductData = $this->getProductDetailArray($id);
        if (!$oldProductData) {
            return $this->jsonResponse->error("Produk tidak ditemukan", 404);
        }

        $productData = [
            'nama_barang' => $data->nama_barang ?? "",
            'description' => $data->description ?? NULL,
            'id_seri_barang' => $data->id_seri_barang ?? null,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
            'harga_jual_toko' => $data->harga_jual_toko,
            'suplier' => $data->suplier ?? null,
            'id_model_barang' => $data->id_model,
            'notes' => $data->notes ?? null,
            'dropship' => $data->dropship ?? 0,
            'berat' => $data->berat ?? 0,
            "updated_by" => $token['user_id'],
        ];

        $this->productModel->update($id, $productData);
        $changeLog = $this->generateChangeLogString($oldProductData, $data);

        log_aktivitas([
            'user_id' => $token['user_id'],
            'action_type' => 'UPDATE',
            'target_table' => 'product',
            'target_id' => $id,
            'description' => $changeLog,
            'detail' => [
                'old' => $oldProductData,
                'new' => $data
            ],
        ]);

        // Update stock data (V1 Logic: Allow Overwrite)
        foreach ($data->stock as $toko) {
            if (isset($toko->id) && $this->stockModel->find($toko->id)) {
                $stockData = [
                    'stock' => $toko->stock,
                    'barang_cacat' => $toko->barang_cacat,
                    'dropship' => isset($toko->dropship) ? (int) $toko->dropship : 0,
                ];
                $this->stockModel->update($toko->id, $stockData);
            } else {
                $existingStock = $this->stockModel
                    ->where('id_barang', $id)
                    ->where('id_toko', $toko->id_toko)
                    ->first();

                $stockData = [
                    'stock' => $toko->stock,
                    'barang_cacat' => $toko->barang_cacat,
                    'dropship' => isset($toko->dropship) ? (int) $toko->dropship : 0,
                ];

                if ($existingStock) {
                    $this->stockModel->update($existingStock['id'], $stockData);
                } else {
                    $stockData['id_barang'] = $data->id_barang;
                    $stockData['id_toko'] = $toko->id_toko;
                    $this->stockModel->insert($stockData);
                }
            }
        }

        return $this->jsonResponse->oneResp('Update ' . ($data->nama_barang ?? 'product') . ' successfully', ['id' => $id], 200);
    }

    // V2: Update Product (No Stock Update, Only Dropship/Store Relation)
    public function updateProductV2($id = null)
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'id_model' => 'required',
            'harga_modal' => 'required',
            'harga_jual' => 'required',
            'harga_jual_toko' => 'permit_empty',
            'id_seri_barang' => 'permit_empty',
            'suplier' => 'permit_empty',
            'description' => 'permit_empty',
            'dropship' => 'permit_empty',
            'berat' => 'permit_empty',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $model = $this->modelBarangModel->find($data->id_model);
        if (!$model) {
            return $this->jsonResponse->error("Kategori barang tidak valid", 400);
        }

        $oldProductData = $this->getProductDetailArray($id);
        if (!$oldProductData) {
            return $this->jsonResponse->error("Produk tidak ditemukan", 404);
        }

        $oldModal = (float) ($oldProductData['harga_modal'] ?? 0);
        $newModal = (float) $data->harga_modal;

        $this->db->transStart();
        try {
            $productData = [
                'nama_barang' => $data->nama_barang ?? "",
                'description' => $data->description ?? NULL,
                'id_seri_barang' => $data->id_seri_barang ?? null,
                'harga_modal' => $newModal,
                'harga_jual' => $data->harga_jual,
                'harga_jual_toko' => $data->harga_jual_toko,
                'suplier' => $data->suplier ?? null,
                'id_model_barang' => $data->id_model,
                'notes' => $data->notes ?? null,
                'berat' => $data->berat ?? 0,
                'dropship' => $data->dropship ?? 0,
                "updated_by" => $token['user_id'],
            ];

            $this->productModel->update($id, $productData);

            // Handle Journal if Modal changed
            if ($oldModal !== $newModal) {
                $diffPerUnit = $newModal - $oldModal;
                $stocks = $this->stockModel->where('id_barang', $oldProductData['id_barang'] ?? $oldProductData['kode_barang'])->findAll();

                foreach ($stocks as $s) {
                    $totalQty = (int) $s['stock'] + (int) $s['barang_cacat'];
                    if ($totalQty == 0)
                        continue;

                    $totalDiff = abs($diffPerUnit * $totalQty);
                    $tokoId = $s['id_toko'];

                    // 1. Create Journal
                    $journalData = [
                        'tenant_id' => TenantContext::id(),
                        'id_toko' => $tokoId,
                        'reference_type' => 'REVALUATION',
                        'reference_id' => $id,
                        'reference_no' => $oldProductData['id_barang'] ?? $oldProductData['kode_barang'],
                        'date' => date('Y-m-d'),
                        'description' => "Penyesuaian Nilai Persediaan (Modal changed from $oldModal to $newModal) - " . ($data->nama_barang ?? $oldProductData['nama_barang']),
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $this->journalModel->insert($journalData);
                    $journalId = $this->journalModel->getInsertID();

                    // 2. Journal Items
                    // 10x4 = Persediaan/Inventaris, 10x1 = Akun Sementara (as offset)
                    $inventoryCode = '10' . $tokoId . '4';
                    $offsetCode = '10' . $tokoId . '8';

                    $invAccount = $this->accountModel->getByBaseCode($inventoryCode, $tokoId);
                    $offsetAccount = $this->accountModel->getByBaseCode($offsetCode, $tokoId);

                    if ($invAccount && $offsetAccount) {
                        if ($diffPerUnit > 0) {
                            // Modal naik: Dr Inventaris (Asset naik), Cr HPP (Beban turun/Gain)
                            $this->journalItemModel->insert([
                                'journal_id' => $journalId,
                                'account_id' => $invAccount['id'],
                                'debit' => $totalDiff,
                                'credit' => 0
                            ]);
                            $this->journalItemModel->insert([
                                'journal_id' => $journalId,
                                'account_id' => $offsetAccount['id'],
                                'debit' => 0,
                                'credit' => $totalDiff
                            ]);
                        } else {
                            // Modal turun: Dr HPP (Beban naik/Loss), Cr Inventaris (Asset turun)
                            $this->journalItemModel->insert([
                                'journal_id' => $journalId,
                                'account_id' => $offsetAccount['id'],
                                'debit' => $totalDiff,
                                'credit' => 0
                            ]);
                            $this->journalItemModel->insert([
                                'journal_id' => $journalId,
                                'account_id' => $invAccount['id'],
                                'debit' => 0,
                                'credit' => $totalDiff
                            ]);
                        }
                    }

                    // 3. Stock Ledger entry for info
                    $this->stockLedgerModel->insert([
                        'id_barang' => $oldProductData['id_barang'] ?? $oldProductData['kode_barang'],
                        'id_toko' => $tokoId,
                        'qty' => 0,
                        'balance' => $totalQty,
                        'reference_type' => 'REVALUATION',
                        'reference_id' => $id,
                        'description' => "Harga modal diubah: $oldModal -> $newModal. Nilai berubah: " . ($diffPerUnit * $totalQty)
                    ]);
                }
            }

            $changeLog = $this->generateChangeLogString($oldProductData, $data);

            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'UPDATE_V2',
                'target_table' => 'product',
                'target_id' => $id,
                'description' => $changeLog,
                'detail' => [
                    'old' => $oldProductData,
                    'new' => $data
                ],
            ]);

            // V2: Ensure Store Relation Exists
            if (isset($data->stock) && is_array($data->stock)) {
                foreach ($data->stock as $toko) {
                    if (isset($toko->id) && $this->stockModel->find($toko->id)) {
                        continue;
                    } else {
                        $existingStock = $this->stockModel
                            ->where('id_barang', $id)
                            ->where('id_toko', $toko->id_toko)
                            ->first();

                        if (!$existingStock) {
                            $this->stockModel->insert([
                                'id_barang' => $data->id_barang ?? $oldProductData['kode_barang'],
                                'id_toko' => $toko->id_toko,
                                'stock' => 0,
                                'barang_cacat' => 0,
                            ]);
                        }
                    }
                }
            }

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new \Exception("Gagal melakukan update produk dan revaluasi jurnal.");
            }

            return $this->jsonResponse->oneResp('Update ' . ($data->nama_barang ?? 'product') . ' successfully', ['id' => $id, 'id_barang' => $oldProductData['id_barang']], 200);
        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function adjustStock($id = null)
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'id_toko' => 'required',
            'stock' => 'required|numeric',
            'barang_cacat' => 'required|numeric',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $this->db->transStart();
        try {
            $product = $this->productModel->find($id);
            if (!$product) {
                return $this->jsonResponse->error("Produk tidak ditemukan", 404);
            }

            $id_barang = $product['id_barang'];

            $existingStock = $this->stockModel
                ->where('id_barang', $id_barang)
                ->where('id_toko', $data->id_toko)
                ->first();

            $oldStock = 0;
            $oldCacat = 0;
            $stockId = null;

            if (!$existingStock) {
                $stockData = [
                    'id_barang' => $id_barang,
                    'id_toko' => $data->id_toko,
                    'stock' => $data->stock,
                    'barang_cacat' => $data->barang_cacat,
                    'tenant_id' => \App\Libraries\TenantContext::id(),
                ];
                $this->stockModel->insert($stockData);
                $stockId = $this->stockModel->getInsertID();
            } else {
                $stockId = $existingStock['id'];
                $oldStock = (int) $existingStock['stock'];
                $oldCacat = (int) $existingStock['barang_cacat'];

                $stockData = [
                    'stock' => $data->stock,
                    'barang_cacat' => $data->barang_cacat,
                ];
                $this->stockModel->update($stockId, $stockData);
            }

            // --- Journal Entry for Adjustment ---
            $diffStock = (int) $data->stock - $oldStock;
            $diffCacat = (int) $data->barang_cacat - $oldCacat;
            $alasan = $data->alasan ?? 'Penyesuaian manual (Opname)';

            $oldTotal = $oldStock + $oldCacat;
            $newTotal = (int) $data->stock + (int) $data->barang_cacat;
            $totalDiff = $newTotal - $oldTotal;

            if ($diffStock != 0 || $diffCacat != 0) {
                $refNo = 'ADJ-OPNAME-' . time();
                $jid = $this->internalCreateJournal('ADJUSTMENT', $id, $refNo, date('Y-m-d'), "Stock Opname: {$product['nama_barang']} ({$alasan})", $data->id_toko);

                if ($totalDiff === 0) {
                    // --- Reklasifikasi saja (total tidak berubah) ---
                    // Hanya normal & cacat yang bergeser, tidak ada barang yg hilang/tambah
                    $moveQty = abs($diffCacat); // bisa pakai diff mana saja, keduanya counterpart
                    $moveValue = $moveQty * (float) $product['harga_modal'];

                    if ($moveValue > 0) {
                        if ($diffCacat > 0) {
                            // Normal berkurang, Cacat bertambah → Normal ke Cacat
                            // Dr Inventory Cacat (10x7), Cr Inventory Normal (10x4)
                            $this->internalAddJournalItem($jid, '10' . $data->id_toko . '7', $moveValue, 0, $data->id_toko);
                            $this->internalAddJournalItem($jid, '10' . $data->id_toko . '4', 0, $moveValue, $data->id_toko);
                        } else {
                            // Cacat berkurang, Normal bertambah → Cacat ke Normal
                            // Dr Inventory Normal (10x4), Cr Inventory Cacat (10x7)
                            $this->internalAddJournalItem($jid, '10' . $data->id_toko . '4', $moveValue, 0, $data->id_toko);
                            $this->internalAddJournalItem($jid, '10' . $data->id_toko . '7', 0, $moveValue, $data->id_toko);
                        }
                    }
                } else {
                    // --- Total berubah: ada selisih stok nyata ---

                    // 1. Adjustment Normal Stock
                    if ($diffStock != 0) {
                        $valueStock = abs($diffStock * (float) $product['harga_modal']);
                        if ($valueStock > 0) {
                            if ($diffStock > 0) {
                                // Surplus: Dr Inventory Normal (10x4), Cr Write Off (10x8)
                                $this->internalAddJournalItem($jid, '10' . $data->id_toko . '4', $valueStock, 0, $data->id_toko);
                                $this->internalAddJournalItem($jid, '10' . $data->id_toko . '8', 0, $valueStock, $data->id_toko);
                            } else {
                                // Shortage: Dr Write Off (10x8), Cr Inventory Normal (10x4)
                                $this->internalAddJournalItem($jid, '10' . $data->id_toko . '8', $valueStock, 0, $data->id_toko);
                                $this->internalAddJournalItem($jid, '10' . $data->id_toko . '4', 0, $valueStock, $data->id_toko);
                            }
                        }
                    }

                    // 2. Adjustment Cacat Stock
                    if ($diffCacat != 0) {
                        $valueCacat = abs($diffCacat * (float) $product['harga_modal']);
                        if ($valueCacat > 0) {
                            if ($diffCacat > 0) {
                                // Surplus Cacat: Dr Inventory Cacat (10x7), Cr Ekuitas (30x1)
                                $this->internalAddJournalItem($jid, '10' . $data->id_toko . '7', $valueCacat, 0, $data->id_toko);
                                $this->internalAddJournalItem($jid, '30' . $data->id_toko . '1', 0, $valueCacat, $data->id_toko);
                            } else {
                                // Shortage Cacat: Dr HPP (50x1), Cr Inventory Cacat (10x7)
                                $this->internalAddJournalItem($jid, '50' . $data->id_toko . '1', $valueCacat, 0, $data->id_toko);
                                $this->internalAddJournalItem($jid, '10' . $data->id_toko . '7', 0, $valueCacat, $data->id_toko);
                            }
                        }
                    }
                }
            }

            // --- Log Activities ---
            $descParts = [];
            if ($oldStock != $data->stock)
                $descParts[] = "normal {$oldStock}->{$data->stock}";
            if ($oldCacat != $data->barang_cacat)
                $descParts[] = "cacat {$oldCacat}->{$data->barang_cacat}";

            if (!empty($descParts)) {
                $descText = implode(', ', $descParts);
                log_aktivitas([
                    'user_id' => $token['user_id'],
                    'action_type' => 'ADJUST_STOCK',
                    'target_table' => 'stock',
                    'target_id' => $stockId,
                    'description' => "Opname: {$descText}, ket: {$alasan}",
                    'detail' => [
                        'old' => ['stock' => $oldStock, 'barang_cacat' => $oldCacat],
                        'new' => ['stock' => $data->stock, 'barang_cacat' => $data->barang_cacat],
                        'alasan' => $alasan
                    ],
                ]);
            }

            $this->db->transComplete();
            return $this->jsonResponse->oneResp('Penyesuaian stok berhasil', ['id' => $id, 'id_barang' => $id_barang, 'id_toko' => $data->id_toko], 200);
        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    private function getProductDetailArray($id)
    {
        // 🔹 Ambil data produk + relasi model & seri
        $product = $this->productModel
            ->select('
            product.*,
            product.id_model_barang AS id_model,
            model_barang.nama_model,
            seri.seri
        ')
            ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
            ->join('seri', 'seri.id = product.id_seri_barang', 'left')
            ->where('product.id', $id)
            ->first();

        if (!$product) {
            return null;
        }

        $p = (array) $product;

        // 🔹 Tambahkan kode_barang dan nama_lengkap_barang
        $p['kode_barang'] = $p['id_barang'] ?? null;
        $p['nama_lengkap_barang'] = trim(implode(' ', array_filter([
            $p['nama_barang'] ?? '',
            $p['nama_model'] ?? '',
            $p['seri'] ?? ''
        ])));

        // 🔹 Ambil stok (langsung dari tabel stock untuk efisiensi)
        $p['stock'] = array_map(
            function ($s) {
                return [
                    'id' => $s['id'],
                    'stock' => $s['stock'],
                    'dropship' => (bool) $s['dropship'],
                    'id_toko' => $s['id_toko'],
                    'barang_cacat' => $s['barang_cacat'],
                    'toko_name' => $s['toko_name']
                ];
            },
            $this->db->table('stock')
                ->select('
            stock.id,
            stock.stock,
            stock.dropship,
            stock.id_toko,
            stock.barang_cacat,
            toko.toko_name
        ')
                ->join('toko', 'toko.id = stock.id_toko', 'left')
                ->where('stock.id_barang', $p['id_barang'])
                ->where('stock.tenant_id', \App\Libraries\TenantContext::id())
                ->get()
                ->getResultArray()
        );

        // 🔹 Ambil gambar (hanya ambil URL langsung)
        $p['images'] = array_column(
            $this->imageModel
                ->where(['type' => 'product', 'kode' => $id])
                ->findAll(),
            'url'
        );

        // 🔹 Normalisasi field dropship & supplier
        $p['dropship'] = (bool) ($p['dropship'] ?? false);

        // 🔹 Ambil supplier details (jika ada)
        $p['supplier_details'] = !empty($p['suplier'])
            ? $this->db->table('suplier')
                ->select('id, suplier_name as name')
                ->where('id', $p['suplier'])
                ->where('tenant_id', \App\Libraries\TenantContext::id())
                ->get()
                ->getRowArray()
            : [];

        return $p;
    }


    public function getDetailById($id = null)
    {
        try {
            $product = $this->getProductDetailArray($id);
            if ($product) {
                return $this->jsonResponse->oneResp('Data berhasil diambil', $product);
            }
            return $this->jsonResponse->error('Product Not Found', 404);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }


    public function getListSeribySearchProduct()
    {
        $namaProduct = $this->request->getGet('namaProduct') ?? '';
        $tenantId = \App\Libraries\TenantContext::id();
        $cacheKey = 'seri_by_search_' . $tenantId . '_' . md5($namaProduct);

        try {
            $cached = cache()->get($cacheKey);
            if ($cached !== null) {
                return $this->jsonResponse->oneResp('Data berhasil diambil dari cache', $cached, 200);
            }

            $builder = $this->productModel
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->select([
                    'seri.seri as label',
                    'product.id_seri_barang as value',
                ]);

            $builder->where('seri.seri IS NOT NULL')
                ->where('model_barang.nama_model IS NOT NULL')
                ->where('product.nama_barang IS NOT NULL')
                ->where('seri.deleted_at IS NULL');

            if (!empty($namaProduct)) {
                $builder->groupStart()
                    ->like("CONCAT(COALESCE(product.nama_barang, ''), ' ', COALESCE(model_barang.nama_model, ''), ' ', COALESCE(seri.seri, ''))", $namaProduct)
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }

            $products = $builder
                ->groupBy('product.id_seri_barang')
                ->get()
                ->getResultArray();

            // Simpan ke cache selama 10 menit
            cache()->save($cacheKey, array_values($products), 600);

            return $this->jsonResponse->oneResp('', array_values($products), 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function getTotalByModelId()
    {
        $modelId = $this->request->getGet('model_id') ?? '';

        try {
            $builder = $this->productModel;

            if (!empty($modelId)) {
                $builder->where('id_model_barang', $modelId);
            } else {
                return $this->jsonResponse->error('Parameter model_id is required', 400);
            }
            $total = $builder->countAllResults(false);
            return $this->jsonResponse->oneResp('Total data retrieved successfully', $total, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function getTotalBySeriId()
    {
        $seriId = $this->request->getGet('seri_id') ?? '';

        try {
            $builder = $this->productModel;

            if (!empty($seriId)) {
                $builder->where('id_seri_barang', $seriId);
            } else {
                return $this->jsonResponse->error('Parameter model_id is required', 400);
            }
            $total = $builder->countAllResults(false);
            return $this->jsonResponse->oneResp('Total data retrieved successfully', $total, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function getAllProduct()
    {
        try {
            // === Ambil parameter ===
            $sortBy = $this->request->getGet('sortBy');
            if ($sortBy === 'kode_barang') {
                $sortBy = 'product.id_barang';
            } elseif (!$sortBy) {
                $sortBy = 'product.id';
            }

            $sortMethodRaw = $this->request->getGet('sortMethod');
            $sortMethod = in_array(strtolower($sortMethodRaw), ['asc', 'desc']) ? strtolower($sortMethodRaw) : 'desc';

            $namaProduct = trim($this->request->getGet('namaProduct') ?? '');
            $seri = trim($this->request->getGet('seri') ?? '');
            $model = trim($this->request->getGet('model') ?? '');
            $suplier = trim($this->request->getGet('suplier') ?? '');
            $stockFilter = trim($this->request->getGet('stock') ?? '');
            $id_toko = trim($this->request->getGet('id_toko') ?? '');
            $limit = max((int) ($this->request->getGet('limit') ?: 25), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            // === Bangun query dasar ===
            $builder = $this->productModel
                ->select([
                    'product.id',
                    'product.id_barang',
                    'product.notes',
                    'product.nama_barang',
                    'product.harga_modal',
                    'product.harga_jual',
                    'product.harga_jual_toko',
                    'product.description',
                    'product.id_model_barang',
                    'product.id_seri_barang',
                    'product.suplier',
                    'product.dropship',
                    'product.berat',
                    'model_barang.nama_model',
                    'seri.seri',
                ])
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left');

            // Filter by toko: only show products that have stock in this toko
            if (!empty($id_toko)) {
                $builder->join('stock', 'stock.id_barang = product.id_barang AND stock.id_toko = ' . (int) $id_toko, 'inner');
            }

            $builder->where('product.tenant_id', \App\Libraries\TenantContext::id())
                ->where('product.deleted_at IS NULL');

            // === Filter ===
            if (!empty($namaProduct)) {
                $db = \Config\Database::connect();
                $escapedVal = $db->escapeLikeString($namaProduct);

                $builder->groupStart()
                    ->where("CONCAT(COALESCE(product.nama_barang, ''), ' ', COALESCE(model_barang.nama_model, ''), ' ', COALESCE(seri.seri, '')) LIKE '%{$escapedVal}%'")
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }

            if (!empty($seri)) {
                $builder->where('product.id_seri_barang', $seri);
            }
            if (!empty($model)) {
                $builder->where('product.id_model_barang', $model);
            }

            if (!empty($suplier) && is_numeric($suplier)) {
                $builder->where('product.suplier', $suplier);
            }

            // Hitung total
            $total_data = $builder->countAllResults(false);
            $total_page = $limit > 0 ? ceil($total_data / $limit) : 0;

            if ($total_data === 0) {
                return $this->jsonResponse->multiResp('', [], $total_data, $total_page, $page, $limit, 200);
            }

            // Ambil data
            $products = $builder
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            // === Batch Query Preparation ===
            $productIds = [];
            $productCodes = [];
            $allSuplierIds = [];

            foreach ($products as $p) {
                $productIds[] = $p['id'];
                $productCodes[] = $p['id_barang'];
                if (!empty($p['suplier'])) {
                    $ids = explode(',', $p['suplier']);
                    foreach ($ids as $id) {
                        if (is_numeric(trim($id))) {
                            $allSuplierIds[] = trim($id);
                        }
                    }
                }
            }
            $productIds = array_unique($productIds);
            $productCodes = array_unique($productCodes);
            $allSuplierIds = array_unique($allSuplierIds);

            // --- Suplier Map ---
            $suplierNameMap = [];
            if (!empty($allSuplierIds)) {
                $supList = $this->db->table('suplier')
                    ->select('id, suplier_name')
                    ->whereIn('id', $allSuplierIds)
                    ->where('tenant_id', \App\Libraries\TenantContext::id())
                    ->get()
                    ->getResultArray();
                foreach ($supList as $sup) {
                    $suplierNameMap[$sup['id']] = $sup['suplier_name'];
                }
            }

            // --- Stock + Toko ---
            $stockByProduct = [];
            $tokoMap = [];
            if (!empty($productCodes)) {
                $stocks = $this->stockModel
                    ->select('id_barang, dropship, stock, barang_cacat, id_toko')
                    ->whereIn('id_barang', $productCodes)
                    ->findAll();

                $tokoIds = array_unique(array_column($stocks, 'id_toko'));
                if (!empty($tokoIds)) {
                    $tokoList = $this->db->table('toko')
                        ->select('id, toko_name')
                        ->where('tenant_id', \App\Libraries\TenantContext::id())
                        ->whereIn('id', $tokoIds)
                        ->get()
                        ->getResultArray();
                    $tokoMap = array_column($tokoList, 'toko_name', 'id');
                }

                foreach ($stocks as $s) {
                    $stockByProduct[$s['id_barang']][] = $s;
                }
            }

            // --- Sales + Hold Optimized (One query for both) ---
            $terjualMap = [];
            $holdMap = [];
            $holdTotalMap = [];
            if (!empty($productCodes)) {
                $rows = $this->db->table('sales_product sp')
                    ->select('
                        sp.kode_barang, 
                        t.id_toko, 
                        SUM(CASE WHEN t.status IN ("SUCCESS", "PAID", "PACKING", "IN_DELIVERY", "PARTIALLY_PAID") THEN sp.jumlah ELSE 0 END) as terjual,
                        SUM(CASE WHEN t.status = "WAITING_PAYMENT" THEN sp.jumlah ELSE 0 END) as hold
                    ')
                    ->join('transaction t', 't.id = sp.id_transaction')
                    ->whereIn('sp.kode_barang', $productCodes)
                    ->where('sp.tenant_id', \App\Libraries\TenantContext::id())
                    ->groupBy('sp.kode_barang, t.id_toko')
                    ->get()->getResultArray();

                foreach ($rows as $row) {
                    $kode = $row['kode_barang'];
                    $tId = $row['id_toko'];

                    if ($row['terjual'] > 0) {
                        $terjualMap[$kode] = ($terjualMap[$kode] ?? 0) + (int) $row['terjual'];
                    }
                    if ($row['hold'] > 0) {
                        $holdMap[$kode][$tId] = (int) $row['hold'];
                        $holdTotalMap[$kode] = ($holdTotalMap[$kode] ?? 0) + (int) $row['hold'];
                    }
                }
            }

            // --- Coming Soon ---
            $comingSoonTotalMap = [];
            if (!empty($productCodes)) {
                $rows = $this->db->table('pembelian_detail pd')
                    ->select('pd.kode_barang, SUM(pd.jumlah) as total')
                    ->join('pembelian p', 'p.id = pd.pembelian_id')
                    ->whereIn('pd.kode_barang', $productCodes)
                    ->whereIn('p.status', ['APPROVED', 'NEED_REVIEW', 'WAITING', 'PENDING', 'ON_PROGRESS'])
                    ->where('pd.tenant_id', \App\Libraries\TenantContext::id())
                    ->where('p.deleted_at IS NULL')
                    ->groupBy('pd.kode_barang')
                    ->get()->getResultArray();
                foreach ($rows as $row) {
                    $kode = strtoupper(trim($row['kode_barang']));
                    $comingSoonTotalMap[$kode] = (int) $row['total'];
                }
            }

            // --- Images ---
            $imageMap = [];
            if (!empty($productIds)) {
                $images = $this->imageModel
                    ->select('kode, url')
                    ->where('type', 'product')
                    ->whereIn('kode', $productIds)
                    ->findAll();
                foreach ($images as $img) {
                    $imageMap[$img['kode']][] = $img['url'];
                }
            }

            // === Format Hasil ===
            $formattedProducts = [];
            foreach ($products as $p) {
                $kodeBarang = $p['id_barang'];
                $namaLengkap = trim(implode(' ', array_filter([
                    $p['nama_barang'],
                    $p['nama_model'] ?? '',
                    $p['seri'] ?? ''
                ])));

                // Stock Logic
                $stocks = $stockByProduct[$kodeBarang] ?? [];
                $stockList = [];
                $totalStockReady = 0;
                $totalCacat = 0;

                foreach ($stocks as $s) {
                    $tokoId = $s['id_toko'];
                    $tokoName = $tokoMap[$tokoId] ?? 'Tidak diketahui';
                    $dropship = in_array($s['dropship'], ['1', 1, true], true);
                    $stockReady = (int) ($s['stock'] ?? 0);
                    $cacatVal = (int) ($s['barang_cacat'] ?? 0);
                    $holdVal = (int) ($holdMap[$kodeBarang][$tokoId] ?? 0);
                    $comingSoonVal = (int) ($comingSoonTotalMap[strtoupper($kodeBarang)] ?? 0);

                    $stockList[] = [
                        'stock_ready' => $stockReady,
                        'stock' => $stockReady + $holdVal, // Stock aja = ready + hold
                        'barang_cacat' => $cacatVal,
                        'hold_stock' => $holdVal,
                        'stock_coming_soon' => $comingSoonVal,
                        'toko_name' => $tokoName,
                        'id_toko' => $tokoId,
                        'dropship' => $dropship
                    ];
                    $totalStockReady += $stockReady;
                    $totalCacat += $cacatVal;
                }

                // Suplier names from map
                $suplierNames = [];
                if (!empty($p['suplier'])) {
                    $ids = explode(',', $p['suplier']);
                    foreach ($ids as $id) {
                        $id = trim($id);
                        if (isset($suplierNameMap[$id])) {
                            $suplierNames[] = $suplierNameMap[$id];
                        }
                    }
                }

                $totalHold = (int) ($holdTotalMap[$kodeBarang] ?? 0);
                $formattedProducts[] = [
                    'id' => $p['id'],
                    'kode_barang' => $kodeBarang,
                    'notes' => $p['notes'],
                    'suplier' => $suplierNames,
                    'description' => $p['description'] ?? null,
                    'nama_barang' => $p['nama_barang'],
                    'nama_lengkap_barang' => $namaLengkap,
                    'harga_modal' => $p['harga_modal'],
                    'harga_jual' => $p['harga_jual'],
                    'harga_jual_toko' => $p['harga_jual_toko'],
                    'nama_model' => $p['nama_model'] ?? null,
                    'seri' => $p['seri'] ?? null,
                    'dropship' => $p['dropship'] ?? null,
                    'berat' => $p['berat'] ?? null,
                    'stock' => $stockList,
                    'total_stock_ready' => $totalStockReady,
                    'total_stock' => $totalStockReady + $totalHold, // total ready + hold
                    'total_cacat' => $totalCacat,
                    'total_terjual' => (int) ($terjualMap[$kodeBarang] ?? 0),
                    'total_hold' => $totalHold,
                    'total_coming_soon' => (int) ($comingSoonTotalMap[strtoupper($kodeBarang)] ?? 0),
                    'images' => $imageMap[$p['id']] ?? []
                ];
            }

            // === Filter berdasarkan stok ===
            if (!empty($stockFilter)) {
                $formattedProducts = array_filter($formattedProducts, function ($prod) use ($stockFilter) {
                    $total = (int) $prod['total_stock_ready'];
                    switch ($stockFilter) {
                        case 'available':
                            return $total > 6;
                        case 'low_stock':
                            return $total <= 5 && $total > 0;
                        case 'out_stock':
                            return $total === 0;
                        default:
                            return true;
                    }
                });
                $formattedProducts = array_values($formattedProducts);
                $total_data = count($formattedProducts);
                $total_page = $limit > 0 ? ceil($total_data / $limit) : 0;
            }

            return $this->jsonResponse->multiResp('', $formattedProducts, $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            log_message('error', '[getAllProduct] Error at ' . $e->getFile() . ':' . $e->getLine() . ' - ' . $e->getMessage());
            return $this->jsonResponse->error('Terjadi kesalahan saat mengambil data produk.', 500);
        }
    }
    public function getProductStock()
    {
        try {
            $sortBy = $this->request->getGet('sortBy') ?? 'product.id_barang';
            $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
            $namaProduct = $this->request->getGet('namaProduct') ?? '';
            $id_toko = $this->request->getGet('id_toko') ?? '';
            $stockFilter = $this->request->getGet('stockFilter') ?? '';
            $is_pricelist = $this->request->getGet('is_pricelist') ?? false;
            $customer_id = $this->request->getGet('customer_id') ?? '';
            $seri = $this->request->getGet('seri') ?? '';
            $model = $this->request->getGet('model') ?? '';
            $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            // Ambil data dari body request
            $requestBody = $this->request->getJSON(true);
            $kode_exclude = !empty($requestBody['kode_exclude']) ? $requestBody['kode_exclude'] : [];


            $selectFields = [
                'product.id',
                'product.berat',
                'product.id_barang as kode_barang',
                'model_barang.nama_model',
                'product.nama_barang as nama_barang',
                'product.harga_modal',
                'CONCAT(COALESCE(product.nama_barang, ""), " ", COALESCE(model_barang.nama_model, ""), " ", COALESCE(seri.seri, "")) as nama_lengkap_barang',
                'COALESCE(seri.seri, "") as seri',
                'stock.stock',
                'stock.dropship',
                'stock.barang_cacat',
                'toko.toko_name',
                'product.harga_jual',
                '(SELECT COALESCE(SUM(sp.jumlah), 0) 
                  FROM sales_product sp 
                  JOIN transaction t ON t.id = sp.id_transaction 
                  WHERE sp.kode_barang = product.id_barang 
                    AND t.id_toko = stock.id_toko 
                    AND t.status = "WAITING_PAYMENT" 
                    AND t.tenant_id = product.tenant_id
                 ) as hold_stock'
            ];

            $builder = $this->productModel
                ->join('stock', 'stock.id_barang = product.id_barang', 'left')
                ->join('toko', 'toko.id = stock.id_toko', 'left')
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->where('product.tenant_id', \App\Libraries\TenantContext::id())
                ->select($selectFields);


            // Apply filters
            if (!empty($namaProduct)) {
                $currDb = \Config\Database::connect();
                $escapedVal = $currDb->escapeLikeString($namaProduct);
                $builder->groupStart()
                    ->where("CONCAT_WS(' ', product.nama_barang, model_barang.nama_model, seri.seri) LIKE '%{$escapedVal}%'")
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }

            if (!empty($seri)) {
                $builder->where('product.id_seri_barang', $seri);
            }
            if (!empty($model)) {
                $builder->where('product.id_model_barang', $model);
            }
            if (!empty($id_toko)) {
                $builder->where('toko.id', $id_toko);
            }

            // Exclude products with kode_exclude
            if (!empty($kode_exclude) && is_array($kode_exclude)) {
                $builder->whereNotIn('product.id_barang', $kode_exclude);
            }


            if (!$is_pricelist) {
                $builder->where('stock.stock >', 0);
            }

            // Fetch results
            $productList = $builder
                ->orderBy($sortBy, $sortMethod)
                ->get()
                ->getResultArray();

            // Format results and apply stockFilter manually because it involves calculated hold_stock
            $formattedProducts = [];
            foreach ($productList as $p) {
                $stock = (int) ($p['stock'] ?? 0);
                $hold = (int) ($p['hold_stock'] ?? 0);
                $total_ready = $stock - $hold;

                $formattedProducts[] = [
                    'id' => $p['id'],
                    'berat' => $p['berat'],
                    'kode_barang' => $p['kode_barang'],
                    'nama_model' => $p['nama_model'],
                    'nama_barang' => $p['nama_barang'],
                    'harga_modal' => $p['harga_modal'],
                    'nama_lengkap_barang' => $p['nama_lengkap_barang'],
                    'seri' => $p['seri'],
                    'stock' => $stock,
                    'total_stock_ready' => $total_ready,
                    'hold_stock' => $hold,
                    'dropship' => $p['dropship'],
                    'barang_cacat' => $p['barang_cacat'],
                    'toko_name' => $p['toko_name'],
                    'harga_jual' => $p['harga_jual'],
                ];
            }

            if (!empty($stockFilter)) {
                $formattedProducts = array_filter($formattedProducts, function ($prod) use ($stockFilter) {
                    $total = (int) $prod['total_stock_ready'];
                    switch ($stockFilter) {
                        case 'available':
                            return $total > 6;
                        case 'low_stock':
                            return $total <= 5 && $total > 0;
                        case 'out_stock':
                            return $total === 0;
                        default:
                            return true;
                    }
                });
                $formattedProducts = array_values($formattedProducts);
            }

            $total_data = count($formattedProducts);
            $total_page = $limit > 0 ? ceil($total_data / $limit) : 0;

            // Apply manual pagination
            $pagedData = array_slice($formattedProducts, $offset, $limit);

            return $this->jsonResponse->multiResp('', $pagedData, $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    public function getProductStockForPricelist()
    {
        try {
            // === Ambil parameter ===
            $sortBy = $this->request->getGet('sortBy') ?? 'product.id_barang';
            $sortMethod = in_array(strtolower($this->request->getGet('sortMethod')), ['asc', 'desc'])
                ? strtolower($this->request->getGet('sortMethod'))
                : 'asc';
            $namaProduct = trim($this->request->getGet('namaProduct') ?? '');
            $id_toko = trim($this->request->getGet('id_toko') ?? '');
            $customer_id = trim($this->request->getGet('customer_id') ?? '');
            $seri = trim($this->request->getGet('seri') ?? '');
            $model = trim($this->request->getGet('model') ?? '');
            $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            // Cache key
            $tenantId = \App\Libraries\TenantContext::id();
            $cacheKeyData = compact('tenantId', 'sortBy', 'sortMethod', 'namaProduct', 'id_toko', 'customer_id', 'seri', 'model', 'limit', 'page');
            $cacheKey = 'getProductStockForPricelist_' . md5(json_encode($cacheKeyData));
            $cache = \Config\Services::cache();

            if ($cached = $cache->get($cacheKey)) {
                return $this->jsonResponse->multiResp('', $cached['data'], $cached['total_data'], $cached['total_page'], $page, $limit, 200);
            }

            // === Cek tipe customer ===
            $isSpecialCustomer = false;
            if (!empty($customer_id) && is_numeric($customer_id)) {
                $customer = $this->db->table('customer')
                    ->select('type')
                    ->where('id', (int) $customer_id)
                    ->where('tenant_id', \App\Libraries\TenantContext::id())
                    ->where('deleted_at', null)
                    ->get()
                    ->getRow();

                if ($customer && $customer->type === 'special') {
                    $isSpecialCustomer = true;
                }
            }

            // === LANGKAH 1: Ambil produk ===
            $productBuilder = $this->productModel
                ->select([
                    'product.id',
                    'product.id_barang',
                    'product.nama_barang',

                    'product.harga_modal',
                    'product.harga_jual',
                    'product.harga_jual_toko',
                    'product.description',
                    'product.id_model_barang',
                    'product.id_seri_barang',
                    'product.berat',
                    'model_barang.nama_model as nama_model',
                    'seri.seri as seri',
                ])
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->where('product.tenant_id', \App\Libraries\TenantContext::id());

            // Filter teks
            if (!empty($namaProduct)) {
                $currDb = \Config\Database::connect();
                $escapedVal = $currDb->escapeLikeString($namaProduct);
                $productBuilder->groupStart()
                    ->where("CONCAT_WS(' ', product.nama_barang, model_barang.nama_model, seri.seri) LIKE '%{$escapedVal}%'")
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }
            if (!empty($seri))
                $productBuilder->where('product.id_seri_barang', $seri);
            if (!empty($model))
                $productBuilder->where('product.id_model_barang', $model);

            // Filter berdasarkan stock & toko
            if (!empty($id_toko) && is_numeric($id_toko)) {
                $productBuilder->whereIn('product.id_barang', function ($sub) use ($id_toko) {
                    return $sub->select('id_barang')
                        ->from('stock')
                        ->where('id_toko', (int) $id_toko)
                        ->where('tenant_id', \App\Libraries\TenantContext::id())
                        ->where('stock >', 0);
                });
            } else {
                $productBuilder->whereIn('product.id_barang', function ($sub) {
                    return $sub->select('id_barang')
                        ->from('stock')
                        ->where('tenant_id', \App\Libraries\TenantContext::id())
                        ->where('stock >', 0);
                });
            }

            // Hitung & ambil data
            $total_data = $productBuilder->countAllResults(false);
            $total_page = $limit > 0 ? ceil($total_data / $limit) : 0;

            if ($total_data === 0) {
                $cache->save($cacheKey, ['data' => [], 'total_data' => 0, 'total_page' => 0], 300);
                return $this->jsonResponse->multiResp('', [], 0, 0, $page, $limit, 200);
            }

            $products = $productBuilder
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            // === LANGKAH 2: Ambil stock ===
            $productCodes = array_unique(array_column($products, 'id_barang'));
            $stockByProduct = [];
            $tokoMap = [];

            if (!empty($productCodes)) {
                $stockQuery = $this->db->table('stock')
                    ->select('id_barang, id_toko, stock, barang_cacat, dropship')
                    ->whereIn('id_barang', $productCodes)
                    ->where('tenant_id', \App\Libraries\TenantContext::id())
                    ->where('stock >', 0);

                if (!empty($id_toko) && is_numeric($id_toko)) {
                    $stockQuery->where('id_toko', (int) $id_toko);
                }

                $allStocks = $stockQuery->get()->getResultArray();

                $tokoIds = array_unique(array_column($allStocks, 'id_toko'));
                if (!empty($tokoIds)) {
                    $tokoList = $this->db->table('toko')
                        ->select('id, toko_name')
                        ->where('tenant_id', \App\Libraries\TenantContext::id())
                        ->whereIn('id', $tokoIds)
                        ->get()
                        ->getResultArray();
                    $tokoMap = array_column($tokoList, 'toko_name', 'id');
                }

                foreach ($allStocks as $s) {
                    $stockByProduct[$s['id_barang']][] = [
                        'stock' => (int) $s['stock'],
                        'barang_cacat' => (int) $s['barang_cacat'],
                        'toko_name' => $tokoMap[$s['id_toko']] ?? 'Toko Tidak Diketahui',
                        'dropship' => in_array($s['dropship'], ['1', 1, true], true),
                    ];
                }
            }

            // === LANGKAH 3: Ambil images ===
            $productIds = array_unique(array_column($products, 'id'));
            $imageMap = [];
            if (!empty($productIds)) {
                $images = $this->imageModel
                    ->select('kode, url')
                    ->where('type', 'product')
                    ->whereIn('kode', $productIds)
                    ->findAll();
                foreach ($images as $img) {
                    $imageMap[$img['kode']][] = $img['url'];
                }
            }

            // === LANGKAH 4: Format hasil ===
            $result = [];
            foreach ($products as $p) {
                $namaLengkap = trim(implode(' ', array_filter([
                    $p['nama_barang'],
                    $p['nama_model'] ?? '',
                    $p['seri'] ?? ''
                ])));

                // 🔥 Tentukan harga berdasarkan tipe customer
                $hargaJual = $isSpecialCustomer
                    ? $p['harga_jual_toko']
                    : $p['harga_jual'];

                $result[] = [
                    'id' => $p['id'],
                    'kode_barang' => $p['id_barang'],
                    'nama_barang' => $p['nama_barang'],
                    'nama_lengkap_barang' => $namaLengkap,
                    'nama_model' => $p['nama_model'] ?? null,
                    'seri' => $p['seri'] ?? null,
                    'harga_jual' => $hargaJual,
                    'berat' => $p['berat'] ?? null,
                    'stock' => $stockByProduct[$p['id_barang']] ?? [],
                    'images' => $imageMap[$p['id']] ?? [],
                ];
            }

            $cache->save($cacheKey, [
                'data' => $result,
                'total_data' => $total_data,
                'total_page' => $total_page,
            ], 300);

            return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            log_message('error', '[getProductStockForPricelist] Error: ' . $e->getMessage());
            return $this->jsonResponse->error('Terjadi kesalahan saat mengambil data.', 500);
        }
    }

    /**
     * V2: Get product stock for pricelist with customer discount
     * Uses customer JWT token to apply discount automatically
     * 
     * @return Response
     */
    public function getProductStockForPricelistV2()
    {
        try {
            // === Ambil parameter ===
            $sortBy = $this->request->getGet('sortBy') ?? 'product.id_barang';
            $sortMethod = in_array(strtolower($this->request->getGet('sortMethod')), ['asc', 'desc'])
                ? strtolower($this->request->getGet('sortMethod'))
                : 'asc';
            $namaProduct = trim($this->request->getGet('namaProduct') ?? '');
            $id_toko = trim($this->request->getGet('id_toko') ?? '');
            $seri = trim($this->request->getGet('seri') ?? '');
            $model = trim($this->request->getGet('model') ?? '');
            $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            // === Get customer discount from JWT token (if authenticated) ===
            $customer = $this->request->customer ?? null;
            $discountType = null;
            $discountValue = 0;

            if ($customer) {
                $discountType = $customer['discount_type'] ?? null;
                $discountValue = (float) ($customer['discount_value'] ?? 0);
            }

            // Cache key (include customer discount in cache key)
            $tenantId = \App\Libraries\TenantContext::id();
            $cacheKeyData = compact('tenantId', 'sortBy', 'sortMethod', 'namaProduct', 'id_toko', 'seri', 'model', 'limit', 'page', 'discountType', 'discountValue');
            $cacheKey = 'getProductStockForPricelistV2_' . md5(json_encode($cacheKeyData));
            $cache = \Config\Services::cache();

            if ($cached = $cache->get($cacheKey)) {
                return $this->jsonResponse->multiResp('', $cached['data'], $cached['total_data'], $cached['total_page'], $page, $limit, 200);
            }

            // === LANGKAH 1: Ambil produk ===
            $productBuilder = $this->productModel
                ->select([
                    'product.id',
                    'product.id_barang',
                    'product.nama_barang',
                    'product.harga_modal',
                    'product.harga_jual',
                    'product.description',
                    'product.id_model_barang',
                    'product.id_seri_barang',
                    'model_barang.nama_model as nama_model',
                    'seri.seri as seri',
                ])
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->where('product.tenant_id', \App\Libraries\TenantContext::id());

            // Filter teks
            if (!empty($namaProduct)) {
                $currDb = \Config\Database::connect();
                $escapedVal = $currDb->escapeLikeString($namaProduct);
                $productBuilder->groupStart()
                    ->where("CONCAT_WS(' ', product.nama_barang, model_barang.nama_model, seri.seri) LIKE '%{$escapedVal}%'")
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }
            if (!empty($seri))
                $productBuilder->where('product.id_seri_barang', $seri);
            if (!empty($model))
                $productBuilder->where('product.id_model_barang', $model);

            // Filter berdasarkan stock & toko
            if (!empty($id_toko) && is_numeric($id_toko)) {
                $productBuilder->whereIn('product.id_barang', function ($sub) use ($id_toko) {
                    return $sub->select('id_barang')
                        ->from('stock')
                        ->where('id_toko', (int) $id_toko)
                        ->where('tenant_id', \App\Libraries\TenantContext::id())
                        ->where('stock >', 0);
                });
            } else {
                $productBuilder->whereIn('product.id_barang', function ($sub) {
                    return $sub->select('id_barang')
                        ->from('stock')
                        ->where('tenant_id', \App\Libraries\TenantContext::id())
                        ->where('stock >', 0);
                });
            }

            // Hitung & ambil data
            $total_data = $productBuilder->countAllResults(false);
            $total_page = $limit > 0 ? ceil($total_data / $limit) : 0;

            if ($total_data === 0) {
                $cache->save($cacheKey, ['data' => [], 'total_data' => 0, 'total_page' => 0], 300);
                return $this->jsonResponse->multiResp('', [], 0, 0, $page, $limit, 200);
            }

            $products = $productBuilder
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            // === LANGKAH 2: Ambil stock ===
            $productCodes = array_unique(array_column($products, 'id_barang'));
            $stockByProduct = [];
            $tokoMap = [];

            if (!empty($productCodes)) {
                $stockQuery = $this->db->table('stock')
                    ->select('id_barang, id_toko, stock, barang_cacat, dropship')
                    ->whereIn('id_barang', $productCodes)
                    ->where('tenant_id', \App\Libraries\TenantContext::id())
                    ->where('stock >', 0);

                if (!empty($id_toko) && is_numeric($id_toko)) {
                    $stockQuery->where('id_toko', (int) $id_toko);
                }

                $allStocks = $stockQuery->get()->getResultArray();

                $tokoIds = array_unique(array_column($allStocks, 'id_toko'));
                if (!empty($tokoIds)) {
                    $tokoList = $this->db->table('toko')
                        ->select('id, toko_name')
                        ->where('tenant_id', \App\Libraries\TenantContext::id())
                        ->whereIn('id', $tokoIds)
                        ->get()
                        ->getResultArray();
                    $tokoMap = array_column($tokoList, 'toko_name', 'id');
                }

                foreach ($allStocks as $s) {
                    $stockByProduct[$s['id_barang']][] = [
                        'stock' => (int) $s['stock'],
                        'barang_cacat' => (int) $s['barang_cacat'],
                        'toko_name' => $tokoMap[$s['id_toko']] ?? 'Toko Tidak Diketahui',
                        'dropship' => in_array($s['dropship'], ['1', 1, true], true),
                    ];
                }
            }

            // === LANGKAH 3: Ambil images ===
            $productIds = array_unique(array_column($products, 'id'));
            $imageMap = [];
            if (!empty($productIds)) {
                $images = $this->imageModel
                    ->select('kode, url')
                    ->where('type', 'product')
                    ->whereIn('kode', $productIds)
                    ->findAll();
                foreach ($images as $img) {
                    $imageMap[$img['kode']][] = $img['url'];
                }
            }

            // === LANGKAH 4: Format hasil dengan discount ===
            $result = [];
            foreach ($products as $p) {
                $namaLengkap = trim(implode(' ', array_filter([
                    $p['nama_barang'],
                    $p['nama_model'] ?? '',
                    $p['seri'] ?? ''
                ])));

                // Harga jual original
                $hargaJualOriginal = (float) $p['harga_jual'];
                $hargaJualFinal = $hargaJualOriginal;
                $discountAmount = 0;

                // Apply discount jika customer punya discount
                if ($discountType && $discountValue > 0) {
                    $type = strtolower($discountType);
                    if ($type === 'percentage') {
                        // Discount percentage
                        $discountAmount = $hargaJualOriginal * ($discountValue / 100);
                        $hargaJualFinal = $hargaJualOriginal - $discountAmount;
                    } elseif ($type === 'fixed') {
                        // Discount fixed amount
                        $discountAmount = $discountValue;
                        $hargaJualFinal = max(0, $hargaJualOriginal - $discountValue);
                    }
                }

                $result[] = [
                    'id' => $p['id'],
                    'kode_barang' => $p['id_barang'],
                    'nama_barang' => $p['nama_barang'],
                    'nama_lengkap_barang' => $namaLengkap,
                    'nama_model' => $p['nama_model'] ?? null,
                    'seri' => $p['seri'] ?? null,
                    'description' => $p['description'] ?? null,
                    'harga_jual' => (int) round($hargaJualFinal), // Harga setelah discount
                    'stock' => $stockByProduct[$p['id_barang']] ?? [],
                    'images' => $imageMap[$p['id']] ?? [],
                ];
            }

            $cache->save($cacheKey, [
                'data' => $result,
                'total_data' => $total_data,
                'total_page' => $total_page,
            ], 300);

            return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            log_message('error', '[getProductStockForPricelistV2] Error: ' . $e->getMessage());
            return $this->jsonResponse->error('Terjadi kesalahan saat mengambil data.', 500);
        }
    }

    public function getProductStockSummary()
    {
        try {
            // === Ambil parameter ===
            $sortBy = $this->request->getGet('sortBy');
            if ($sortBy === 'kode_barang') {
                $sortBy = 'product.id_barang';
            } elseif (!$sortBy) {
                $sortBy = 'product.id';
            }

            $sortMethodRaw = $this->request->getGet('sortMethod');
            $sortMethod = in_array(strtolower($sortMethodRaw), ['asc', 'desc']) ? strtolower($sortMethodRaw) : 'desc';

            $namaProduct = trim($this->request->getGet('namaProduct') ?? '');
            $limit = max((int) ($this->request->getGet('limit') ?: 25), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            // === Bangun query dasar ===
            $builder = $this->productModel
                ->select([
                    'product.id',
                    'product.id_barang',
                    'product.nama_barang',
                    'product.harga_modal',
                    'product.id_model_barang',
                    'product.id_seri_barang',
                    'model_barang.nama_model',
                    'seri.seri',
                ])
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->where('product.tenant_id', \App\Libraries\TenantContext::id());

            // === Filter nama produk (id_barang atau nama lengkap) ===
            if (!empty($namaProduct)) {
                $currDb = \Config\Database::connect();
                $escapedVal = $currDb->escapeLikeString($namaProduct);
                $builder->groupStart()
                    ->where("CONCAT_WS(' ', product.nama_barang, model_barang.nama_model, seri.seri) LIKE '%{$escapedVal}%'")
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }

            // Hitung total untuk pagination
            $total_data = $builder->countAllResults(false);
            $total_page = $limit > 0 ? ceil($total_data / $limit) : 0;

            if ($total_data === 0) {
                return $this->jsonResponse->multiResp('', [], $total_data, $total_page, $page, $limit, 200);
            }

            // Ambil data produk dengan pagination
            $products = $builder
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            $productCodes = array_unique(array_column($products, 'id_barang'));

            // === Hitung stock dari table stock ===
            $stockData = [];
            if (!empty($productCodes)) {
                $stocks = $this->stockModel
                    ->select('id_barang, id_toko, stock as total_stock, barang_cacat as total_cacat')
                    ->whereIn('id_barang', $productCodes)
                    ->get()
                    ->getResultArray();

                foreach ($stocks as $stock) {
                    $kode = $stock['id_barang'];
                    if (!isset($stockData[$kode])) {
                        $stockData[$kode] = [
                            'total_stock' => 0,
                            'total_cacat' => 0,
                            'detail_stock_normal' => [],
                            'detail_stock_cacat' => []
                        ];
                    }
                    $stockData[$kode]['total_stock'] += (int) $stock['total_stock'];
                    $stockData[$kode]['total_cacat'] += (int) $stock['total_cacat'];

                    $stockData[$kode]['detail_stock_normal'][] = [
                        'id_toko' => (int) $stock['id_toko'],
                        'jumlah' => (int) $stock['total_stock']
                    ];
                    $stockData[$kode]['detail_stock_cacat'][] = [
                        'id_toko' => (int) $stock['id_toko'],
                        'jumlah' => (int) $stock['total_cacat']
                    ];
                }
            }

            // === Hitung stock gantung (waiting_payment) dari sales_product + transaction ===
            $pendingStockData = [];
            if (!empty($productCodes)) {
                $pendingStocks = $this->db->table('sales_product sp')
                    ->select('sp.kode_barang, t.id_toko, SUM(sp.jumlah) as total_pending')
                    ->join('transaction t', 't.id = sp.id_transaction')
                    ->whereIn('sp.kode_barang', $productCodes)
                    ->where('t.tenant_id', \App\Libraries\TenantContext::id())
                    ->where('t.status', 'WAITING_PAYMENT')
                    ->groupBy('sp.kode_barang, t.id_toko')
                    ->get()
                    ->getResultArray();

                foreach ($pendingStocks as $pending) {
                    $kode = $pending['kode_barang'];
                    if (!isset($pendingStockData[$kode])) {
                        $pendingStockData[$kode] = [
                            'total_pending' => 0,
                            'detail_stock_gantung' => []
                        ];
                    }
                    $pendingStockData[$kode]['total_pending'] += (int) $pending['total_pending'];
                    $pendingStockData[$kode]['detail_stock_gantung'][] = [
                        'id_toko' => (int) $pending['id_toko'],
                        'jumlah' => (int) $pending['total_pending']
                    ];
                }
            }

            // === Format hasil dan hitung estimasi modal untuk data yang dipaginasi ===
            $summaryProducts = [];
            $pageTotalStock = 0;
            $pageTotalEstimasiModal = 0;

            foreach ($products as $product) {
                $kodeBarang = $product['id_barang'];

                $stock = $stockData[$kodeBarang] ?? [
                    'total_stock' => 0,
                    'total_cacat' => 0,
                    'detail_stock_normal' => [],
                    'detail_stock_cacat' => []
                ];
                $pending = $pendingStockData[$kodeBarang] ?? [
                    'total_pending' => 0,
                    'detail_stock_gantung' => []
                ];

                $totalStock = $stock['total_stock'] + $stock['total_cacat'] + $pending['total_pending'];
                $hargaModal = (float) $product['harga_modal'];
                $estimasiModal = $totalStock * $hargaModal;

                // Format nama lengkap barang
                $namaLengkap = trim(implode(' ', array_filter([
                    $product['nama_barang'],
                    $product['nama_model'] ?? '',
                    $product['seri'] ?? ''
                ])));

                $summaryProducts[] = [
                    'id' => $product['id'],
                    'kode_barang' => $kodeBarang,
                    'nama_barang' => $product['nama_barang'],
                    'nama_lengkap_barang' => $namaLengkap,
                    'nama_model' => $product['nama_model'] ?? null,
                    'seri' => $product['seri'] ?? null,
                    'harga_modal' => $hargaModal,

                    // Stock breakdown
                    'stock_normal' => $stock['total_stock'],
                    'stock_cacat' => $stock['total_cacat'],
                    'stock_gantung' => $pending['total_pending'],
                    'stock_total' => $totalStock,

                    // Detail stock per toko
                    'detail_stock_normal' => $stock['detail_stock_normal'],
                    'detail_stock_cacat' => $stock['detail_stock_cacat'],
                    'detail_stock_gantung' => $pending['detail_stock_gantung'],

                    // Estimasi
                    'estimasi_modal' => $estimasiModal
                ];

                // Accumulate page totals
                $pageTotalStock += $totalStock;
                $pageTotalEstimasiModal += $estimasiModal;
            }

            // === HITUNG SUMMARY GLOBAL (SEMUA DATA TANPA PAGINATION) ===
            $globalBuilder = $this->productModel
                ->select([
                    'product.id_barang',
                    'product.harga_modal',
                ])
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->where('product.tenant_id', \App\Libraries\TenantContext::id());

            // Apply filter yang sama untuk global summary
            if (!empty($namaProduct)) {
                $globalBuilder->groupStart()
                    ->like("CONCAT_WS(' ', product.nama_barang, model_barang.nama_model, seri.seri)", $namaProduct)
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }

            $allProducts = $globalBuilder->get()->getResultArray();
            $allProductCodes = array_unique(array_column($allProducts, 'id_barang'));

            // Hitung stock global
            $globalStockData = [];
            if (!empty($allProductCodes)) {
                $globalStocks = $this->stockModel
                    ->select('id_barang, SUM(stock) as total_stock, SUM(barang_cacat) as total_cacat')
                    ->whereIn('id_barang', $allProductCodes)
                    ->groupBy('id_barang')
                    ->get()
                    ->getResultArray();

                foreach ($globalStocks as $stock) {
                    $globalStockData[$stock['id_barang']] = [
                        'total_stock' => (int) $stock['total_stock'],
                        'total_cacat' => (int) $stock['total_cacat']
                    ];
                }
            }

            // Hitung stock gantung global
            $globalPendingStockData = [];
            if (!empty($allProductCodes)) {
                $globalPendingStocks = $this->db->table('sales_product sp')
                    ->select('sp.kode_barang, SUM(sp.jumlah) as total_pending')
                    ->join('transaction t', 't.id = sp.id_transaction')
                    ->whereIn('sp.kode_barang', $allProductCodes)
                    ->where('t.tenant_id', \App\Libraries\TenantContext::id())
                    ->where('t.status', 'WAITING_PAYMENT')
                    ->groupBy('sp.kode_barang')
                    ->get()
                    ->getResultArray();

                foreach ($globalPendingStocks as $pending) {
                    $globalPendingStockData[$pending['kode_barang']] = (int) $pending['total_pending'];
                }
            }

            // Hitung total global
            $globalTotalStock = 0;
            $globalTotalEstimasiModal = 0;

            foreach ($allProducts as $product) {
                $kodeBarang = $product['id_barang'];

                $stock = $globalStockData[$kodeBarang] ?? ['total_stock' => 0, 'total_cacat' => 0];
                $pendingStock = $globalPendingStockData[$kodeBarang] ?? 0;

                $totalStock = $stock['total_stock'] + $stock['total_cacat'] + $pendingStock;
                $hargaModal = (float) $product['harga_modal'];
                $estimasiModal = $totalStock * $hargaModal;

                $globalTotalStock += $totalStock;
                $globalTotalEstimasiModal += $estimasiModal;
            }

            // === Response dengan summary ===
            $response = [
                'products' => $summaryProducts,
                'summary_page' => [
                    'total_produk_page' => count($summaryProducts),
                    'total_stock_page' => $pageTotalStock,
                    'total_estimasi_modal_page' => $pageTotalEstimasiModal
                ],
                'summary_global' => [
                    'total_produk_global' => count($allProducts),
                    'total_stock_global' => $globalTotalStock,
                    'total_estimasi_modal_global' => $globalTotalEstimasiModal
                ]
            ];

            return $this->jsonResponse->multiResp(
                'Data summary modal produk berhasil diambil',
                $response,
                $total_data,
                $total_page,
                $page,
                $limit,
                200
            );

        } catch (\Exception $e) {
            log_message('error', '[getProductStockSummary] Error: ' . $e->getMessage());
            return $this->jsonResponse->error('Terjadi kesalahan saat mengambil summary modal produk.', 500);
        }
    }
    public function deleteByProductId($id)
    {
        $token = $this->request->user;

        $product = $this->getProductDetailArray($id);

        if (!$product) {
            return $this->jsonResponse->error("Product Not Found", 404);
        }

        try {
            $this->db->transBegin();

            $deleted = $this->productModel->delete($id);

            if (!$deleted) {
                throw new \Exception('Delete query did not affect any rows.');
            }

            $this->db->transCommit();

            $productIdentifier = $product['nama_barang'] . ' ' . $product['nama_model'] . ' ' . $product['seri'];

            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'DELETE',
                'target_table' => 'product',
                'target_id' => $id,
                'description' => "Produk '{$productIdentifier}' (ID Barang: {$product['id_barang']}) dihapus.",
                'detail' => [
                    'deleted_data' => $product,
                ],
            ]);

            return $this->jsonResponse->oneResp("Data Deleted Successfully", "", 200);

        } catch (\Exception $e) {
            $this->db->transRollback();

            return $this->jsonResponse->error("Failed to delete data due to a server error: " . $e->getMessage(), 500);
        }
    }

    public function bulkUpload()
    {
        $token = $this->request->user;
        $file = $this->request->getFile('excel_file');

        if (!$file->isValid() || !in_array($file->getExtension(), ['xls', 'xlsx'])) {
            return $this->jsonResponse->error("Invalid file format. Please upload an Excel file.", 400);
        }

        $spreadsheet = IOFactory::load($file->getTempName());
        $sheet = $spreadsheet->getActiveSheet();
        $excelData = $sheet->toArray(null, true, true, true);
        $header = array_shift($excelData);

        // Ambil toko dari header
        $storeNames = [];
        foreach ($header as $key => $column) {
            if (stripos($column, 'STOCK') !== false || stripos($column, 'BARANG CACAT') !== false) {
                $storeName = trim(str_replace(['STOCK', 'BARANG CACAT'], '', $column));
                $storeNames[$storeName] = $key; // Simpan indeks abjad kolom
            }
        }

        // Mapping toko
        $storeQuery = $this->db->table('toko')
            ->whereIn('toko_name', array_keys($storeNames))
            ->where('tenant_id', \App\Libraries\TenantContext::id())
            ->get();
        $storeMap = [];
        foreach ($storeQuery->getResultArray() as $store) {
            $storeMap[$store['toko_name']] = $store['id'];
        }

        $categoryMap = [];
        $seriesMap = [];
        $supplierMap = [];
        $dataToInsert = [];
        $productMap = [];
        $stockToInsert = [];

        $lastProduct = $this->db->table('product')
            ->select('id')
            ->where('tenant_id', \App\Libraries\TenantContext::id())
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();
        $lastId = $lastProduct ? (int) preg_replace('/[^0-9]/', '', $lastProduct['id']) : 0;

        foreach ($excelData as $row) {
            $lastId++;
            $categoryName = trim($row['B'] ?? '');
            if (empty($categoryName))
                continue;

            // Cek kategori
            if (!isset($categoryMap[$categoryName])) {
                $category = $this->db->table('model_barang')
                    ->where('LOWER(TRIM(nama_model))', strtolower($categoryName))
                    ->where('tenant_id', \App\Libraries\TenantContext::id())
                    ->get()
                    ->getRowArray();
                if ($category) {
                    $categoryMap[$categoryName] = $category['id'];
                } else {
                    log_message('error', "Category '$categoryName' not found in database.");
                    return $this->jsonResponse->error("Category '$categoryName' not found.", 400);
                }
            }

            // Cek seri
            $seriesName = trim($row['C'] ?? '');
            if (!isset($seriesMap[$seriesName]) && !empty($seriesName)) {
                $series = $this->db->table('seri')
                    ->where('seri', $seriesName)
                    ->get()->getRowArray();
                if ($series) {
                    $seriesMap[$seriesName] = $series['id'];
                } else {
                    $this->db->table('seri')->insert([
                        'tenant_id' => \App\Libraries\TenantContext::id(),
                        'seri' => $seriesName
                    ]);
                    $seriesMap[$seriesName] = $this->db->insertID();
                }
            }

            // Cek supplier
            $supplierList = array_filter(array_map('trim', explode(',', $row['D'] ?? '')));
            $suplierIds = [];
            foreach ($supplierList as $supplier) {
                if (!isset($supplierMap[$supplier])) {
                    $existingSupplier = $this->db->table('suplier')
                        ->where('suplier_name', $supplier)
                        ->where('tenant_id', \App\Libraries\TenantContext::id())
                        ->get()->getRowArray();
                    if ($existingSupplier) {
                        $supplierMap[$supplier] = $existingSupplier['id'];
                    } else {
                        $this->db->table('suplier')->insert([
                            'tenant_id' => \App\Libraries\TenantContext::id(),
                            'suplier_name' => $supplier
                        ]);
                        $supplierMap[$supplier] = $this->db->insertID();
                    }
                }
                $suplierIds[] = $supplierMap[$supplier];
            }

            $id_barang = $category['kode_awal'] . str_pad($lastId, 3, '0', STR_PAD_LEFT);

            // Data produk
            $productData = [
                'tenant_id' => \App\Libraries\TenantContext::id(),
                'id_barang' => $id_barang,
                'nama_barang' => trim($row['A']),
                'id_seri_barang' => $seriesMap[$seriesName] ?? null,
                'harga_modal' => isset($row['F']) ? (float) str_replace(',', '', $row['F']) : 0,
                'harga_jual' => isset($row['G']) ? (float) str_replace(',', '', $row['G']) : 0,
                'harga_jual_toko' => isset($row['H']) ? (float) str_replace(',', '', $row['H']) : 0,
                'suplier' => implode(',', array_filter($suplierIds)),
                'id_model_barang' => $categoryMap[$categoryName],
                'dropship' => $row['E'] === "TRUE" ? 1 : 0,
                'berat' => isset($row['I']) ? (float) $row['I'] : 0, // Assuming column I is Berat
                'created_by' => $token['user_id'],
            ];
            $dataToInsert[] = $productData;

            foreach ($storeNames as $storeName => $columnName) {
                if (isset($storeMap[$storeName])) {
                    $barang_cacat = array_key_exists($columnName, $row) && is_numeric($row[$columnName]) ? (int) $row[$columnName] : 0;
                    $stock = array_key_exists(chr(ord($columnName) - 1), $row) && is_numeric($row[chr(ord($columnName) - 1)]) ? (int) $row[chr(ord($columnName) - 1)] : 0;

                    $stockToInsert[] = [
                        'tenant_id' => \App\Libraries\TenantContext::id(),
                        'id_barang' => $id_barang,
                        'id_toko' => $storeMap[$storeName],
                        'stock' => $stock,
                        'barang_cacat' => $barang_cacat,
                    ];
                }
            }

        }
        // Insert produk
        if (!empty($dataToInsert)) {
            $this->db->table('product')->insertBatch($dataToInsert);
        } else {
            return $this->jsonResponse->error("No valid data to insert.", 400);
        }

        // Insert stok jika ada data
        if (!empty($stockToInsert)) {
            $this->db->table('stock')->insertBatch($stockToInsert);
        } else {
            return $this->jsonResponse->error("Stock data is empty.", 400);
        }

        return $this->jsonResponse->oneResp(count($dataToInsert) . ' products added successfully', [], 201);
    }
    public function moveToCacat($id = null)
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();
        $qty = (int) ($data->qty ?? 0);
        $idToko = $data->id_toko ?? null;
        $notes = $data->notes ?? 'Pindah ke Cacat';

        if ($qty <= 0 || !$idToko) {
            return $this->jsonResponse->error("Qty dan ID Toko wajib diisi", 400);
        }

        $product = $this->productModel->find($id);
        if (!$product)
            return $this->jsonResponse->error("Produk tidak ditemukan", 404);

        $stock = $this->stockModel->where('id_barang', $product['id_barang'])->where('id_toko', $idToko)->first();
        if (!$stock || $stock['stock'] < $qty) {
            return $this->jsonResponse->error("Stok normal tidak mencukupi", 400);
        }

        $this->db->transStart();
        try {
            // Update Stock
            $this->stockModel->update($stock['id'], [
                'stock' => $stock['stock'] - $qty,
                'barang_cacat' => $stock['barang_cacat'] + $qty
            ]);

            // Journal
            $cogsTotal = $product['harga_modal'] * $qty;
            if ($cogsTotal > 0) {
                $refNo = 'ADJ-' . time();
                $jid = $this->internalCreateJournal('ADJUSTMENT', $id, $refNo, date('Y-m-d'), "Normal to Cacat: {$product['nama_barang']} ({$notes})", $idToko);
                // Dr Inventory Cacat (10x5)
                $this->internalAddJournalItem($jid, '10' . $idToko . '7', $cogsTotal, 0, $idToko);
                // Cr Inventory Normal (10x4)
                $this->internalAddJournalItem($jid, '10' . $idToko . '4', 0, $cogsTotal, $idToko);
            }

            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'MOVE_TO_CACAT',
                'target_table' => 'stock',
                'target_id' => $stock['id'],
                'description' => "Pindah stock normal ke cacat: {$product['nama_barang']} Qty: {$qty}. Notes: {$notes}"
            ]);

            $this->db->transComplete();
            return $this->jsonResponse->oneResp("Berhasil memindahkan ke barang cacat", null, 200);
        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function moveToNormal($id = null)
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();
        $qty = (int) ($data->qty ?? 0);
        $idToko = $data->id_toko ?? null;
        $notes = $data->notes ?? 'Pindah ke Normal';

        if ($qty <= 0 || !$idToko) {
            return $this->jsonResponse->error("Qty dan ID Toko wajib diisi", 400);
        }

        $product = $this->productModel->find($id);
        if (!$product)
            return $this->jsonResponse->error("Produk tidak ditemukan", 404);

        $stock = $this->stockModel->where('id_barang', $product['id_barang'])->where('id_toko', $idToko)->first();
        if (!$stock || $stock['barang_cacat'] < $qty) {
            return $this->jsonResponse->error("Stok cacat tidak mencukupi", 400);
        }

        $this->db->transStart();
        try {
            $this->stockModel->update($stock['id'], [
                'stock' => $stock['stock'] + $qty,
                'barang_cacat' => $stock['barang_cacat'] - $qty
            ]);

            // Journal
            $cogsTotal = $product['harga_modal'] * $qty;
            if ($cogsTotal > 0) {
                $refNo = 'ADJ-' . time();
                $jid = $this->internalCreateJournal('ADJUSTMENT', $id, $refNo, date('Y-m-d'), "Cacat to Normal: {$product['nama_barang']} ({$notes})", $idToko);
                // Dr Inventory Normal (10x4)
                $this->internalAddJournalItem($jid, '10' . $idToko . '4', $cogsTotal, 0, $idToko);
                // Cr Inventory Cacat (10x7)
                $this->internalAddJournalItem($jid, '10' . $idToko . '7', 0, $cogsTotal, $idToko);
            }

            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'MOVE_TO_NORMAL',
                'target_table' => 'stock',
                'target_id' => $stock['id'],
                'description' => "Pindah stock cacat ke normal: {$product['nama_barang']} Qty: {$qty}. Notes: {$notes}"
            ]);

            $this->db->transComplete();
            return $this->jsonResponse->oneResp("Berhasil memindahkan ke barang normal", null, 200);
        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function writeOffCacat($id = null)
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();
        $qty = (int) ($data->qty ?? 0);
        $idToko = $data->id_toko ?? null;
        $notes = $data->notes ?? 'Pembersihan Barang Cacat (Rugi)';

        if ($qty <= 0 || !$idToko) {
            return $this->jsonResponse->error("Qty dan ID Toko wajib diisi", 400);
        }

        $product = $this->productModel->find($id);
        if (!$product)
            return $this->jsonResponse->error("Produk tidak ditemukan", 404);

        $stock = $this->stockModel->where('id_barang', $product['id_barang'])->where('id_toko', $idToko)->first();
        if (!$stock || $stock['barang_cacat'] < $qty) {
            return $this->jsonResponse->error("Stok cacat tidak mencukupi", 400);
        }

        $this->db->transStart();
        try {
            $this->stockModel->update($stock['id'], [
                'barang_cacat' => $stock['barang_cacat'] - $qty
            ]);

            // Journal: Loss on Damaged Goods
            $cogsTotal = $product['harga_modal'] * $qty;
            if ($cogsTotal > 0) {
                $refNo = 'LOSS-' . time();
                $jid = $this->internalCreateJournal('WRITE_OFF', $id, $refNo, date('Y-m-d'), "Write-off Cacat: {$product['nama_barang']} ({$notes})", $idToko);
                // Dr HPP (As requested: HPP +)
                $this->internalAddJournalItem($jid, '50' . $idToko . '1', $cogsTotal, 0, $idToko);
                // Cr Inventory Cacat (10x7)
                $this->internalAddJournalItem($jid, '10' . $idToko . '7', 0, $cogsTotal, $idToko);
            }

            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'WRITE_OFF_CACAT',
                'target_table' => 'stock',
                'target_id' => $stock['id'],
                'description' => "Write-off (anggap rugi) stock cacat: {$product['nama_barang']} Qty: {$qty}. Notes: {$notes}"
            ]);

            $this->db->transComplete();
            return $this->jsonResponse->oneResp("Berhasil melakukan write-off barang cacat", null, 200);
        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    private function internalCreateJournal($refType, $refId, $refNo, $date, $desc, $tokoId = null)
    {
        $journalModel = new \App\Models\JournalModel();
        $data = [
            'tenant_id' => TenantContext::id(),
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

    private function internalAddJournalItem($journalId, $accountCode, $debit, $credit, $tokoId = null)
    {
        $accountModel = new \App\Models\AccountModel();
        $journalItemModel = new \App\Models\JournalItemModel();

        $account = $accountModel->getByBaseCode($accountCode, $tokoId);
        if (!$account) {
            $account = $accountModel->where('code', $accountCode)->first();
        }

        if (!$account)
            return;

        $journalItemModel->insert([
            'journal_id' => $journalId,
            'account_id' => $account['id'],
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}