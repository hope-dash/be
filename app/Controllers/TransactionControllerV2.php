<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\TransactionModel;
use App\Models\SalesProductModel;
use App\Models\ProductModel;
use App\Models\StockModel;
use App\Models\StockLedgerModel;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\TransactionPaymentModel;
use App\Models\CustomerModel;
use App\Models\JsonResponse;
use App\Models\TransactionMetaModel;
use CodeIgniter\API\ResponseTrait;

class TransactionControllerV2 extends ResourceController
{
    use ResponseTrait;

    protected $transactionModel;
    protected $salesProductModel;
    protected $productModel;
    protected $stockModel;
    protected $stockLedgerModel;
    protected $journalModel;
    protected $journalItemModel;
    protected $accountModel;
    protected $paymentModel;
    protected $customerModel;
    protected $transactionMetaModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        helper('log');
        $this->transactionModel = new TransactionModel();
        $this->salesProductModel = new SalesProductModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->stockLedgerModel = new StockLedgerModel();
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->accountModel = new AccountModel();
        $this->paymentModel = new TransactionPaymentModel();
        $this->customerModel = new CustomerModel();
        $this->transactionMetaModel = new TransactionMetaModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // 1. Create Transaction (Invoice)
    public function create()
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;

        $this->db->transStart();

