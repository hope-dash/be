<?php

namespace App\Controllers;

use App\Models\CashflowModel;
use App\Models\JsonResponse;
use App\Models\PembelianModel;
use App\Models\PembelianDetailModel;
use App\Models\PembelianBiayaModel;
use App\Models\ProductModel;
use App\Models\StockModel;
use App\Models\AccountModel;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
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
    protected $accountModel;
    protected $journalModel;
    protected $journalItemModel;

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
        $this->accountModel = new AccountModel();
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->db = \Config\Database::connect();
    }

    public function createPembelian()
    {
        $request = $this->request->getJSON(true);
        $token = $this->request->user;

        // Validasi input dasar
        if (empty($request['tanggal_belanja']) || empty($request['detail']) || !is_array($request['detail']) || empty($request['id_toko'])) {
            return $this->jsonResponse->error('tanggal_belanja, detail (harus array), dan id_toko wajib diisi.', 400);
        }

        foreach ($request['detail'] as $key => $item) {
            if (empty($item['kode_barang']) || !isset($item['jumlah']) || !isset($item['harga_satuan'])) {
                return $this->jsonResponse->error("Item detail ke-" . ($key + 1) . ": kode_barang, jumlah, dan harga_satuan wajib diisi.", 400);
            }
            if (intval($item['jumlah']) <= 0) {
                return $this->jsonResponse->error('Item detail ke-" . ($key + 1) . ": jumlah harus lebih besar dari 0.', 400);
            }
        }
        if (!empty($request['biaya']) && !is_array($request['biaya'])) {
            return $this->jsonResponse->error('Biaya harus berupa array jika diisi.', 400);
        }


        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $totalBelanjaDariDetail = 0;
            foreach ($request['detail'] as $item) {
                $hargaSatuanItem = floatval($item['harga_satuan']);
                $ongkirItem = isset($item['ongkir']) ? floatval($item['ongkir']) : 0;
                $jumlahItem = intval($item['jumlah']);
                $totalBelanjaDariDetail += ($hargaSatuanItem + $ongkirItem) * $jumlahItem;
            }

            $totalBiayaLain = 0;
            if (!empty($request['biaya'])) {
                foreach ($request['biaya'] as $biaya) {
                    if (empty($biaya['nama_biaya']) || !isset($biaya['jumlah'])) {
                        throw new \Exception("Setiap biaya tambahan harus memiliki nama_biaya dan jumlah.");
                    }
                    $totalBiayaLain += floatval($biaya['jumlah']);
                }
            }

            $totalBelanjaKeseluruhan = $totalBelanjaDariDetail + $totalBiayaLain;

            $pembelianData = [
                'tanggal_belanja' => $request['tanggal_belanja'],
                'supplier_id' => $request['supplier_id'] ?? null,
                'id_toko' => $request['id_toko'],
                'total_belanja' => $totalBelanjaKeseluruhan,
                'catatan' => $request['catatan'] ?? null,
                'status' => 'REVIEW', // Status default
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $token['user_id'] ?? null,
            ];

            $pembelianId = $this->pembelianModel->insert($pembelianData);
            if (!$pembelianId) {
                $errors = $this->pembelianModel->errors();
                throw new \Exception('Gagal menyimpan data pembelian: ' . implode(', ', $errors ?: []));
            }

            // Simpan detail pembelian
            foreach ($request['detail'] as $item) {
                $hargaSatuanItem = floatval($item['harga_satuan']);
                $ongkirItem = isset($item['ongkir']) ? floatval($item['ongkir']) : 0;
                $jumlahItem = intval($item['jumlah']);

                $detailData = [
                    'pembelian_id' => $pembelianId,
                    'kode_barang' => $item['kode_barang'],
                    'jumlah' => $jumlahItem,
                    'harga_satuan' => $hargaSatuanItem,
                    'ongkir' => $ongkirItem,
                    'total_harga' => ($hargaSatuanItem + $ongkirItem) * $jumlahItem,
                ];
                if (!$this->pembelianDetailModel->insert($detailData)) {
                    $errors = $this->pembelianDetailModel->errors();
                    throw new \Exception('Gagal menyimpan detail pembelian: ' . implode(', ', $errors ?: []));
                }
            }

            // Simpan biaya tambahan jika ada
            if (!empty($request['biaya'])) {
                foreach ($request['biaya'] as $biaya) {
                    $biayaInsert = $this->pembelianBiayaModel->insert([
                        'pembelian_id' => $pembelianId,
                        'nama_biaya' => $biaya['nama_biaya'],
                        'jumlah' => floatval($biaya['jumlah']),
                    ]);
                    if (!$biayaInsert) {
                        $errors = $this->pembelianBiayaModel->errors();
                        throw new \Exception('Gagal menyimpan biaya pembelian tambahan: ' . implode(', ', $errors ?: []));
                    }
                }
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->jsonResponse->error('Gagal menyimpan pembelian karena transaksi database.', 400);
            }

            $db->transCommit();

            return $this->jsonResponse->oneResp(
                'Pembelian berhasil disimpan',
                [
                    'pembelian_id' => $pembelianId,
                ],
                201
            );

        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ERROR CREATE PEMBELIAN] ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString());
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function cancelPembelian($pembelianId = null)
    {
        if ($pembelianId === null) {
            return $this->jsonResponse->error('ID Pembelian wajib diisi.', 400);
        }

        $pembelian = $this->pembelianModel->find($pembelianId);
        if (!$pembelian) {
            return $this->jsonResponse->error('Data pembelian tidak ditemukan.', 400);
        }


        if ($pembelian['status'] !== 'REVIEW') {
            return $this->jsonResponse->error('Pembelian ini tidak dapat dibatalkan (status saat ini: ' . $pembelian['status'] . ').', 400);
        }

        $token = $this->request->user;

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $updateData = [
                'status' => 'CANCEL',
                'updated_at' => date('Y-m-d H:i:s'),

            ];

            if (!$this->pembelianModel->update($pembelianId, $updateData)) {
                $errors = $this->pembelianModel->errors();
                throw new \Exception('Gagal mengubah status pembelian menjadi CANCEL: ' . implode(', ', $errors ?: []));
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->jsonResponse->error('Gagal membatalkan pembelian karena transaksi database.', 400);
            }

            $db->transCommit();

            return $this->jsonResponse->oneResp(
                'Pembelian berhasil dibatalkan',
                [
                    'pembelian_id' => $pembelianId,
                ],
                200
            );

        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ERROR CANCEL PEMBELIAN] ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString());
            return $this->jsonResponse->error('Terjadi kesalahan internal saat membatalkan pembelian: ' . $e->getMessage(), 400);
        }
    }
    public function executePembelian($pembelianId = null)
    {
        if ($pembelianId === null) {
            return $this->jsonResponse->error('ID Pembelian wajib diisi.', 400);
        }

        $token = $this->request->user;
        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $pembelian = $this->pembelianModel->find($pembelianId);
            if (!$pembelian) {
                throw new \Exception('Data pembelian tidak ditemukan.');
            }

            if ($pembelian['status'] !== 'REVIEW') {
                throw new \Exception('Hanya pembelian dengan status REVIEW yang dapat dieksekusi. Status saat ini: ' . $pembelian['status']);
            }

            $idToko = $pembelian['id_toko'];
            $pembelianDetails = $this->pembelianDetailModel->where('pembelian_id', $pembelianId)->findAll();
            if (empty($pembelianDetails)) {
                throw new \Exception('Detail pembelian tidak ditemukan untuk pembelian ID: ' . $pembelianId);
            }

            $pembelianBiayas = $this->pembelianBiayaModel->where('pembelian_id', $pembelianId)->findAll();

            $totalBiayaTambahanDariTabel = 0;
            if (!empty($pembelianBiayas)) {
                foreach ($pembelianBiayas as $biaya) {
                    $totalBiayaTambahanDariTabel += floatval($biaya['jumlah']);
                }
            }

            $totalJumlahSemuaItem = 0;
            foreach ($pembelianDetails as $item) {
                $totalJumlahSemuaItem += intval($item['jumlah']);
            }

            $biayaTambahanPerUnitItem = $totalJumlahSemuaItem > 0 ? $totalBiayaTambahanDariTabel / $totalJumlahSemuaItem : 0;

            foreach ($pembelianDetails as $item) {
                $kodeBarang = $item['kode_barang'];
                $jumlahBeli = intval($item['jumlah']);
                $hargaSatuanAsli = floatval($item['harga_satuan']);
                $ongkirPerItem = floatval($item['ongkir']);

                // Harga satuan item setelah ditambah ongkir spesifiknya dan biaya tambahan terdistribusi
                // Ini adalah harga modal per unit untuk item ini dari pembelian ini
                $hargaModalSatuanItemIni = $hargaSatuanAsli + $ongkirPerItem + $biayaTambahanPerUnitItem;

                $product = $this->productModel->where('id_barang', $kodeBarang)->first();
                if (!$product) {
                    throw new \Exception("Produk dengan kode {$kodeBarang} tidak ditemukan.");
                }
                $productId = $product['id'];
                $hargaModalLama = floatval($product['harga_modal']);

                $stock = $this->stockModel->where('id_barang', $kodeBarang)
                    ->where('id_toko', $idToko)
                    ->first();
                $stokLama = $stock ? intval($stock['stock']) : 0;
                $stokBaru = $stokLama + $jumlahBeli;

                $stokTotalSetelahBeli = $stokLama + $jumlahBeli;
                $hargaModalBaru = 0;
                if ($stokTotalSetelahBeli > 0) {
                    $calculatedHargaModalBaru = (($hargaModalLama * $stokLama) + ($hargaModalSatuanItemIni * $jumlahBeli)) / $stokTotalSetelahBeli;
                    $hargaModalBaru = round($calculatedHargaModalBaru);
                } else {
                    $hargaModalBaru = round($hargaModalSatuanItemIni);
                }


                // Update harga modal di tabel produk
                if (!$this->productModel->update($productId, ['harga_modal' => $hargaModalBaru])) {
                    $errors = $this->productModel->errors();
                    throw new \Exception('Gagal update harga modal produk ' . $kodeBarang . ': ' . implode(', ', $errors ?: []));
                }

                // Update atau insert stock
                if ($stock) {
                    if (!$this->stockModel->update($stock['id'], ['stock' => $stokBaru])) {
                        $errors = $this->stockModel->errors();
                        throw new \Exception('Gagal update stok produk ' . $kodeBarang . ': ' . implode(', ', $errors ?: []));
                    }
                } else {
                    $newStockData = [
                        'id_barang' => $kodeBarang,
                        'id_toko' => $idToko,
                        'stock' => $jumlahBeli,

                    ];
                    if (!$this->stockModel->insert($newStockData)) {
                        $errors = $this->stockModel->errors();
                        throw new \Exception('Gagal insert stok baru untuk produk ' . $kodeBarang . ': ' . implode(', ', $errors ?: []));
                    }
                }
                log_aktivitas([
                    'user_id' => $token['user_id'],
                    'action_type' => 'UPDATE',
                    'target_table' => 'product',
                    'target_id' => $productId,
                    'description' => 'Belanja pada tanggal ' . date('Y-m-d') .
                        " Produk {$kodeBarang}: modal {$hargaModalLama} -> {$hargaModalBaru}, stok {$stokLama} -> {$stokBaru}."
                ]);

            }

            $cashflowData = [
                'debit' => 0,
                'credit' => $pembelian['total_belanja'],
                'noted' => "Belanja ID " . $pembelianId . " pada tanggal " . date('Y-m-d'),
                'type' => 'Belanja',
                'status' => 'SUCCESS',
                'date_time' => date('Y-m-d H:i:s'),
                'id_toko' => $idToko,

            ];
            // Jika menggunakan model: $this->cashflowModel->insert($cashflowData)
            if (!$db->table('cashflow')->insert($cashflowData)) {
                throw new \Exception('Gagal mencatat transaksi ke cashflow.');
            }

            // ==========================================
            // CREATE JOURNAL ENTRIES
            // ==========================================
            try {
                $toko = $db->table('toko')->where('id', $idToko)->get()->getRowArray();
                $cashAccountId = $toko['cash_account_id']; // Default to Cash for Purchases

                // Find Purchase Account (Expense/Asset) - Default 'Pembelian'
                $purchaseAccount = $this->accountModel->like('name', 'Pembelian', 'both')->first();
                if (!$purchaseAccount) {
                    $purchaseAccount = $this->accountModel->where('type', 'EXPENSE')->first();
                }

                if ($cashAccountId && $purchaseAccount) {
                    $dateTime = date('Y-m-d H:i:s');
                    // 1. Journal Header
                    $journalData = [
                        'id_toko' => $idToko,
                        'reference_type' => 'PURCHASE',
                        'reference_id' => (string) $pembelianId,
                        'reference_no' => 'PO-' . $pembelianId,
                        'date' => date('Y-m-d'),
                        'description' => "Purchase Execution ID " . $pembelianId,
                        'total_debit' => $pembelian['total_belanja'],
                        'total_credit' => $pembelian['total_belanja'],
                        'created_at' => $dateTime,
                        'updated_at' => $dateTime
                    ];
                    $this->journalModel->insert($journalData);
                    $journalId = $this->journalModel->getInsertID();

                    // 2. Dr. Purchase (Expense/Asset)
                    $this->journalItemModel->insert([
                        'journal_id' => $journalId,
                        'account_id' => $purchaseAccount['id'],
                        'debit' => $pembelian['total_belanja'],
                        'credit' => 0,
                        'created_at' => $dateTime
                    ]);

                    // 3. Cr. Cash (Asset) - Money Out
                    $this->journalItemModel->insert([
                        'journal_id' => $journalId,
                        'account_id' => $cashAccountId,
                        'debit' => 0,
                        'credit' => $pembelian['total_belanja'],
                        'created_at' => $dateTime
                    ]);
                }
            } catch (\Exception $e) {
                log_message('error', 'Journal Entry Failed for Purchase: ' . $e->getMessage());
            }


            $updatePembelianData = [
                'status' => 'SUCCESS',
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $token['user_id'] ?? null,
            ];
            if (!$this->pembelianModel->update($pembelianId, $updatePembelianData)) {
                $errors = $this->pembelianModel->errors();
                throw new \Exception('Gagal mengubah status pembelian menjadi SUCCESS: ' . implode(', ', $errors ?: []));
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->jsonResponse->error('Gagal mengeksekusi pembelian karena transaksi database.', 400);
            }

            $db->transCommit();

            return $this->jsonResponse->oneResp(
                'Pembelian berhasil dieksekusi',
                [
                    'pembelian_id' => $pembelianId,
                ],
                200
            );

        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ERROR EXECUTE PEMBELIAN] ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString());
            return $this->jsonResponse->error($e->getMessage(), 400);
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
        $status = $this->request->getGet(index: 'status');

        $builder = $this->db->table('pembelian');
        $builder->select('pembelian.*, toko.toko_name, suplier.suplier_name');
        $builder->join('toko', 'toko.id = pembelian.id_toko', 'left');
        $builder->join('suplier', 'suplier.id = pembelian.supplier_id', 'left');

        // FILTER
        if ($id_toko) {
            $builder->where('pembelian.id_toko', $id_toko);
        }

        if ($status) {
            $builder->where('pembelian.status', $status);
        }

        if ($date_start && $date_end) {
            $start_val = $date_start . ' 00:00:00';
            $end_val = $date_end . ' 23:59:59';
            $builder->where("pembelian.created_at BETWEEN '{$start_val}' AND '{$end_val}'");

        } elseif ($date_start) {
            $start_val = $date_start . ' 00:00:00';
            $builder->where("pembelian.created_at >= '{$start_val}'");

        } elseif ($date_end) {
            $end_val = $date_end . ' 23:59:59';
            $builder->where("pembelian.created_at <= '{$end_val}'");
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

        if (is_object($pembelian)) {
            $pembelian = (array) $pembelian;
        }

        $pembelian['detail'] = $detail;
        $pembelian['biaya'] = $biaya;

        return $this->jsonResponse->oneResp('', $pembelian, 200);



    }


}
