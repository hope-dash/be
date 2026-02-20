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

class InventoryController extends ResourceController
{
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
        $this->stockModel = new StockModel();
        $this->stockLedgerModel = new StockLedgerModel();
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->accountModel = new AccountModel();
        $this->productModel = new ProductModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // Transfer Stock (Antar Toko)
    public function transfer()
    {
        $data = $this->request->getJSON();
        $user = $this->request->user['user_id'] ?? 0;

        // Input: source_toko_id, target_toko_id, items: [{kode_barang, qty}], note, date

        if (empty($data->source_toko_id) || empty($data->target_toko_id) || empty($data->items)) {
            return $this->jsonResponse->error("Missing required fields", 400);
        }

        $fromToko = $data->source_toko_id;
        $toToko = $data->target_toko_id;
        $date = $data->date ?? date('Y-m-d');
        $note = $data->note ?? "Stock Transfer Toko $fromToko -> $toToko";

        $this->db->transStart();

        try {
            // Need unique reference for this transfer
            $refId = "TRF-STK-" . date('ymdHis');

            // 1. Ledger Entries & Stock Updates
            $totalValue = 0;

            foreach ($data->items as $item) {
                $qty = $item->qty;
                $code = $item->kode_barang;

                // Validate Product
                $product = $this->productModel->where('id_barang', $code)->first();
                if (!$product)
                    throw new \Exception("Product $code not found");

                // Validate Source Stock
                $stockSource = $this->stockModel->where('id_barang', $code)->where('id_toko', $fromToko)->first();
                if (!$stockSource || $stockSource['stock'] < $qty) {
                    throw new \Exception("Insufficient stock for $code in Source Toko");
                }

                // Deduct Source
                $this->stockModel->update($stockSource['id'], ['stock' => $stockSource['stock'] - $qty]);
                $this->stockLedgerModel->insert([
                    'id_barang' => $code,
                    'id_toko' => $fromToko,
                    'qty' => -$qty,
                    'balance' => $stockSource['stock'] - $qty,
                    'reference_type' => 'TRANSFER_OUT',
                    'reference_id' => $refId,
                    'description' => $note
                ]);

                // Add Target
                $stockTarget = $this->stockModel->where('id_barang', $code)->where('id_toko', $toToko)->first();
                if ($stockTarget) {
                    $newStock = $stockTarget['stock'] + $qty;
                    $this->stockModel->update($stockTarget['id'], ['stock' => $newStock]);
                } else {
                    $this->stockModel->insert([
                        'id_barang' => $code,
                        'id_toko' => $toToko,
                        'stock' => $qty,
                        'barang_cacat' => 0
                    ]);
                    $newStock = $qty;
                }

                $this->stockLedgerModel->insert([
                    'id_barang' => $code,
                    'id_toko' => $toToko,
                    'qty' => $qty,
                    'balance' => $newStock,
                    'reference_type' => 'TRANSFER_IN',
                    'reference_id' => $refId,
                    'description' => $note
                ]);

                // Calculate Value (Using Cost Price)
                $totalValue += ($product['harga_modal'] * $qty);
            }

            // 2. Journal Entries (Value Transfer)
            // Same logic as Money Transfer.
            // J1 (Source): Cr Inventory (1004), Dr In Transit (1005 or similar)
            // J2 (Target): Dr Inventory (1004), Cr In Transit

            // Create Journal Out (Source)
            $j1 = $this->createJournal('TRANSFER_OUT', $refId, "Inventory Move Out -> $toToko", $date, $fromToko);
            $this->addJournalItem($j1, '1006', $totalValue, 0); // Dr Transit
            $this->addJournalItem($j1, '1004', 0, $totalValue); // Cr Inventory

            // Create Journal In (Target)
            $j2 = $this->createJournal('TRANSFER_IN', $refId, "Inventory Move In <- $fromToko", $date, $toToko);
            $this->addJournalItem($j2, '1004', $totalValue, 0); // Dr Inventory
            $this->addJournalItem($j2, '1006', 0, $totalValue); // Cr Transit

            // 3. Handle Shipping Cost (Ongkos Kirim)
            if (isset($data->ongkos_kirim) && $data->ongkos_kirim > 0) {
                $ongkir = $data->ongkos_kirim;
                $paymentMethod = $data->payment_method ?? 'CASH';
                $creditAccount = ($paymentMethod === 'BANK') ? '1002' : '1001'; // Default CASH

                // Expense Journal for Source Store
                $j3 = $this->createJournal('EXPENSE', $refId, "Biaya Kirim Transfer Stock ($paymentMethod)", $date, $fromToko);
                $this->addJournalItem($j3, '5006', $ongkir, 0); // Dr Expense (Biaya Kirim/Operasional)
                $this->addJournalItem($j3, $creditAccount, 0, $ongkir); // Cr Cash/Bank
            }

            $this->db->transComplete();

            return $this->jsonResponse->oneResp('Stock transfer successful', ['ref_id' => $refId], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
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

    private function addJournalItem($journalId, $accountCode, $debit, $credit)
    {
        $account = $this->accountModel->where('code', $accountCode)->first();
        if (!$account) {
            if ($accountCode == '1005') {
                // Seed if missing
                $this->accountModel->insert(['code' => '1005', 'name' => 'Funds/Goods In Transit', 'type' => 'ASSET', 'normal_balance' => 'DEBIT']);
                $account = $this->accountModel->where('code', '1005')->first();
            } else {
                return;
            }
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