        try {
            $items = $data->items ?? $data->item ?? []; // Support both keys
            if (empty($items)) throw new \Exception("Items cannot be empty");

            // -- 0. Customer Handling --
            $customerId = $data->customer_id ?? null;
            if (empty($customerId) && !empty($data->customer_phone)) {
                // Check if customer exists by phone
                $existingCust = $this->customerModel->where('no_hp_customer', $data->customer_phone)->first();
                if ($existingCust) {
                    $customerId = $existingCust['id'];
                } else {
                    // Create new customer
                    $custData = [
                        'nama_customer' => $data->customer_name ?? 'Guest',
                        'no_hp_customer' => $data->customer_phone,
                        'alamat' => $data->alamat ?? '',
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $this->customerModel->insert($custData);
                    $customerId = $this->customerModel->getInsertID();
                }
            }


            // -- 1. Calculate Totals --
            $totalAmount = 0;
            $itemsProcessed = [];
            $totalModal = 0;

            foreach ($items as $item) {
                // Compatible with both 'id_barang' and 'kode_barang'
                $idBarang = $item->id_barang ?? $item->kode_barang ?? null;
                if (!$idBarang) throw new \Exception("Item code/id missing");
                
                // Compatible with 'price' or 'harga_jual'
                $price = $item->price ?? $item->harga_jual ?? 0;
                $qty = $item->qty ?? $item->jumlah ?? 0;

                $product = $this->productModel->where('id_barang', $idBarang)->first();
                if (!$product) throw new \Exception("Product {$idBarang} not found");

                // Validate Stock
                $stockEntry = $this->stockModel->where('id_barang', $idBarang)->where('id_toko', $data->id_toko)->first();
                $currentStock = $stockEntry ? $stockEntry['stock'] : 0;
                if ($currentStock < $qty) {
                    throw new \Exception("Insufficient stock for {$product['nama_barang']}");
                }

                $totalAmount += ($price * $qty);
                $modal = $product['harga_modal'] * $qty;
                $totalModal += $modal;
                
                $itemsProcessed[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'price' => $price
                ];
            }

            $discountAmount = $data->discount_amount ?? 0;
            
            // Shipping Cost Logic
            $shippingCost = $data->biaya_pengiriman ?? 0;
            $isFreeOngkir = $data->free_ongkir ?? false;
            
            // Grand Total Calculation
            // Subtotal - Discount + Shipping (if not free)
            // If free ongkir, customer doesn't pay shipping, but we record the expense later?
            // "kalo ada ongkir tapi free ongkir true maka jadi beban dikitia biaya ongkirnya"
            // Means: Receivable amount does NOT include shipping.
            // But we might need to pay the courier.
            
            $grandTotal = $totalAmount - $discountAmount;
            
            if (!$isFreeOngkir) {
                $grandTotal += $shippingCost;
            }
            
            if ($grandTotal < 0) $grandTotal = 0;

            // -- Insert Transaction --
            $trxData = [
                'invoice' => 'INV-' . date('ymd') . rand(1000,9999), 
                'id_toko' => $data->id_toko,
                'amount' => $grandTotal,
                'actual_total' => $grandTotal,
                'total_payment' => 0,
                'status' => 'WAITING_PAYMENT',
                'delivery_status' => 'READY_TO_PICKUP',
                'discount_type' => $data->discount_type ?? 'FIXED',
                'discount_amount' => $discountAmount,
                'total_modal' => $totalModal,
                'po' => $data->po ?? false,
                'created_by' => $userId,
                'date_time' => date('Y-m-d H:i:s'),
                'invoice' => 'INV-' . date('ymd') . rand(1000, 9999), // Temporary, will update with ID if needed
            ];
            
            $this->transactionModel->insert($trxData);
            $trxId = $this->transactionModel->getInsertID();

            // Save Metadata (Customer, Shipping info, etc)
            $metaData = [
                'customer_id' => $customerId,
                'customer_name' => $data->customer_name ?? '',
                'customer_phone' => $data->customer_phone ?? '',
                'alamat' => $data->alamat ?? '',
                'source' => $data->source ?? '',
                'jatuh_tempo' => $data->jatuh_tempo ?? '',
                'pengiriman' => $data->pengiriman ?? '',
                'biaya_pengiriman' => $shippingCost,
                'free_ongkir' => $isFreeOngkir ? '1' : '0',
                'ppn' => $data->ppn ?? 0
            ];
            
            foreach ($metaData as $key => $val) {
                if ($val !== null) {
                    $this->transactionMetaModel->insert([
                        'transaction_id' => $trxId,
                        'key' => $key,
                        'value' => (string)$val
                    ]);
                }
            }


            // -- Accounting: Sales Journal --
            $journalId = $this->createJournal('SALES', $trxId, $trxData['invoice'], date('Y-m-d'), "Invoice #{$trxData['invoice']}", $data->id_toko);
            
            // 1. Dr AR (Total Receivables)
            $this->addJournalItem($journalId, '1003', $grandTotal, 0); 
            
            // 2. Dr Discount (if any)
            if ($discountAmount > 0) {
                 $this->addJournalItem($journalId, '4002', $discountAmount, 0); 
            }
            
            // 3. Cr Sales Revenue (Gross Sales from Items)
            // Revenue only comes from the ITEM Sales.
            $this->addJournalItem($journalId, '4001', 0, $totalAmount); 
            
            // 4. Shipping Logic
            // If Customer pays shipping (!FreeOngkir):
            // We credit a Liability/Income account for shipping? Or Revenue?
            // Usually "Shipping Revenue" or "Shipping Payable" (if pass-through).
            // Let's assume Credit '4004' (Shipping Revenue) or reuse 4001 if simple.
            // If FreeOngkir:
            // We Debit "Shipping Expense" (Beban Ongkir) and Credit "Cash/Payable" to courier later?
            // "Maka jadi beban di kita" -> This happens when we PAY the courier. 
            // In the SALES invoice, if it's free, we just don't charge the customer.
            // IF we want to accrue the expense NOW (provision):
            // Dr Shipping Expense, Cr Accrued Shipping.
            // For MVP: If customer pays -> Cr Shipping Revenue.
            
            if (!$isFreeOngkir && $shippingCost > 0) {
                 // We don't have Shipping Revenue account in seeds, put to Sales or Other Revenue.
                 // Using 4001 Sales Revenue for now to balance.
                 $this->addJournalItem($journalId, '4001', 0, $shippingCost);
            }
            
            if ($isFreeOngkir && $shippingCost > 0) {
                // "Jadi beban di kita". We recognize expense.
                // Dr Shipping Expense (6005 Operational or new 6006 Shipping)
                // Cr Freight Payable (200x).
                // Assuming we pay later.
                // Let's create account 6006 if not exists, or use 6005. 
                // Using 6005 Operational for now.
                // And Credit 2001 AP.
                $this->addJournalItem($journalId, '6005', $shippingCost, 0); // Dr Expense
                $this->addJournalItem($journalId, '2001', 0, $shippingCost); // Cr Payable
            }


            // -- Process Items: Stock & COGS --
            $salesProductData = [];
            $cogsTotal = 0;

            foreach ($itemsProcessed as $itemData) {
                $p = $itemData['product'];
                $qty = $itemData['qty'];
                $price = $itemData['price'];
                $modal = $p['harga_modal'] * $qty;

                // Deduct Stock
                $this->deductStock($p['id_barang'], $data->id_toko, $qty, $trxId, "Invoice {$trxData['invoice']}");

                $salesProductData[] = [
                    'id_transaction' => $trxId,
                    'kode_barang' => $p['id_barang'],
                    'jumlah' => $qty,
                    'harga_jual' => $price,
                    'total' => $qty * $price,
                    'modal_system' => $p['harga_modal'],
                    'total_modal' => $modal,
                    'actual_per_piece' => $price,
                    'actual_total' => $qty * $price,
                ];

                $cogsTotal += $modal;
            }
            $this->salesProductModel->insertBatch($salesProductData);

            // -- Accounting: COGS Journal --
            if ($cogsTotal > 0) {
                 $cogsJournalId = $this->createJournal('COGS', $trxId, $trxData['invoice'], date('Y-m-d'), "COGS Invoice {$trxData['invoice']}", $data->id_toko);
                 $this->addJournalItem($cogsJournalId, '5001', $cogsTotal, 0); // Dr COGS
                 $this->addJournalItem($cogsJournalId, '1004', 0, $cogsTotal); // Cr Inventory
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->jsonResponse->error('Transaction failed to save', 500);
            }
            
            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'CREATE',
                'target_table' => 'transaction',
                'target_id' => $trxId,
                'description' => "Created transaction {$trxData['invoice']}",
                'detail' => $trxData
            ]);

