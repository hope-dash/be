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
        $this->request = service('request');
        $this->response = service('response');
        $this->db = \Config\Database::connect();
        helper('log');
    }

    /**
     * REVIEW/APPROVE PEMBELIAN
     * Changes status from NEED_REVIEW to APPROVED
     */
    public function review($id = null)
    {
        $user = $this->request->user;
        $activeUser = $user['user_id'] ?? null;

        $pembelian = $this->pembelianModel->find($id);
        if (!$pembelian) {
            return $this->jsonResponse->error('Pembelian tidak ditemukan', 404);
        }

        if ($pembelian['status'] !== 'NEED_REVIEW') {
            return $this->jsonResponse->error('Hanya pembelian dengan status NEED_REVIEW yang dapat di-approve. Status saat ini: ' . $pembelian['status'], 400);
        }

        $this->db->transStart();
        try {
            $this->pembelianModel->update($id, [
                'status' => 'APPROVED',
                'updated_by' => $activeUser
            ]);

            $this->db->transComplete();

            log_aktivitas([
                'user_id' => $activeUser,
                'action_type' => 'APPROVE_PURCHASE',
                'target_table' => 'pembelian',
                'target_id' => $id,
                'description' => "Menyetujui (Approve) pembelian ID: #$id senilai " . number_format($pembelian['total_belanja'], 0, ',', '.')
            ]);

            return $this->jsonResponse->oneResp('Pembelian berhasil di-approve', ['id' => $id], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
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

            log_aktivitas([
                'user_id' => $user['user_id'],
                'action_type' => 'CREATE_PURCHASE',
                'target_table' => 'pembelian',
                'target_id' => $pembelianId,
                'description' => "Membuat draft pembelian barang senilai " . number_format($grandTotal, 0, ',', '.'),
                'detail' => ['id_toko' => $request['id_toko'], 'total' => $grandTotal]
            ]);

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
            $this->addJournalItem($journalId, '10' . $pembelian['id_toko'] . '4', $pembelian['total_belanja'], 0, $pembelian['id_toko']);

            // Credit Bank (Using Bank Account 1002 default)
            $this->addJournalItem($journalId, '10' . $pembelian['id_toko'] . '2', 0, $pembelian['total_belanja'], $pembelian['id_toko']);


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

                // Activity Log for each product
                log_aktivitas([
                    'user_id' => $user['user_id'],
                    'action_type' => 'STOCK_IN',
                    'target_table' => 'product',
                    'target_id' => $product['id'],
                    'description' => "Belanja: Produk {$item['kode_barang']} di Toko #{$pembelian['id_toko']}. Stock: $oldQty -> $totalNewQty, Modal: $oldCostDisplay -> $newAvgCostDisplay"
                ]);
            }

            // Update Header
            $this->pembelianModel->update($pembelianId, [
                'status' => 'SUCCESS',
                'updated_by' => $user['user_id']
            ]);

            $this->db->transComplete();

            log_aktivitas([
                'user_id' => $user['user_id'],
                'action_type' => 'EXECUTE_PURCHASE',
                'target_table' => 'pembelian',
                'target_id' => $pembelianId,
                'description' => "Mengeksekusi pembelian ID: #$pembelianId, stock bertambah dan jurnal dicatat."
            ]);

            return $this->jsonResponse->oneResp('Pembelian berhasil diproses', ['id' => $pembelianId], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * ROLLBACK / UNDO PEMBELIAN
     * Reverses stock, stock_ledgers (with negative qty), journals, and activity logs.
     * Optionally transfers and re-executes purchase to a target store (e.g. target_id_toko = 3).
     * POST /api/v2/purchase/(:num)/rollback
     */
    public function rollback($pembelianId = null, $cliTargetIdToko = null, $cliReason = null)
    {
        if ($pembelianId === null) {
            return $this->jsonResponse->error('ID Pembelian wajib diisi.', 400);
        }

        $request = $this->request ?? service('request');
        $user = ($request && isset($request->user)) ? $request->user : [];
        $userId = $user['user_id'] ?? 1;

        $requestData = [];
        if ($request && method_exists($request, 'getJSON')) {
            try {
                $requestData = $request->getJSON(true) ?: ($request->getPost() ?: []);
            } catch (\Throwable $e) {
                $requestData = $request->getPost() ?: [];
            }
        }

        $targetIdToko = ($cliTargetIdToko !== null && $cliTargetIdToko !== 0) ? (int)$cliTargetIdToko : (!empty($requestData['target_id_toko']) ? (int)$requestData['target_id_toko'] : null);
        $reason = $cliReason !== null ? $cliReason : ($requestData['reason'] ?? 'Salah input toko');

        $pembelian = $this->pembelianModel->find($pembelianId);
        if (!$pembelian) {
            return $this->jsonResponse->error('Data pembelian tidak ditemukan.', 404);
        }

        if ($pembelian['status'] !== 'SUCCESS') {
            return $this->jsonResponse->error('Hanya pembelian dengan status SUCCESS yang dapat di-rollback. Status saat ini: ' . $pembelian['status'], 400);
        }

        $wrongTokoId = (int)$pembelian['id_toko'];
        $details = $this->pembelianDetailModel->where('pembelian_id', $pembelianId)->findAll();
        if (empty($details)) {
            return $this->jsonResponse->error('Detail pembelian tidak ditemukan untuk pembelian ID: ' . $pembelianId, 400);
        }

        $biayas = $this->pembelianBiayaModel->where('pembelian_id', $pembelianId)->findAll();
        $totalBiayaLain = array_sum(array_column($biayas, 'jumlah'));
        $totalQtyAll = array_sum(array_column($details, 'jumlah'));
        $biayaPerUnit = ($totalQtyAll > 0) ? round($totalBiayaLain / $totalQtyAll) : 0;
        $totalBelanja = (float)$pembelian['total_belanja'];

        $this->db->transStart();

        try {
            // ========================================================
            // STEP 1: UNDO STOCK & RECORD MINUS STOCK LEDGER AT WRONG TOKO
            // ========================================================
            $rollbackSummary = [];
            foreach ($details as $item) {
                $kodeBarang = $item['kode_barang'];
                $qty = (int)$item['jumlah'];

                $product = $this->productModel->where('id_barang', $kodeBarang)->first();
                $stockEntry = $this->stockModel
                    ->where('id_barang', $kodeBarang)
                    ->where('id_toko', $wrongTokoId)
                    ->first();

                $oldStock = $stockEntry ? (int)$stockEntry['stock'] : 0;
                $newStock = $oldStock - $qty;

                // Update stock in wrong store
                if ($stockEntry) {
                    $this->stockModel->update($stockEntry['id'], ['stock' => $newStock]);
                } else {
                    $this->stockModel->insert([
                        'id_barang' => $kodeBarang,
                        'id_toko' => $wrongTokoId,
                        'stock' => $newStock,
                        'barang_cacat' => 0
                    ]);
                }

                // Add minus entry in stock_ledgers
                $this->stockLedgerModel->insert([
                    'tenant_id' => $pembelian['tenant_id'] ?? 1,
                    'id_barang' => $kodeBarang,
                    'id_toko' => $wrongTokoId,
                    'qty' => -$qty,
                    'balance' => $newStock,
                    'reference_type' => 'PURCHASE_CANCEL',
                    'reference_id' => $pembelianId,
                    'description' => "Koreksi salah input toko - Rollback Pembelian #{$pembelianId} di Toko #{$wrongTokoId} (Stok dikurangi: -{$qty}). Alasan: {$reason}",
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                // Activity log for stock out / reversal
                log_aktivitas([
                    'user_id' => $userId,
                    'action_type' => 'STOCK_OUT',
                    'target_table' => 'product',
                    'target_id' => $product['id'] ?? null,
                    'description' => "Koreksi salah input toko Pembelian #{$pembelianId}: Stok Produk {$kodeBarang} di Toko #{$wrongTokoId} dikurangi (-{$qty}). Stok: {$oldStock} -> {$newStock}. Alasan: {$reason}"
                ]);

                $rollbackSummary[] = [
                    'kode_barang' => $kodeBarang,
                    'qty_dikurangi' => $qty,
                    'stok_lama' => $oldStock,
                    'stok_baru' => $newStock
                ];
            }

            // ========================================================
            // STEP 2: REVERSE JOURNAL AT WRONG TOKO
            // ========================================================
            $existingJournal = $this->journalModel
                ->where('reference_type', 'PURCHASE')
                ->where('reference_id', $pembelianId)
                ->first();

            $revDate = date('Y-m-d');
            $revJournalId = $this->createJournal(
                'PURCHASE_CANCEL',
                $pembelianId,
                "REV-PO-{$pembelianId}",
                $revDate,
                "Pembalikan Jurnal Pembelian #{$pembelianId} karena salah input toko #{$wrongTokoId}",
                $wrongTokoId
            );

            if ($existingJournal) {
                $origItems = $this->journalItemModel->where('journal_id', $existingJournal['id'])->findAll();
                foreach ($origItems as $oi) {
                    $this->journalItemModel->insert([
                        'journal_id' => $revJournalId,
                        'account_id' => $oi['account_id'],
                        'debit' => $oi['credit'],   // Flip debit & credit
                        'credit' => $oi['debit'],
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            } else {
                // Fallback standard reversal: Dr Bank, Cr Inventory
                $this->addJournalItem($revJournalId, '10' . $wrongTokoId . '2', $totalBelanja, 0, $wrongTokoId);
                $this->addJournalItem($revJournalId, '10' . $wrongTokoId . '4', 0, $totalBelanja, $wrongTokoId);
            }

            // ========================================================
            // STEP 3: REVERSE CASHFLOW (IF ANY RECORD EXISTS)
            // ========================================================
            $existingCashflow = $this->db->table('cashflow')
                ->where('id_toko', $wrongTokoId)
                ->like('noted', "Belanja ID {$pembelianId}")
                ->get()->getRowArray();

            if ($existingCashflow) {
                $this->db->table('cashflow')->insert([
                    'debit' => $totalBelanja,
                    'credit' => 0,
                    'noted' => "Koreksi/Pembalikan Belanja ID {$pembelianId} karena salah input toko #{$wrongTokoId}",
                    'type' => 'Koreksi Belanja',
                    'status' => 'SUCCESS',
                    'date_time' => date('Y-m-d H:i:s'),
                    'id_toko' => $wrongTokoId,
                ]);
            }

            // Activity log for overall rollback
            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'ROLLBACK_PURCHASE',
                'target_table' => 'pembelian',
                'target_id' => $pembelianId,
                'description' => "Rollback Pembelian ID: #{$pembelianId} dari Toko #{$wrongTokoId}. Stok dikurangi dan jurnal dibalik (Reversal Journal #{$revJournalId}). Alasan: {$reason}"
            ]);

            // ========================================================
            // STEP 4: TRANSFER & EXECUTE TO TARGET TOKO (IF REQUESTED)
            // ========================================================
            $targetExecutionSummary = null;
            if ($targetIdToko && $targetIdToko !== $wrongTokoId) {
                // 1. Update purchase header to target toko
                $this->pembelianModel->update($pembelianId, [
                    'id_toko' => $targetIdToko,
                    'status' => 'APPROVED',
                    'updated_by' => $userId
                ]);

                // 2. Create Purchase Journal at Target Toko
                $targetJournalId = $this->createJournal(
                    'PURCHASE',
                    $pembelianId,
                    "PO-{$pembelianId}",
                    $pembelian['tanggal_belanja'] ?? date('Y-m-d'),
                    "Pembelian Barang (Toko #{$targetIdToko})",
                    $targetIdToko
                );

                // Debit Inventory Target Toko
                $this->addJournalItem($targetJournalId, '10' . $targetIdToko . '4', $totalBelanja, 0, $targetIdToko);
                // Credit Bank Target Toko
                $this->addJournalItem($targetJournalId, '10' . $targetIdToko . '2', 0, $totalBelanja, $targetIdToko);

                // 3. Process Stock & Ledgers at Target Toko
                $targetItemsSummary = [];
                foreach ($details as $item) {
                    $qty = (int)$item['jumlah'];
                    $costPerUnit = round($item['harga_satuan'] + $item['ongkir'] + $biayaPerUnit);
                    $product = $this->productModel->where('id_barang', $item['kode_barang'])->first();

                    if (!$product) continue;

                    $targetStockEntry = $this->stockModel
                        ->where('id_barang', $item['kode_barang'])
                        ->where('id_toko', $targetIdToko)
                        ->first();

                    $oldTargetQty = $targetStockEntry ? (int)$targetStockEntry['stock'] : 0;
                    $oldCost = (float)$product['harga_modal'];
                    $newTargetQty = $oldTargetQty + $qty;

                    // Moving Average Cost calculation
                    $newAvgCost = round((($oldTargetQty * $oldCost) + ($qty * $costPerUnit)) / ($newTargetQty > 0 ? $newTargetQty : 1));

                    // Update product master cost
                    $productUpdateData = ['harga_modal' => $newAvgCost];
                    if (!empty($item['harga_jual']) && $item['harga_jual'] > 0) {
                        $productUpdateData['harga_jual'] = round($item['harga_jual']);
                    }
                    $this->productModel->update($product['id'], $productUpdateData);

                    // Update Stock at target store
                    if ($targetStockEntry) {
                        $this->stockModel->update($targetStockEntry['id'], ['stock' => $newTargetQty]);
                    } else {
                        $this->stockModel->insert([
                            'id_barang' => $item['kode_barang'],
                            'id_toko' => $targetIdToko,
                            'stock' => $newTargetQty,
                            'barang_cacat' => 0
                        ]);
                    }

                    // Stock ledger at target store
                    $this->stockLedgerModel->insert([
                        'tenant_id' => $pembelian['tenant_id'] ?? 1,
                        'id_barang' => $item['kode_barang'],
                        'id_toko' => $targetIdToko,
                        'qty' => $qty,
                        'balance' => $newTargetQty,
                        'reference_type' => 'PURCHASE',
                        'reference_id' => $pembelianId,
                        'description' => "Pembelian Barang di Toko #{$targetIdToko} (Dialihkan dari Toko #{$wrongTokoId})"
                    ]);

                    // Activity Log at target store
                    log_aktivitas([
                        'user_id' => $userId,
                        'action_type' => 'STOCK_IN',
                        'target_table' => 'product',
                        'target_id' => $product['id'],
                        'description' => "Belanja: Produk {$item['kode_barang']} dialihkan ke Toko #{$targetIdToko}. Stock: {$oldTargetQty} -> {$newTargetQty}, Modal: " . round($oldCost) . " -> {$newAvgCost}"
                    ]);

                    $targetItemsSummary[] = [
                        'kode_barang' => $item['kode_barang'],
                        'qty_bertambah' => $qty,
                        'stok_lama' => $oldTargetQty,
                        'stok_baru' => $newTargetQty
                    ];
                }

                // Finalize purchase header to SUCCESS at target store
                $this->pembelianModel->update($pembelianId, [
                    'status' => 'SUCCESS',
                    'updated_by' => $userId,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                log_aktivitas([
                    'user_id' => $userId,
                    'action_type' => 'EXECUTE_PURCHASE',
                    'target_table' => 'pembelian',
                    'target_id' => $pembelianId,
                    'description' => "Mengeksekusi pembelian ID: #{$pembelianId} di Toko #{$targetIdToko} (pengalihan dari Toko #{$wrongTokoId})."
                ]);

                $targetExecutionSummary = [
                    'target_id_toko' => $targetIdToko,
                    'target_journal_id' => $targetJournalId,
                    'items' => $targetItemsSummary
                ];
            } else {
                // If not transferred immediately, reset status to NEED_REVIEW so it can be edited/re-approved
                $this->pembelianModel->update($pembelianId, [
                    'status' => 'NEED_REVIEW',
                    'updated_by' => $userId,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->jsonResponse->error('Gagal melakukan rollback karena kegagalan transaksi database.', 500);
            }

            // Sync TikTok Stock for wrong store and target store if TikTok service exists
            try {
                if (class_exists('\App\Libraries\TiktokService')) {
                    $tiktokService = new \App\Libraries\TiktokService();
                    foreach ($details as $dItem) {
                        $pRow = $this->productModel->where('id_barang', $dItem['kode_barang'])->first();
                        if ($pRow) {
                            $tiktokService->syncProductStock((int)$pRow['id'], $wrongTokoId);
                            if ($targetIdToko) {
                                $tiktokService->syncProductStock((int)$pRow['id'], $targetIdToko);
                            }
                        }
                    }
                }
            } catch (\Throwable $ttEx) {
                log_message('warning', "[RollbackPembelian] TikTok sync warning: " . $ttEx->getMessage());
            }

            return $this->jsonResponse->oneResp(
                $targetIdToko
                    ? "Pembelian #{$pembelianId} berhasil di-rollback dari Toko #{$wrongTokoId} dan dialihkan ke Toko #{$targetIdToko}"
                    : "Pembelian #{$pembelianId} berhasil di-rollback dari Toko #{$wrongTokoId}. Status kembali ke NEED_REVIEW.",
                [
                    'pembelian_id' => $pembelianId,
                    'wrong_toko_id' => $wrongTokoId,
                    'reversal_journal_id' => $revJournalId,
                    'rollback_summary' => $rollbackSummary,
                    'transferred_to_target' => $targetExecutionSummary
                ],
                200
            );

        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', '[ERROR ROLLBACK PEMBELIAN] ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString());
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
