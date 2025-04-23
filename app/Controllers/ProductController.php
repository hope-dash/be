<?php

namespace App\Controllers;

use App\Models\ModelBarangModel;
use App\Models\ProductModel;
use App\Models\ImageModel;
use App\Models\StockModel;
use App\Models\TokoModel;
use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductController extends ResourceController
{
    protected $modelBarangModel;
    protected $productModel;
    protected $stockModel;
    protected $imageModel;
    protected $jsonResponse;
    protected $db;
    protected $modelToko;


    public function __construct()
    {
        $this->modelBarangModel = new ModelBarangModel();
        $this->imageModel = new ImageModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
        $this->modelToko = new TokoModel();
    }

    public function createProduct()
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
            'suplier.*' => 'permit_empty|integer',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $model = $this->modelBarangModel->find($data->id_model);
        if (!$model) {
            return $this->jsonResponse->error("Kategori barang tidak valid", 400);
        }

        $kodeAwal = $model['kode_awal'];

        $lastProduct = $this->productModel->orderBy('id', 'DESC')->first();
        $nextId = $lastProduct ? (int) $lastProduct['id'] + 1 : 1;
        $productId = $kodeAwal . str_pad($nextId, 3, '0', STR_PAD_LEFT);

        $productData = [
            'id_barang' => $productId,
            'nama_barang' => isset($data->nama_barang) ? $data->nama_barang : null,
            'id_seri_barang' => $data->id_seri_barang ?? null,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
            'harga_jual_toko' => $data->harga_jual_toko,
            'suplier' => !empty($data->suplier) ? implode(',', $data->suplier) : null, // Convert array to comma-separated string
            'id_model_barang' => $data->id_model,
            'notes' => $data->notes ?? null,
            "created_by" => $token['user_id'],
        ];

        $this->productModel->insert($productData);

        $stockData = [];
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

        $this->stockModel->insertBatch($stockData);

        return $this->jsonResponse->oneResp('Add ' . ($data->nama_barang ?? 'product') . ' successfully', ['id' => $nextId], 201);

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

        if (!empty($images['image'])) {
            foreach ($images['image'] as $image) {
                if ($image->isValid() && !$image->hasMoved()) {
                    $newName = $image->getRandomName();
                    $image->move(ROOTPATH . 'public/uploads/images', $newName);
                    $imagePath = 'uploads/images/' . $newName;
                    $uploadedImagePaths[] = $imagePath;

                    if (!in_array($imagePath, $existingImageUrls)) {
                        $this->imageModel->insert([
                            'type' => "product",
                            'kode' => $kode,
                            'url' => $imagePath,
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
            'suplier.*' => 'permit_empty|integer',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $model = $this->modelBarangModel->find($data->id_model);
        if (!$model) {
            return $this->jsonResponse->error("Kategori barang tidak valid", 400);
        }

        $productData = [
            'nama_barang' => isset($data->nama_barang) ? $data->nama_barang : '',
            'id_seri_barang' => $data->id_seri_barang ?? null,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
            'harga_jual_toko' => $data->harga_jual_toko,
            'notes' => $data->notes ?? null,
            "updated_by" => $token['user_id'],
        ];

        $this->productModel->update($id, $productData);

        // Update stock data
        foreach ($data->stock as $toko) {
            if (isset($toko->id) && $this->stockModel->find($toko->id)) {
                // Update existing stock
                $stockData = [
                    'stock' => $toko->stock,
                    'barang_cacat' => $toko->barang_cacat,
                    'dropship' => isset($toko->dropship) ? (int) $toko->dropship : 0,
                ];

                $this->stockModel->update($toko->id, $stockData);
            } else {
                // Prepare data for new stock entry
                $newStockData = [
                    'id_barang' => $id, // Use the product ID for new stock entry
                    'id_toko' => $toko->id_toko,
                    'stock' => $toko->stock,
                    'barang_cacat' => $toko->barang_cacat,
                    'dropship' => isset($toko->dropship) ? (int) $toko->dropship : 0,
                ];

                // Create new stock entry
                $this->stockModel->insert($newStockData);
            }
        }

        // Update suppliers if provided
        if (!empty($data->suplier)) {
            $supplierIds = implode(',', $data->suplier);
            $this->productModel->update($id, ['suplier' => $supplierIds]);
        }

        return $this->jsonResponse->oneResp('Update ' . ($data->nama_barang ?? 'product') . ' successfully', ['id' => $id], 200);
    }
    public function getDetailById($id = null)
    {
        try {
            $product = $this->productModel->find($id);
            if ($product) {
                $product = $this->productModel
                    ->select('product.*, product.id_model_barang as id_model, model_barang.nama_model, seri.seri')
                    ->join('model_barang', 'model_barang.id = product.id_model_barang') // Corrected join to use id_model_barang
                    ->join('seri', 'seri.id = product.id_seri_barang', 'left') // Ensure left join for optional data
                    ->where('product.id', $id)
                    ->first();

                if ($product) {
                    $product = (array) $product;

                    // Fetch stock data
                    $stockData = $this->productModel
                        ->select('stock.id, stock.stock, stock.id_toko, stock.barang_cacat, toko.toko_name')
                        ->join('stock', 'stock.id_barang = product.id_barang', 'left')
                        ->join('toko', 'toko.id = stock.id_toko', 'left')
                        ->where('product.id', $id)
                        ->get()
                        ->getResultArray();

                    $product['stock'] = $stockData;

                    // Fetch existing images
                    $existingImages = $this->imageModel->where('type', 'product')
                        ->where('kode', $id)
                        ->findAll();

                    $product['images'] = array_column($existingImages, 'url');

                    // Include dropship and suplier in the response
                    $product['dropship'] = (bool) $product['dropship']; // Ensure dropship is a boolean
                    $product['suplier'] = !empty($product['suplier']) ? explode(',', $product['suplier']) : []; // Convert suplier string to array

                    // Fetch supplier names
                    if (!empty($product['suplier'])) {
                        $supplierNames = $this->db->table('suplier')
                            ->select('id, suplier_name')
                            ->whereIn('id', $product['suplier'])
                            ->get()
                            ->getResultArray();

                        // Map supplier names to their IDs
                        $product['supplier_details'] = [];
                        foreach ($supplierNames as $supplier) {
                            $product['supplier_details'][] = [
                                'id' => $supplier['id'],
                                'name' => $supplier['suplier_name'],
                            ];
                        }
                    } else {
                        $product['supplier_details'] = []; // Default to empty if no suppliers
                    }

                    return $this->jsonResponse->oneResp('', $product);
                } else {
                    return $this->jsonResponse->error('Product Not Found');
                }
            } else {
                return $this->jsonResponse->error('Product Not Found');
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage());
        }
    }
    public function getAllProduct()
    {
        try {
            $sortBy = $this->request->getGet('sortBy') ?? 'product.id';
            if ($sortBy === 'kode_barang') {
                $sortBy = 'product.id_barang';
            }
            $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
            $namaProduct = $this->request->getGet('namaProduct') ?? '';
            $seri = $this->request->getGet('seri') ?? '';
            $model = $this->request->getGet('model') ?? '';
            $suplier = $this->request->getGet('suplier') ?? '';
            $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            $builder = $this->productModel
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->join('suplier', 'suplier.id IN (product.suplier)', 'left')
                ->select([
                    'product.id',
                    'product.id_barang',
                    'product.notes',
                    'product.nama_barang as nama_barang',
                    'CONCAT(product.nama_barang, " ", model_barang.nama_model, " ", COALESCE(seri.seri, "")) as nama_lengkap_barang',
                    'product.harga_modal',
                    'product.harga_jual',
                    'product.harga_jual_toko',
                    'model_barang.nama_model',
                    'COALESCE(seri.seri, "") as seri',
                    '(SELECT SUM(stock.stock) FROM stock WHERE stock.id_barang = product.id_barang) as total_stock',
                    '(SELECT SUM(stock.barang_cacat) FROM stock WHERE stock.id_barang = product.id_barang) as total_cacat',
                    'GROUP_CONCAT(suplier.suplier_name) as suplier_names' // Get all supplier names
                ]);

            // Apply filters
            if (!empty($namaProduct)) {
                $builder->groupStart()
                    ->like("CONCAT(product.nama_barang, ' ', model_barang.nama_model, ' ', seri.seri)", $namaProduct)
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }
            if (!empty($seri)) {
                $builder->like('product.id_seri_barang', $seri, 'both');
            }
            if (!empty($model)) {
                $builder->like('product.id_model_barang', $model, 'both');
            }
            if (!empty($suplier)) {
                $builder->like('product.suplier', $suplier, 'both');
            }

            // Count total data
            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            // Fetch product data with pagination
            $products = $builder
                ->groupBy('product.id')
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            // Log the SQL query for debugging
            log_message('debug', $this->productModel->getLastQuery());

            $formattedProducts = [];
            foreach ($products as $item) {
                $productId = $item['id'];

                // Initialize product data
                if (!isset($formattedProducts[$productId])) {
                    $formattedProducts[$productId] = [
                        'id' => $productId,
                        'kode_barang' => $item['id_barang'],
                        'notes' => $item['notes'],
                        'suplier' => !empty($item['suplier_names']) ? explode(',', $item['suplier_names']) : [], // Convert supplier names to array
                        'nama_barang' => $item['nama_barang'],
                        'nama_lengkap_barang' => $item['nama_lengkap_barang'],
                        'harga_modal' => $item['harga_modal'],
                        'harga_jual' => $item['harga_jual'],
                        'harga_jual_toko' => $item['harga_jual_toko'],
                        'nama_model' => $item['nama_model'],
                        'seri' => $item['seri'],
                        'stock' => [],
                        'total_stock' => 0,
                        'total_cacat' => 0,
                        'stock_string' => ''
                    ];
                }

                // Fetch stock data based on product code
                if (!empty($item['id_barang'])) {
                    $stocks = $this->stockModel
                        ->select('stock.dropship, stock.stock, stock.barang_cacat, toko.toko_name')
                        ->join('toko', 'toko.id = stock.id_toko', 'left')
                        ->where('stock.id_barang', $item['id_barang'])
                        ->findAll();

                    $stockStrings = [];
                    foreach ($stocks as $stockItem) {
                        $stockValue = (int) ($stockItem['stock'] ?? 0);
                        $barangCacat = (int) ($stockItem['barang_cacat'] ?? 0);
                        $tokoName = $stockItem['toko_name'] ?? 'Tidak diketahui';
                        $dropship = $stockItem['dropship'] === "1" ;

                        $formattedProducts[$productId]['stock'][] = [
                            'stock' => $stockValue,
                            'barang_cacat' => $barangCacat,
                            'toko_name' => $tokoName,
                            'dropship' => $dropship
                        ];

                        // Add to total stock & defective items
                        $formattedProducts[$productId]['total_stock'] += $stockValue;
                        $formattedProducts[$productId]['total_cacat'] += $barangCacat;

                        // Save for stock_string
                        $stockStrings[] = "{$tokoName}={$stockValue}";
                    }

                    // Combine stock strings
                    $formattedProducts[$productId]['stock_string'] = implode("\n", $stockStrings);

                    // Fetch existing images
                    $existingImages = $this->imageModel->where('type', 'product')
                        ->where('kode', $item['id'])
                        ->findAll();

                    $formattedProducts[$productId]['images'] = array_column($existingImages, 'url');
                }
            }

            return $this->jsonResponse->multiResp('', array_values($formattedProducts), $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    public function getProductStock()
    {
        try {
            $sortBy = $this->request->getGet('sortBy') ?? 'product.id_barang';
            $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
            $namaProduct = $this->request->getGet('namaProduct') ?? '';
            $id_toko = $this->request->getGet('id_toko') ?? '';
            $is_pricelist = $this->request->getGet('is_pricelist') ?? '';
            $is_toko = $this->request->getGet('is_toko') ?? '';
            $seri = $this->request->getGet('seri') ?? '';
            $model = $this->request->getGet('model') ?? '';
            $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            // Ambil data dari body request
            $requestBody = $this->request->getJSON(true);
            $kode_exclude = !empty($requestBody['kode_exclude']) ? $requestBody['kode_exclude'] : [];

            $builder = $this->productModel
                ->join('stock', 'stock.id_barang = product.id_barang', 'left')
                ->join('toko', 'toko.id = stock.id_toko', 'left')
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->select([
                    'product.id',
                    'product.id_barang as kode_barang',
                    'model_barang.nama_model',
                    'product.nama_barang as nama_barang',
                    'CONCAT(product.nama_barang, " ", model_barang.nama_model, " ", COALESCE(seri.seri, "")) as nama_lengkap_barang',
                    'COALESCE(seri.seri, "") as seri',
                    'stock.stock',
                    'stock.dropship',
                    'stock.barang_cacat',
                    'toko.toko_name',
                    ...($is_toko ? ["product.harga_jual_toko as harga_jual"] : ["product.harga_jual"]),
                    ...($is_pricelist ? [] : ["product.harga_modal"]),
                ]);

            // Apply filters
            if (!empty($namaProduct)) {
                $builder->groupStart()
                    ->like("CONCAT_WS(' ', product.nama_barang, model_barang.nama_model, seri.seri)", $namaProduct)
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

            // Count total data
            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            // Fetch product stock data with pagination
            $products = $builder
                ->groupStart()
                ->where('stock.stock >', 0)
                ->orWhere('stock.dropship >', 0)
                ->groupEnd()
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            foreach ($products as &$item) {
                $existingImages = $this->imageModel
                    ->where('type', 'product')
                    ->where('kode', $item['id'])
                    ->findAll();

                $item['images'] = array_column($existingImages, 'url');
            }

            return $this->jsonResponse->multiResp('', $products, $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function deleteByProductId($id)
    {
        // Start a database transaction
        $this->db->transStart();
        $query = $this->productModel->where("id", $id)
            ->first();

        if ($query) {
            $stockDeleted = $this->stockModel->delete(['id_barang' => $query['id_barang']]);
            $productDeleted = $this->productModel->delete($id);


            if ($stockDeleted && $productDeleted) {
                $this->db->transComplete();
                return $this->jsonResponse->oneResp("Data Deleted", "", 200);
            } else {

                $this->db->transRollback();
                return $this->jsonResponse->error("Failed to delete data", 500);
            }
        } else {
            return $this->jsonResponse->error("Product Not Found", 404);
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
        $storeQuery = $this->db->table('toko')->whereIn('toko_name', array_keys($storeNames))->get();
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

        $lastProduct = $this->db->table('product')->select('id')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $lastId = $lastProduct ? (int) preg_replace('/[^0-9]/', '', $lastProduct['id']) : 0;

        foreach ($excelData as $row) {
            $lastId++;
            $categoryName = trim($row['B'] ?? '');
            if (empty($categoryName))
                continue;

            // Cek kategori
            if (!isset($categoryMap[$categoryName])) {
                $category = $this->db->table('model_barang')->where('LOWER(TRIM(nama_model))', strtolower($categoryName))->get()->getRowArray();
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
                $series = $this->db->table('seri')->where('seri', $seriesName)->get()->getRowArray();
                if ($series) {
                    $seriesMap[$seriesName] = $series['id'];
                } else {
                    $this->db->table('seri')->insert(['seri' => $seriesName]);
                    $seriesMap[$seriesName] = $this->db->insertID();
                }
            }

            // Cek supplier
            $supplierList = array_filter(array_map('trim', explode(',', $row['D'] ?? '')));
            $suplierIds = [];
            foreach ($supplierList as $supplier) {
                if (!isset($supplierMap[$supplier])) {
                    $existingSupplier = $this->db->table('suplier')->where('suplier_name', $supplier)->get()->getRowArray();
                    if ($existingSupplier) {
                        $supplierMap[$supplier] = $existingSupplier['id'];
                    } else {
                        $this->db->table('suplier')->insert(['suplier_name' => $supplier]);
                        $supplierMap[$supplier] = $this->db->insertID();
                    }
                }
                $suplierIds[] = $supplierMap[$supplier];
            }

            $id_barang = $category['kode_awal'] . str_pad($lastId, 3, '0', STR_PAD_LEFT);

            // Data produk
            $productMap[trim($row['A'])] = [
                'id_barang' => $id_barang,
                'nama_barang' => trim($row['A']),
                'id_seri_barang' => $seriesMap[$seriesName] ?? null,
                'harga_modal' => isset($row['F']) ? (float) str_replace(',', '', $row['F']) : 0,
                'harga_jual' => isset($row['G']) ? (float) str_replace(',', '', $row['G']) : 0,
                'harga_jual_toko' => isset($row['H']) ? (float) str_replace(',', '', $row['H']) : 0,
                'suplier' => implode(',', array_filter($suplierIds)),
                'id_model_barang' => $categoryMap[$categoryName],
                'dropship' => $row['E'] === "TRUE" ? 1 : 0,
                'created_by' => $token['user_id'],
            ];
            $dataToInsert[] = $productMap[trim($row['A'])];

            foreach ($storeNames as $storeName => $columnName) {
                if (isset($storeMap[$storeName])) {
                    $barang_cacat = array_key_exists($columnName, $row) && is_numeric($row[$columnName]) ? (int) $row[$columnName] : 0;
                    $stock = array_key_exists(chr(ord($columnName) - 1), $row) && is_numeric($row[chr(ord($columnName) - 1)]) ? (int) $row[chr(ord($columnName) - 1)] : 0;

                    $stockToInsert[] = [
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
}