            return $this->jsonResponse->oneResp('Transaction created successfully', ['id' => $trxId], 201);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 2. Add Payment
    public function addPayment($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;
        
        $trx = $this->transactionModel->find($id);
        if (!$trx) return $this->jsonResponse->error("Transaction not found", 404);

        $amount = $data->amount;
        $method = $data->payment_method ?? 'CASH';

        $this->db->transStart();
        try {
            $this->paymentModel->insert([
                'transaction_id' => $id,
                'amount' => $amount,
                'payment_method' => $method,
                'status' => 'VERIFIED',
                'paid_at' => date('Y-m-d H:i:s'),
                'image_url' => $data->image ?? null
            ]);

            $accountCode = ($method == 'CASH') ? '1001' : '1002'; 
            $journalId = $this->createJournal('PAYMENT', $id, $trx['invoice'], date('Y-m-d'), "Payment for {$trx['invoice']}", $trx['id_toko']);
            $this->addJournalItem($journalId, $accountCode, $amount, 0); // Dr Cash
            $this->addJournalItem($journalId, '1003', 0, $amount); // Cr AR

            $newTotalPaid = $trx['total_payment'] + $amount;
            $newStatus = ($newTotalPaid >= $trx['amount']) ? 'PAID' : 'PARTIALLY_PAID';

            $this->transactionModel->update($id, [
                'total_payment' => $newTotalPaid,
                'status' => $newStatus
            ]);

            $this->db->transComplete();
            
            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'PAYMENT',
                'target_table' => 'transaction',
                'target_id' => $id,
                'description' => "Added payment of $amount via $method",
                'detail' => ['amount' => $amount, 'method' => $method, 'image' => $data->image ?? null]
            ]);

