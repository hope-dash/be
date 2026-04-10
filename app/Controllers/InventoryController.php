<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\StockModel;
use App\Models\StockLedgerModel;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\JsonResponse;
use App\Models\ProductModel;
use App\Models\StockTransferModel;
use App\Models\StockTransferItemModel;

class InventoryController extends ResourceController
{
    protected $stockModel;
    protected $stockLedgerModel;
    protected $journalModel;
    protected $journalItemModel;
    protected $accountModel;
    protected $productModel;
    protected $stockTransferModel;
    protected $stockTransferItemModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        $this->stockModel = new StockModel();
        $this->stockLedgerModel = new StockLedgerModel();
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->accountModel = new AccountModel();
        $this->productModel = new ProductModel();
        $this->stockTransferModel = new StockTransferModel();
        $this->stockTransferItemModel = new StockTransferItemModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
        helper('log');
    }

    // REQUEST Transfer Stock (Save as PENDING)
    public function requestTransfer()
    {
        $data = $this->request->getJSON();
        $user = $this->request->user['user_id'] ?? 0;

        if (empty($data->source_toko_id) || empty($data->target_toko_id) || empty($data->items)) {
            return $this->jsonResponse->error("Missing required fields", 400);
        }

        $this->db->transStart();
        try {
            $refId = "TRF-STK-" . date('ymdHis');
            $totalValue = 0;

            // 1. Create Header
            $transferId = $this->stockTransferModel->insert([
                'ref_id' => $refId,
                'source_toko_id' => $data->source_toko_id,
                'target_toko_id' => $data->target_toko_id,
                'status' => 'PENDING',
                'note' => $data->note ?? "Transfer Request Toko $data->source_toko_id -> $data->target_toko_id",
                'date' => $data->date ?? date('Y-m-d'),
                'ongkos_kirim' => $data->ongkos_kirim ?? 0,
                'payment_method' => $data->payment_method ?? 'CASH',
                'created_by' => $user,
                'tenant_id' => \App\Libraries\TenantContext::id()
            ]);

            // 2. Save Items and Calculate Value
            foreach ($data->items as $item) {
                $product = $this->productModel->where('id_barang', $item->kode_barang)->first();
                if (!$product) throw new \Exception("Product {$item->kode_barang} not found");

                $this->stockTransferItemModel->insert([
                    'transfer_id' => $transferId,
                    'kode_barang' => $item->kode_barang,
                    'qty' => $item->qty,
                    'harga_modal' => $product['harga_modal']
                ]);

                $totalValue += ($product['harga_modal'] * $item->qty);
            }

            // Update Total Value
            $this->stockTransferModel->update($transferId, ['total_value' => $totalValue]);

            $this->db->transComplete();

            return $this->jsonResponse->oneResp('Transfer request created', ['id' => $transferId, 'ref_id' => $refId], 201);
        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // APPROVE & EXECUTE Transfer
    public function approveTransfer($id)
    {
        $user = $this->request->user['user_id'] ?? 0;
        $transfer = $this->stockTransferModel->find($id);

        if (!$transfer) return $this->jsonResponse->error("Transfer not found", 404);
        if ($transfer['status'] !== 'PENDING') return $this->jsonResponse->error("Transfer is already {$transfer['status']}", 400);

        $items = $this->stockTransferItemModel->where('transfer_id', $id)->findAll();
        
        $this->db->transStart();
        try {
            // VALIDATION: Check ALL items stock before any movement
            foreach ($items as $item) {
                $stockSource = $this->stockModel->where('id_barang', $item['kode_barang'])
                                              ->where('id_toko', $transfer['source_toko_id'])
                                              ->first();
                
                if (!$stockSource || (int)$stockSource['stock'] < (int)$item['qty']) {
                    throw new \Exception("Insufficient stock for product {$item['kode_barang']} in source store. Available: " . ($stockSource['stock'] ?? 0));
                }
            }

            // EXECUTION: Move stock and create ledgers
            foreach ($items as $item) {
                $qty = (int)$item['qty'];
                $code = $item['kode_barang'];

                // Deduct Source
                $stockSource = $this->stockModel->where('id_barang', $code)->where('id_toko', $transfer['source_toko_id'])->first();
                $this->stockModel->update($stockSource['id'], ['stock' => $stockSource['stock'] - $qty]);
                
                $this->stockLedgerModel->insert([
                    'id_barang' => $code,
                    'id_toko' => $transfer['source_toko_id'],
                    'qty' => -$qty,
                    'balance' => $stockSource['stock'] - $qty,
                    'reference_type' => 'TRANSFER_OUT',
                    'reference_id' => $transfer['ref_id'],
                    'description' => $transfer['note']
                ]);

                // Add Target
                $stockTarget = $this->stockModel->where('id_barang', $code)->where('id_toko', $transfer['target_toko_id'])->first();
                if ($stockTarget) {
                    $newStock = $stockTarget['stock'] + $qty;
                    $this->stockModel->update($stockTarget['id'], ['stock' => $newStock]);
                } else {
                    $this->stockModel->insert([
                        'id_barang' => $code,
                        'id_toko' => $transfer['target_toko_id'],
                        'stock' => $qty,
                        'barang_cacat' => 0
                    ]);
                    $newStock = $qty;
                }

                $this->stockLedgerModel->insert([
                    'id_barang' => $code,
                    'id_toko' => $transfer['target_toko_id'],
                    'qty' => $qty,
                    'balance' => $newStock,
                    'reference_type' => 'TRANSFER_IN',
                    'reference_id' => $transfer['ref_id'],
                    'description' => $transfer['note']
                ]);
            }

            // Accounting Journals
            $totalValue = $transfer['total_value'];
            $fromToko = $transfer['source_toko_id'];
            $toToko = $transfer['target_toko_id'];
            $refId = $transfer['ref_id'];
            $date = $transfer['date'];

            $j1 = $this->createJournal('TRANSFER_OUT', $refId, "Inventory Move Out -> $toToko", $date, $fromToko);
            $this->addJournalItem($j1, '10' . $toToko . '4', $totalValue, 0, $fromToko); 
            $this->addJournalItem($j1, '10' . $fromToko . '4', 0, $totalValue, $fromToko);

            $j2 = $this->createJournal('TRANSFER_IN', $refId, "Inventory Move In <- $fromToko", $date, $toToko);
            $this->addJournalItem($j2, '10' . $toToko . '4', $totalValue, 0, $toToko);
            $this->addJournalItem($j2, '30' . $fromToko . '1', 0, $totalValue, $toToko);

            // Shipping Cost
            if ($transfer['ongkos_kirim'] > 0) {
                $paymentMethod = $transfer['payment_method'] ?? 'CASH';
                $creditAccount = ($paymentMethod === 'BANK') ? '10' . $toToko . '2' : '10' . $toToko . '1';
                $j3 = $this->createJournal('EXPENSE', $refId, "Biaya Kirim Transfer Stock", $date, $toToko);
                $this->addJournalItem($j3, '50' . $toToko . '6', $transfer['ongkos_kirim'], 0, $toToko);
                $this->addJournalItem($j3, $creditAccount, 0, $transfer['ongkos_kirim'], $toToko);
            }

            // Update Status
            $this->stockTransferModel->update($id, [
                'status' => 'APPROVED',
                'approved_by' => $user
            ]);

            $this->db->transComplete();

            return $this->jsonResponse->oneResp('Transfer approved and executed', ['ref_id' => $refId], 200);

        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
    // REJECT Transfer
    public function rejectTransfer($id)
    {
        $user = $this->request->user['user_id'] ?? 0;
        $transfer = $this->stockTransferModel->find($id);

        if (!$transfer) return $this->jsonResponse->error("Transfer not found", 404);
        if ($transfer['status'] !== 'PENDING') return $this->jsonResponse->error("Only PENDING transfers can be rejected", 400);

        $this->stockTransferModel->update($id, [
            'status' => 'REJECTED',
            'approved_by' => $user // We use this field to track who took action
        ]);

        return $this->jsonResponse->oneResp('Transfer rejected', null, 200);
    }

    public function getTransfers()
    {
        $status = $this->request->getGet('status');
        $builder = $this->stockTransferModel;
        if ($status) $builder->where('status', $status);
        
        $data = $builder->orderBy('created_at', 'DESC')->findAll();
        return $this->jsonResponse->oneResp('success', $data, 200);
    }

    public function getTransferDetail($id)
    {
        $transfer = $this->stockTransferModel->find($id);
        if (!$transfer) return $this->jsonResponse->error("Not found", 404);
        
        $transfer['items'] = $this->stockTransferItemModel
            ->select('stock_transfer_item.*, product.nama_barang')
            ->join('product', 'product.id_barang = stock_transfer_item.kode_barang', 'left')
            ->where('transfer_id', $id)
            ->findAll();

        return $this->jsonResponse->oneResp('success', $transfer, 200);
    }

    private function createJournal($refType, $refId, $desc, $date, $tokoId)
    {
        $this->journalModel->insert([
            'id_toko' => $tokoId,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'date' => $date,
            'description' => $desc,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        return $this->journalModel->getInsertID();
    }

    private function addJournalItem($journalId, $accountCode, $debit, $credit, $tokoId)
    {
        $account = $this->accountModel->getByBaseCode($accountCode, $tokoId);
        if (!$account) {
            // Fallback to base account if store-specific not found (safety)
            $account = $this->accountModel->where('code', $accountCode)->first();

            if (!$account && in_array($accountCode, ['10' . $tokoId . '5', '10' . $tokoId . '6'])) {
                // Seed if missing
                $name = ($accountCode == '10' . $tokoId . '5') ? 'Funds/Goods In Transit' : 'Inventory Cabangan (Transit)';
                $this->accountModel->insert(['code' => $accountCode, 'name' => $name, 'type' => 'ASSET', 'normal_balance' => 'DEBIT']);
                $account = $this->accountModel->where('code', $accountCode)->first();
            }

            if (!$account)
                return;
        }
        $this->journalItemModel->insert([
            'journal_id' => $journalId,
            'account_id' => $account['id'],
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}
