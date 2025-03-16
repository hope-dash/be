<?php

namespace App\Controllers;

use App\Models\ModelBarangModel;
use App\Models\ProductModel;
use App\Models\StockModel;
use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;

class ProductController extends ResourceController
{
    protected $modelBarangModel;
    protected $productModel;
    protected $stockModel;
    protected $jsonResponse;
    protected $db;


    public function __construct()
    {
        $this->modelBarangModel = new ModelBarangModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
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
            'notes' => 'permit_empty',
            'id_seri_barang' => 'permit_empty',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $model = $this->modelBarangModel->find($data->id_model);
        if (!$model) {
            return $this->jsonResponse->error("Type not Valid", 400);
        }

        $kodeAwal = $model['kode_awal'];

        $lastProduct = $this->productModel->orderBy('id', 'DESC')->first();
        $nextId = $lastProduct ? (int) $lastProduct['id'] + 1 : 1;
        $productId = $kodeAwal . str_pad($nextId, 3, '0', STR_PAD_LEFT);

        $productData = [
            'id_barang' => $productId,
            'nama_barang' => $data->nama_barang,
            'id_seri_barang' => $data->id_seri_barang ?? null,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
            'suplier' => $data->suplier,
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
                ];
            }
        }

        $this->stockModel->insertBatch($stockData);
        return $this->jsonResponse->oneResp('Add ' . $data->nama_barang . ' successfully', ['id' => $productId], 201);
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
            'id_seri_barang' => 'permit_empty', // Ubah di sini
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $model = $this->modelBarangModel->find($data->id_model);
        if (!$model) {
            return $this->jsonResponse->error("Type not Valid", 400);
        }

        $productData = [
            'nama_barang' => $data->nama_barang,
            'id_seri_barang' => $data->id_seri_barang ?? null,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
            'notes' => $data->notes ?? null,
            "updated_by" => $token['user_id'],
        ];

        $this->productModel->update($id, row: $productData);

        foreach ($data->stock as $toko) {
            if (isset($toko->id) && $this->stockModel->find($toko->id)) {
                // Update existing stock
                $stockData = [
                    'stock' => $toko->stock,
                    'barang_cacat' => $toko->barang_cacat,
                ];

                $this->stockModel->update($toko->id, $stockData);
            } else {
                // Prepare data for new stock entry
                $newStockData = [
                    'id_barang' => $data->id_barang,
                    'id_toko' => $toko->id_toko,
                    'stock' => $toko->stock,
                    'barang_cacat' => $toko->barang_cacat,
                ];

                // Create new stock entry
                $this->stockModel->insert($newStockData);
            }
        }
        return $this->jsonResponse->oneResp('Update ' . $data->nama_barang . ' successfully', ['id' => $id], 201);
    }

    public function getDetailById($id = null)
    {
        try {
            $product = $this->productModel->find($id);
            if ($product) {
                $product = $this->productModel
                    ->select('product.*, product.id_model_barang as id_model,  model_barang.nama_model, seri.seri')
                    ->join('model_barang', 'model_barang.id = product.id_seri_barang')
                    ->join('seri', 'seri.id = product.id_seri_barang')
                    ->where('product.id', $id)
                    ->first();

                if ($product) {
                    $product = (array) $product;
                    $stockData = $this->productModel
                        ->select('stock.id, stock.stock,stock.id_toko, stock.barang_cacat, toko.toko_name')
                        ->join('stock', 'stock.id_barang = product.id_barang', 'left')
                        ->join('toko', 'toko.id = stock.id_toko', 'left')
                        ->where('product.id', $id)
                        ->get()
                        ->getResultArray();

                    $product['stock'] = $stockData;

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
                ->join('suplier', 'suplier.id = product.suplier', 'left') // Join with suplier table
                ->select([
                    'product.id',
                    'product.id_barang',
                    'product.suplier',
                    'suplier.suplier_name', // Select supplier name
                    'product.nama_barang as nama_barang',
                    'CONCAT(product.nama_barang, " ", model_barang.nama_model, " ", seri.seri) as nama_lengkap_barang',
                    'product.harga_modal',
                    'product.harga_jual',
                    'model_barang.nama_model',
                    'seri.seri',
                    '(SELECT SUM(stock.stock) FROM stock WHERE stock.id_barang = product.id_barang) as total_stock',
                    '(SELECT SUM(stock.barang_cacat) FROM stock WHERE stock.id_barang = product.id_barang) as total_cacat'
                ]);

            // Penerapan filter
            if (!empty($namaProduct)) {
                $builder->like('CONCAT(product.nama_barang, " ", model_barang.nama_model, " ", seri.seri)', $namaProduct, 'both');
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

            // Hitung total data
            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            // Ambil data produk dengan paginasi
            $products = $builder
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            $formattedProducts = [];
            foreach ($products as $item) {
                $productId = $item['id'];

                // Inisialisasi data produk
                if (!isset($formattedProducts[$productId])) {
                    $formattedProducts[$productId] = [
                        'id' => $productId,
                        'kode_barang' => $item['id_barang'],
                        'suplier' => $item['suplier_name'], // Use supplier name from the join
                        'nama_barang' => $item['nama_barang'],
                        'nama_lengkap_barang' => $item['nama_lengkap_barang'],
                        'harga_modal' => $item['harga_modal'],
                        'harga_jual' => $item['harga_jual'],
                        'nama_model' => $item['nama_model'],
                        'seri' => $item['seri'],
                        'stock' => [],
                        'total_stock' => 0,
                        'total_cacat' => 0,
                        'stock_string' => ''
                    ];
                }

                // Ambil data stok berdasarkan kode barang
                if (!empty($item['id_barang'])) {
                    $stocks = $this->stockModel
                        ->select('stock.stock, stock.barang_cacat, toko.toko_name')
                        ->join('toko', 'toko.id = stock.id_toko', 'left')
                        ->where('stock.id_barang', $item['id_barang'])
                        ->findAll();

                    $stockStrings = [];
                    foreach ($stocks as $stockItem) {
                        $stockValue = (int) ($stockItem['stock'] ?? 0);
                        $barangCacat = (int) ($stockItem['barang_cacat'] ?? 0);
                        $tokoName = $stockItem['toko_name'] ?? 'Tidak diketahui';

                        $formattedProducts[$productId]['stock'][] = [
                            'stock' => $stockValue,
                            'barang_cacat' => $barangCacat,
                            'toko_name' => $tokoName
                        ];

                        // Tambahkan ke total stock & barang cacat
                        $formattedProducts[$productId]['total_stock'] += $stockValue;
                        $formattedProducts[$productId]['total_cacat'] += $barangCacat;

                        // Simpan untuk stock_string
                        $stockStrings[] = "{$tokoName}={$stockValue}";
                    }

                    // Gabungkan string stok dalam satu langkah
                    $formattedProducts[$productId]['stock_string'] = implode("\n", $stockStrings);
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
            $seri = $this->request->getGet('seri') ?? '';
            $model = $this->request->getGet('model') ?? '';
            $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;
            $requestData = $this->request->getJSON(true);
            $kode_exclude = $requestData['kode_exclude'] ?? [];

            $builder = $this->stockModel
            ->join('product', 'stock.id_barang = product.id_barang', 'left')
            ->join('model_barang', 'product.id_model_barang = model_barang.id', 'left')
            ->join('seri', 'product.id_seri_barang = seri.id', 'left')
            ->select([
                'stock.id_toko',
                'stock.stock',
                'product.id_barang as kode_barang',
                "product.harga_jual",
                "model_barang.nama_model",
                "seri.seri",
                ...($is_pricelist ? [] : ["product.harga_modal"]),
                "CONCAT(product.nama_barang, ' ', model_barang.nama_model, ' ', seri.seri) AS nama_lengkap_barang"
            ])
            ->where('stock.stock >', 0);

            if (!empty($id_toko)) {
                $builder->where('stock.id_toko', $id_toko);
            }


            if (!empty($namaProduct)) {
                $builder->groupStart()
                    ->like("product.nama_barang", $namaProduct)
                    ->orLike("model_barang.nama_model", $namaProduct)
                    ->orLike("seri.seri", $namaProduct)
                    ->groupEnd();
            }

            // Filter berdasarkan seri dan model
            if (!empty($seri)) {
                $builder->where('product.id_seri_barang', $seri);
            }
            if (!empty($model)) {
                $builder->where('product.id_model_barang', $model);
            }

            if (!empty($kode_exclude) && is_array($kode_exclude)) {
                $builder->whereNotIn('product.id_barang', $kode_exclude);
            }

            // Hitung total data sebelum paginasi
            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            // Ambil data dengan paginasi
            $products = $builder
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            return $this->jsonResponse->multiResp('', array_values($products), $total_data, $total_page, $page, $limit, 200);
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


}
