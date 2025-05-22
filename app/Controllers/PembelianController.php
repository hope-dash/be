<?php

namespace App\Controllers;

use App\Models\CashflowModel;
use App\Models\JsonResponse;
use App\Models\PembelianModel;
use App\Models\PembelianDetailModel;
use App\Models\PembelianBiayaModel;
use App\Models\ProductModel;
use App\Models\StockModel;
use CodeIgniter\RESTful\ResourceController;

class PembelianController extends ResourceController
{
    protected $format = 'json';
    protected $pembelianModel;
    protected $pembelianDetailModel;
    protected $pembelianBiayaModel;
    protected $productModel;
    protected $stockModel;
    protected $db;
    protected $jsonResponse;
    protected $CashflowModel;
    public function __construct()
    {
        helper('log');
        $this->jsonResponse = new JsonResponse();
        $this->pembelianModel = new PembelianModel();
        $this->pembelianDetailModel = new PembelianDetailModel();
        $this->pembelianBiayaModel = new PembelianBiayaModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->CashflowModel = new CashflowModel();
        $this->db = \Config\Database::connect();
    }

    public function create()
    {
        $request = $this->request->getJSON(true);
        $token = $this->request->user;

        if (empty($request['tanggal_belanja']) || empty($request['detail']) || empty($request['id_toko'])) {
            return $this->failValidationError('tanggal_belanja, detail, dan id_toko wajib diisi');
        }

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $totalJumlahItem = array_sum(array_column($request['detail'], 'jumlah'));

            $totalBiayaLain = 0;
            if (!empty($request['biaya'])) {
                foreach ($request['biaya'] as $biaya) {
                    $totalBiayaLain += floatval($biaya['jumlah']);
                }
            }

            $biayaTambahanPerItem = $totalJumlahItem > 0 ? $totalBiayaLain / $totalJumlahItem : 0;

            $totalBelanjaDetail = 0;
            foreach ($request['detail'] as $item) {
                $totalBelanjaDetail += floatval($item['harga_satuan']) * intval($item['jumlah']);
            }
            $totalBelanja = $totalBelanjaDetail + $totalBiayaLain;

            $pembelianData = [
                'tanggal_belanja' => $request['tanggal_belanja'],
                'supplier_id' => $request['supplier_id'] ?? null,
                'id_toko' => $request['id_toko'] ?? null,
                'total_belanja' => $totalBelanja,
                'catatan' => $request['catatan'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                "created_by" => $token['user_id'],
            ];

            $pembelianId = $this->pembelianModel->insert($pembelianData);
            if (!$pembelianId)
                throw new \Exception('Gagal menyimpan data pembelian');

            if (!empty($request['biaya'])) {
                foreach ($request['biaya'] as $biaya) {
                    $biayaInsert = $this->pembelianBiayaModel->insert([
                        'pembelian_id' => $pembelianId,
                        'nama_biaya' => $biaya['nama_biaya'],
                        'jumlah' => $biaya['jumlah'],
                    ]);
                    if (!$biayaInsert)
                        throw new \Exception('Gagal menyimpan biaya pembelian');
                }
            }

            foreach ($request['detail'] as $item) {
                $kodeBarang = $item['kode_barang'];
                $jumlahBaru = intval($item['jumlah']);
                $hargaSatuan = floatval($item['harga_satuan']);
                $hargaSatuanFinal = $hargaSatuan + $biayaTambahanPerItem;

                $stock = $this->stockModel->where('id_barang', $kodeBarang)
                    ->where('id_toko', $request['id_toko'])
                    ->first();
                $stokLama = $stock['stock'] ?? 0;

                $product = $this->productModel->where('id_barang', $kodeBarang)->first();

                if (!$product)
                    throw new \Exception("Produk dengan kode {$kodeBarang} tidak ditemukan");

                $hargaModalLama = floatval($product['harga_modal']);
                $stokTotal = $stokLama + $jumlahBaru;
                if ($stokTotal == 0)
                    $stokTotal = 1;

                $hargaModalBaru = (($hargaModalLama * $stokLama) + ($hargaSatuanFinal * $jumlahBaru)) / $stokTotal;

                if (!$this->productModel->update($product['id'], ['harga_modal' => $hargaModalBaru])) {
                    throw new \Exception('Gagal update harga modal');
                }


                if ($stock) {
                    if (!$this->stockModel->update($stock['id'], ['stock' => $stokLama + $jumlahBaru])) {
                        throw new \Exception('Gagal update stok');
                    }
                }

                if (
                    !$this->pembelianDetailModel->insert([
                        'pembelian_id' => $pembelianId,
                        'kode_barang' => $kodeBarang,
                        'jumlah' => $jumlahBaru,
                        'harga_satuan' => $hargaSatuanFinal,
                        'total_harga' => $hargaSatuanFinal * $jumlahBaru,
                    ])
                ) {
                    throw new \Exception('Gagal simpan detail pembelian');
                }
            }
            // Insert ke tabel cashflow
            $db->table('cashflow')->insert([
                'debit' => 0,
                'credit' => $totalBelanja,
                'noted' => "Belanja ID " . $pembelianId . " pada tanggal " . date('Y-m-d'),
                'type' => 'Belanja',
                'status' => 'SUCCESS',
                'date_time' => date('Y-m-d H:i:s'),
                'id_toko' => $request['id_toko']
            ]);

            // Commit transaksi
            $db->transCommit();

            // Logging aktivitas
            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'UPDATE',
                'target_table' => 'product',
                'target_id' => $product['id'],
                'description' => 'Belanja pada tanggal ' . date('Y-m-d') .
                    '. Melakukan perubahan data pada harga modal dari ' . $hargaModalLama .
                    ' menjadi ' . $hargaModalBaru . '. Stock awal ' . $stokLama .
                    ' menjadi ' . ($stokLama + $jumlahBaru),
            ]);

