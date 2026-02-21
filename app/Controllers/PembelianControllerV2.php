<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\PembelianModel;
use App\Models\PembelianDetailModel;
use App\Models\PembelianBiayaModel;
use App\Models\StockModel;
use App\Models\StockLedgerModel;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\ProductModel;
use App\Models\JsonResponse;
use CodeIgniter\API\ResponseTrait;

class PembelianControllerV2 extends ResourceController
{
    use ResponseTrait;

    protected $pembelianModel;
    protected $pembelianDetailModel;
    protected $pembelianBiayaModel;
    protected $stockModel;
    protected $stockLedgerModel;
    protected $journalModel;
    protected $journalItemModel;
    protected $accountModel;
    protected $productModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        $this->pembelianModel = new PembelianModel();
        $this->pembelianDetailModel = new PembelianDetailModel();
        $this->pembelianBiayaModel = new PembelianBiayaModel();
        $this->stockModel = new StockModel();
        $this->stockLedgerModel = new StockLedgerModel();
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->accountModel = new AccountModel();
        $this->productModel = new ProductModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // CREATE PEMBELIAN (Draft/Review)
    public function create()
    {
        $request = $this->request->getJSON(true);
        $user = $this->request->user;

        if (empty($request['tanggal_belanja']) || empty($request['detail']) || empty($request['id_toko'])) {
            return $this->jsonResponse->error('Data tidak lengkap (tanggal, detail, id_toko)', 400);
        }

        $this->db->transStart();

        try {
            // Calculate Total
            $totalDetail = 0;
            foreach ($request['detail'] as $item) {
                $totalDetail += (($item['harga_satuan'] ?? 0) + ($item['ongkir'] ?? 0)) * ($item['jumlah'] ?? 0);
            }

            $totalBiaya = 0;
            if (!empty($request['biaya'])) {
                foreach ($request['biaya'] as $biaya) {
                    $totalBiaya += ($biaya['jumlah'] ?? 0);
                }
            }
            $grandTotal = $totalDetail + $totalBiaya;

            // Header
            $pembelianId = $this->pembelianModel->insert([
                'tanggal_belanja' => $request['tanggal_belanja'],
                'supplier_id' => $request['supplier_id'] ?? null,
                'id_toko' => $request['id_toko'],
                'total_belanja' => $grandTotal,
                'catatan' => $request['catatan'] ?? null,
                'status' => 'NEED_REVIEW',
                'created_by' => $user['user_id'] ?? null,
                'bukti_foto' => $request['bukti_foto'] ?? null
            ]);

            // Detail
            foreach ($request['detail'] as $item) {
                $hargaSatuan = $item['harga_satuan'] ?? 0;
                $ongkir = $item['ongkir'] ?? 0;
                $jumlah = $item['jumlah'] ?? 0;
                $totalHarga = round(($hargaSatuan + $ongkir) * $jumlah);

                $this->pembelianDetailModel->insert([
                    'pembelian_id' => $pembelianId,
                    'kode_barang' => $item['kode_barang'],
                    'jumlah' => $jumlah,
                    'harga_satuan' => $hargaSatuan,
                    'harga_jual' => $item['harga_jual'] ?? 0,
                    'ongkir' => $ongkir,
                    'total_harga' => $totalHarga
                ]);
            }

            // Biaya Lain
            if (!empty($request['biaya'])) {
                foreach ($request['biaya'] as $biaya) {
                    $this->pembelianBiayaModel->insert([
                        'pembelian_id' => $pembelianId,
                        'nama_biaya' => $biaya['nama_biaya'],
                        'jumlah' => $biaya['jumlah']
                    ]);
                }
            }

            $this->db->transComplete();
            return $this->jsonResponse->oneResp('Pembelian disimpan (Draft)', ['id' => $pembelianId], 201);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // EXECUTE PEMBELIAN (Finalize -> Stock Update -> Journal)
    public function execute($pembelianId = null)
    {
        $user = $this->request->user;
        $pembelian = $this->pembelianModel->find($pembelianId);

        if (!$pembelian || $pembelian['status'] !== 'APPROVED') {
            return $this->jsonResponse->error('Pembelian tidak ditemukan atau status bukan REVIEW', 400);
        }

        $this->db->transStart();

        try {
            $details = $this->pembelianDetailModel->where('pembelian_id', $pembelianId)->findAll();
            $biayas = $this->pembelianBiayaModel->where('pembelian_id', $pembelianId)->findAll();

            // Calculate extra cost per unit distribution
            $totalBiayaLain = array_sum(array_column($biayas, 'jumlah'));
            $totalQtyAll = array_sum(array_column($details, 'jumlah'));
            $biayaPerUnit = ($totalQtyAll > 0) ? round($totalBiayaLain / $totalQtyAll) : 0;

            // Journal Entry Basics
            // Dr Inventory (Total Value)
            // Cr Cash (Assuming Cash Purchase for simplicity, user can expand later)
            // Value = Total Belanja (Details + Biaya)

            $journalId = $this->createJournal('PURCHASE', $pembelianId, "PO-{$pembelianId}", $pembelian['tanggal_belanja'], "Pembelian Barang", $pembelian['id_toko']);

            // Debit Inventory
            $this->addJournalItem($journalId, '1004', $pembelian['total_belanja'], 0, $pembelian['id_toko']);

            // Credit Bank (Using Bank Account 1002 default)
            $this->addJournalItem($journalId, '1002', 0, $pembelian['total_belanja'], $pembelian['id_toko']);


            // Process Stock & Average Cost Updating
            foreach ($details as $item) {
                $qty = $item['jumlah'];
                $costPerUnit = round($item['harga_satuan'] + $item['ongkir'] + $biayaPerUnit);
                $product = $this->productModel->where('id_barang', $item['kode_barang'])->first();

                if (!$product)
                    continue;

                // 1. Calculate New Average Cost (Weighted Average)
                $currentStockTotal = 0; // Across all stores? usually average cost is per product globally or per store? 
                // Context implies global product cost ('harga_modal' on product table).
                // But stock quantity is per store. 
                // Moving Average Cost Formula:
                // New Price = ((Old Stock * Old Price) + (New Qty * New Price)) / (Old Stock + New Qty)

                // We need TOTAL stock across all stores to be accurate or just assume current stock is retrieved.
                // Let's us total stock from validation logic in previous controller:
                // Actually previous controller used `stock` from `stockModel` based on `id_toko`.
                // BUT `harga_modal` is in `product` table (Global).
                // This implies we should consider global stock for accurate WA calculation, OR the user system treats cost per store implicitely but stores globally?
                // The previous code only checked stock in THAT store (`$stokLama = $stock ? intval($stock['stock']) : 0;`).
                // This is mathematically "incorrect" for global weighted average if there are stocks in other stores, but I will follow the legacy logic to avoid breaking their business rule:
                // Legacy: `(($hargaModalLama * $stokLama) + ($hargaModalSatuanItemIni * $jumlahBeli)) / $stokTotalSetelahBeli;` where `$stokLama` is ONLY from this `id_toko`.
                // I will stick to this behavior.

                $stockEntry = $this->stockModel
                    ->where('id_barang', $item['kode_barang'])
                    ->where('id_toko', $pembelian['id_toko'])
                    ->first();

                $oldQty = $stockEntry ? $stockEntry['stock'] : 0;
                $oldCost = $product['harga_modal'];

                $totalNewQty = $oldQty + $qty;

                // Round to avoid floating-point/decimal being stored as harga_modal (IDR is integer)
                $newAvgCost = round((($oldQty * $oldCost) + ($qty * $costPerUnit)) / ($totalNewQty > 0 ? $totalNewQty : 1));

                // Log description uses raw cost before rounding
                $oldCostDisplay = round($oldCost);
                $newAvgCostDisplay = $newAvgCost;

                // Update Product Master Cost & Sell Price
                $productUpdateData = ['harga_modal' => $newAvgCost];
                if (!empty($item['harga_jual']) && $item['harga_jual'] > 0) {
                    $productUpdateData['harga_jual'] = round($item['harga_jual']);
                }
                $this->productModel->update($product['id'], $productUpdateData);

                // Update Stock Quantity
                if ($stockEntry) {
                    $this->stockModel->update($stockEntry['id'], ['stock' => $totalNewQty]);
                } else {
                    $this->stockModel->insert([
                        'id_barang' => $item['kode_barang'],
                        'id_toko' => $pembelian['id_toko'],
                        'stock' => $qty,
                        'barang_cacat' => 0
                    ]);
                }

                // Add to Stock Ledger
                $this->stockLedgerModel->insert([
                    'id_barang' => $item['kode_barang'],
                    'id_toko' => $pembelian['id_toko'],
                    'qty' => $qty,
                    'balance' => $totalNewQty,
                    'reference_type' => 'PURCHASE',
                    'reference_id' => $pembelianId,
                    'description' => "Pembelian Barang (Avg Cost Updated: {$oldCostDisplay} -> {$newAvgCostDisplay})"
                ]);
            }

            // Update Header
            $this->pembelianModel->update($pembelianId, [
                'status' => 'SUCCESS',
                'updated_by' => $user['user_id']
            ]);

            $this->db->transComplete();
            return $this->jsonResponse->oneResp('Pembelian berhasil diproses', ['id' => $pembelianId], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // LIST & DETAIL (Similar to previous, simplified)
    public function index()
    {
        // ... (Similar logic to existing listPembelian)
        $id_toko = $this->request->getGet('id_toko');
        // ... simplified for brevity, assume similar implementation or use existing
        return $this->jsonResponse->error("Use List endpoint", 501);
    }

    // Helper Methods (Duplicated from TransactionControllerV2 for independence)
    private function createJournal($refType, $refId, $refNo, $date, $desc, $tokoId = null)
    {
        $this->journalModel->insert([
            'id_toko' => $tokoId,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'reference_no' => $refNo,
            'date' => $date,
            'description' => $desc,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        return $this->journalModel->getInsertID();
    }

    private function addJournalItem($journalId, $accountCode, $debit, $credit, $tokoId = null)
    {
        $account = $this->accountModel->getByBaseCode($accountCode, $tokoId);
        if (!$account) {
            $account = $this->accountModel->where('code', $accountCode)->first();
        }

        if (!$account)
            return;
        $this->journalItemModel->insert([
            'journal_id' => $journalId,
            'account_id' => $account['id'],
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}
