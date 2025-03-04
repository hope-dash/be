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


    public function __construct()
    {
        $this->modelBarangModel = new ModelBarangModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->jsonResponse = new JsonResponse();
    }

    public function createProduct()
    {
        $data = $this->request->getJSON();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'id_model' => 'required',
            'nama_barang' => 'required',
            'id_seri_barang' => 'required',
            'harga_modal' => 'required',
            'harga_jual' => 'required',
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
            'id_seri_barang' => $data->id_seri_barang,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
        ];

        $this->productModel->insert($productData);

        $stockData = [];
        foreach ($data->stock as $toko) {
            $stockData[] = [
                'id_barang' => $productId,
                'id_toko' => $toko->id_toko,
                'stock' => $toko->stock,
                'barang_cacat' => $toko->barang_cacat,
            ];
        }

        // Insert stock data
        $this->stockModel->insertBatch($stockData);
        return $this->jsonResponse->oneResp('Add ' . $data->nama_barang . ' successfully', ['id' => $productId], 201);
    }

    public function updateProduct($id = null)
    {
        $data = $this->request->getJSON();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'id_model' => 'required',
            'nama_barang' => 'required',
            'id_seri_barang' => 'required',
            'harga_modal' => 'required',
            'harga_jual' => 'required',
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
            'id_seri_barang' => $data->id_seri_barang,
            'harga_modal' => $data->harga_modal,
            'harga_jual' => $data->harga_jual,
        ];

        $this->productModel->update($id, row: $productData);

        foreach ($data->stock as $toko) {

            $stockData = [
                'stock' => $toko->stock,
                'barang_cacat' => $toko->barang_cacat,
            ];
            $this->stockModel->update($toko->id_toko, $stockData);
        }

        return $this->jsonResponse->oneResp('Update ' . $data->nama_barang . ' successfully', ['id' => $id], 201);
    }

    public function getDetailById($id = null)
    {
        try {
            $product = $this->productModel->find($id);
            if ($product) {
                $product = $this->productModel
                    ->select('product.*, model_barang.nama_model, seri.seri')
                    ->join('model_barang', 'model_barang.id = product.id_seri_barang')
                    ->join('seri', 'seri.id = product.id_seri_barang')
                    ->where('product.id', $id)
                    ->first();

                if ($product) {
                    $product = (array) $product;
                    $stockData = $this->productModel
                        ->select('stock.id, stock.stock, stock.barang_cacat, toko.toko_name')
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
            $namaSeri = $this->request->getGet('namaSeri') ?? '';
            $namaModel = $this->request->getGet('namaModel') ?? '';
            $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
            $page = max((int) ($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            // Base Query for Filtering
            $builder = $this->productModel
                ->join('model_barang', 'model_barang.id = product.id_seri_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left');

            if (!empty($namaProduct)) {
                $builder->like('product.nama_barang', $namaProduct, 'both');
            }

            if (!empty($namaModel)) {
                $builder->like('model_barang.nama_model', $namaModel, 'both');
            }

            if (!empty($namaSeri)) {
                $builder->like('seri.seri', $namaSeri, 'both');
            }

            // Count total data first before applying limit
            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            // Now, fetch paginated data
            $products = $builder
                ->select('
                product.id,product.id_barang, product.nama_barang, product.harga_modal, product.harga_jual,
                model_barang.nama_model, seri.seri,
                stock.stock, stock.barang_cacat, toko.toko_name
            ')
                ->join('stock', 'stock.id_barang = product.id_barang', 'left')
                ->join('toko', 'toko.id = stock.id_toko', 'left')
                ->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            // Format Data
            $formattedProducts = [];
            foreach ($products as $item) {
                $productId = $item['id'];

                // Inisialisasi produk jika belum ada
                if (!isset($formattedProducts[$productId])) {
                    $formattedProducts[$productId] = [
                        'id' => $productId,
                        'kode_barang' => $item['id_barang'],
                        'nama_barang' => $item['nama_barang'],
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

                // Jika toko memiliki stok, tambahkan ke daftar stok
                if ($item['toko_name'] !== null) {
                    $formattedProducts[$productId]['stock'][] = [
                        'stock' => (int) $item['stock'],
                        'barang_cacat' => (int) $item['barang_cacat'],
                        'toko_name' => $item['toko_name']
                    ];

                    // Tambahkan ke total stock & barang cacat
                    $formattedProducts[$productId]['total_stock'] += (int) $item['stock'];
                    $formattedProducts[$productId]['total_cacat'] += (int) $item['barang_cacat'];
                }
            }

            // Konversi stock menjadi string
            foreach ($formattedProducts as &$product) {
                if (!empty($product['stock'])) {
                    $product['stock_string'] = implode("\n", array_map(function ($item) {
                        return "{$item['toko_name']}={$item['stock']}";
                    }, $product['stock']));
                }
            }


            return $this->jsonResponse->multiResp('', array_values($formattedProducts), $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

}