            return $this->jsonResponse->oneResp(
                'Pembelian berhasil disimpan',
                [
                    'pembelian_id' => $pembelianId,
                    'total_belanja' => $totalBelanja,
                ],
                201
            );
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->failServerError('Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function listPembelian()
    {
        $id_toko = $this->request->getGet('id_toko');
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $page = (int) $this->request->getGet('page') ?: 1;
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $offset = ($page - 1) * $limit;

        $builder = $this->db->table('pembelian');
        $builder->select('pembelian.*, toko.toko_name, suplier.suplier_name');
        $builder->join('toko', 'toko.id = pembelian.id_toko', 'left');
        $builder->join('suplier', 'suplier.id = pembelian.supplier_id', 'left');

        // FILTER
        if ($id_toko) {
            $builder->where('pembelian.id_toko', $id_toko);
        }

        if ($date_start && $date_end) {
            $builder->where("DATE(pembelian.created_at) BETWEEN '{$date_start}' AND '{$date_end}'");
        }

        // Clone for total count
        $totalBuilder = clone $builder;
        $total_data = $totalBuilder->countAllResults(false); // false: agar tidak reset main builder

        // Pagination & Sorting
        $builder->orderBy('pembelian.created_at', 'DESC');
        $builder->limit($limit, $offset);

        $result = $builder->get()->getResultArray();
        $total_page = ceil($total_data / $limit);

        return $this->jsonResponse->multiResp(
            'Success',
            array_values($result),
            $total_data,
            $total_page,
            $page,
            $limit,
            200
        );
    }


    public function getPembelianById($id)
    {
        $pembelian = $this->db->table('pembelian')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$pembelian) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Data pembelian tidak ditemukan'
            ])->setStatusCode(404);
        }

        $detail = $this->db->table('pembelian_detail pd')
            ->select('
                pd.*,
                p.nama_barang,
                p.id_barang,
                CONCAT(
                    COALESCE(p.nama_barang, ""),
                    " ",
                    COALESCE(model_barang.nama_model, ""),
                    " ",
                    COALESCE(seri.seri, "")
                ) as nama_lengkap_barang
            ')
            ->join('product p', 'p.id_barang = pd.kode_barang', 'left')
            ->join('model_barang', 'model_barang.id = p.id_model_barang', 'left')
            ->join('seri', 'seri.id = p.id_seri_barang', 'left')
            ->where('pd.pembelian_id', $id)
            ->get()
            ->getResultArray();

        $biaya = $this->db->table('pembelian_biaya')
            ->where('pembelian_id', $id)
            ->get()
            ->getResultArray();

        return $this->jsonResponse->oneResp('', [
            'pembelian' => $pembelian,
            'detail' => $detail,
            'biaya' => $biaya
        ], 200);



    }


}