            return $this->jsonResponse->oneResp('Payment added successfully', ['new_status' => $newStatus], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 3. Cancel Transaction
    public function cancel($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;
        
        $trx = $this->transactionModel->find($id);
        if (!$trx) return $this->jsonResponse->error("Transaction not found", 404);

        $this->db->transStart();
        try {
            // Restore Stock
            $items = $this->salesProductModel->where('id_transaction', $id)->findAll();
            $cogsReversal = 0;
            
            foreach ($items as $item) {
                $this->addStock($item['kode_barang'], $trx['id_toko'], $item['jumlah'], $id, "Cancel Transaction {$trx['invoice']}");
                $cogsReversal += $item['total_modal'];
            }

            // Reverse COGS
            if ($cogsReversal > 0) {
                $jId = $this->createJournal('CANCEL_COGS', $id, $trx['invoice'], date('Y-m-d'), "Reversal COGS {$trx['invoice']}", $trx['id_toko']);
                $this->addJournalItem($jId, '1004', $cogsReversal, 0); 
                $this->addJournalItem($jId, '5001', 0, $cogsReversal); 
            }

            // Reverse Sales
            $jIdSales = $this->createJournal('CANCEL_SALES', $id, $trx['invoice'], date('Y-m-d'), "Cancellation {$trx['invoice']}", $trx['id_toko']);
            $this->addJournalItem($jIdSales, '4001', $trx['amount'] + $trx['discount_amount'], 0); 
            if ($trx['discount_amount'] > 0) {
                 $this->addJournalItem($jIdSales, '4002', 0, $trx['discount_amount']);
            }
            $this->addJournalItem($jIdSales, '1003', 0, $trx['amount']);

            $newStatus = 'CANCEL';
            $refundNeeded = 0;
            
            if ($trx['total_payment'] > 0) {
                $newStatus = 'NEED_REFUND';
                $refundNeeded = $trx['total_payment'];
                
                // Store refund needed amount in meta
                $this->transactionMetaModel->insert([
                    'transaction_id' => $id,
                    'key' => 'refund_needed',
                    'value' => $refundNeeded
                ]);
            }

            $this->transactionModel->update($id, ['status' => $newStatus]);

            $this->db->transComplete();
            
            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'CANCEL',
                'target_table' => 'transaction',
                'target_id' => $id,
                'description' => "Transaction cancelled. Status: $newStatus",
            ]);

            return $this->jsonResponse->oneResp('Transaction cancelled successfully', ['status' => $newStatus], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 4. Return Product
    public function returnProduct($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;
        
        $trx = $this->transactionModel->find($id);
        if (!$trx) return $this->jsonResponse->error("Transaction not found", 404);

        $this->db->transStart();
        try {
            $cogsReversal = 0;
            $revenueReduction = 0; 

            foreach ($data->items as $item) {
                $saleItem = $this->salesProductModel
                    ->where('id_transaction', $id)
                    ->where('kode_barang', $item->kode_barang)
                    ->first();
                
                if (!$saleItem) continue;

                $qty = $item->qty;
                $isDamaged = ($item->condition === 'bad'); 

                $this->addStock($item->kode_barang, $trx['id_toko'], $qty, $id, "Retur Barang ({$item->condition})", $isDamaged);

                $modalOne = $saleItem['total_modal'] / $saleItem['jumlah'];
                $cogsReversal += ($modalOne * $qty);
                
                $priceOne = $saleItem['total'] / $saleItem['jumlah'];
                $revenueReduction += ($priceOne * $qty);
            }

            if ($cogsReversal > 0) {
                 $jid = $this->createJournal('RETUR_COGS', $id, $trx['invoice'], date('Y-m-d'), "Retur COGS Reversal", $trx['id_toko']);
                 $this->addJournalItem($jid, '1004', $cogsReversal, 0); 
                 $this->addJournalItem($jid, '5001', 0, $cogsReversal); 
            }

            if ($revenueReduction > 0) {
                $jid = $this->createJournal('RETUR_SALES', $id, $trx['invoice'], date('Y-m-d'), "Retur Sales Reduction", $trx['id_toko']);
                $this->addJournalItem($jid, '4003', $revenueReduction, 0); 
                $this->addJournalItem($jid, '1003', 0, $revenueReduction); 
            }

            $this->db->transComplete();
            
            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'RETUR',
                'target_table' => 'transaction',
                'target_id' => $id,
                'description' => "Product Return processed",
                'detail' => $data->items
            ]);
            
            // If return involves refund (money back), set status NEED_REFUND
            // Check if user requested a refund for this return or it is an exchange?
            // User requirement: "kalo dignti duit jadi need to redunf"
            // Assume input flag 'refund_money' = true
            $refundMoney = $data->refund_money ?? false;
            
            if ($refundMoney) {
                 $this->transactionModel->update($id, ['status' => 'NEED_REFUND']);
                 // Store potential refund amount? Revenue Reduction + any previous needed?
                 // For now, simple flag.
                 $this->transactionMetaModel->insert([
                    'transaction_id' => $id,
                    'key' => 'refund_needed',
                    'value' => $revenueReduction // Estimated refund amount from returned items
                ]);
            }

            return $this->jsonResponse->oneResp('Return processed successfully', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 5. Refund Money
    public function refund($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;
        
        $amount = $data->amount;
        $reason = $data->reason ?? 'Refund';

        $trx = $this->transactionModel->find($id);
        if (!$trx) return $this->jsonResponse->error("Transaction not found", 404);

        $this->db->transStart();
        try {
            $this->paymentModel->insert([
                'transaction_id' => $id,
                'amount' => -$amount,
                'payment_method' => 'REFUND',
                'status' => 'VERIFIED',
                'paid_at' => date('Y-m-d H:i:s'),
                'note' => $reason,
                'image_url' => $data->image ?? null
            ]);

            $jid = $this->createJournal('REFUND', $id, $trx['invoice'], date('Y-m-d'), "Refund: $reason", $trx['id_toko']);
            $this->addJournalItem($jid, '1003', $amount, 0); 
            $this->addJournalItem($jid, '1001', 0, $amount); 

            $newTotalPaid = $trx['total_payment'] - $amount; 
            
            $this->transactionModel->update($id, [
                'total_payment' => $newTotalPaid,
                'status' => ($newTotalPaid <= 0) ? 'REFUNDED' : 'PARTIALLY_REFUNDED' 
            ]);

            $this->db->transComplete();
            
            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'REFUND',
                'target_table' => 'transaction',
                'target_id' => $id,
                'description' => "Refund processed: $amount",
                'detail' => ['amount' => $amount, 'reason' => $reason]
            ]);

            return $this->jsonResponse->oneResp('Refund processed successfully', [], 200);

        } catch (\Exception $e) {
             return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // --- Helpers ---

    private function createJournal($refType, $refId, $refNo, $date, $desc, $tokoId = null) {
        $data = [
            'id_toko' => $tokoId,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'date' => $date,
            'description' => $desc,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $this->journalModel->insert($data);
        return $this->journalModel->getInsertID();
    }

    private function addJournalItem($journalId, $accountCode, $debit, $credit) {
        $account = $this->accountModel->where('code', $accountCode)->first();
        if (!$account) return; 

        $this->journalItemModel->insert([
            'journal_id' => $journalId,
            'account_id' => $account['id'],
            'debit' => $debit,
            'credit' => $credit,
             'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function deductStock($productCode, $tokoId, $qty, $trxId, $reason) {
        $stockEntry = $this->stockModel->where('id_barang', $productCode)->where('id_toko', $tokoId)->first();
        if (!$stockEntry) {
            $this->stockModel->insert([
                'id_barang' => $productCode,
                'id_toko' => $tokoId,
                'stock' => 0,
                'barang_cacat' => 0
            ]);
            $stockEntry = $this->stockModel->where('id_barang', $productCode)->where('id_toko', $tokoId)->first();
        }
        
        $currentStock = $stockEntry['stock'];
        $newStock = $currentStock - $qty;

        $this->stockModel->update($stockEntry['id'], ['stock' => $newStock]);

        $this->stockLedgerModel->insert([
            'id_barang' => $productCode,
            'id_toko' => $tokoId,
            'qty' => -$qty,
            'balance' => $newStock,
            'reference_type' => 'TRANSACTION',
            'reference_id' => $trxId,
            'description' => $reason
        ]);
    }

    private function addStock($productCode, $tokoId, $qty, $trxId, $reason, $isDamaged = false) {
        $stockEntry = $this->stockModel->where('id_barang', $productCode)->where('id_toko', $tokoId)->first();
        if (!$stockEntry) return; 

        if ($isDamaged) {
            $newCacat = $stockEntry['barang_cacat'] + $qty;
            $this->stockModel->update($stockEntry['id'], ['barang_cacat' => $newCacat]);
            // Log damage but usually not in normal stock ledger unless separate logic exist
            return; 
        }

        $newStock = $stockEntry['stock'] + $qty;
        $this->stockModel->update($stockEntry['id'], ['stock' => $newStock]);

        $this->stockLedgerModel->insert([
            'id_barang' => $productCode,
            'id_toko' => $tokoId,
            'qty' => $qty,
            'balance' => $newStock,
            'reference_type' => 'RETURN',
            'reference_id' => $trxId,
            'description' => $reason
        ]);
    }
}
