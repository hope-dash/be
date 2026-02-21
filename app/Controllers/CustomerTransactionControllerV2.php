<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\CartModel;
use App\Models\ProductModel;
use App\Models\StockModel;
use App\Models\TransactionModel;
use App\Models\SalesProductModel;
use App\Models\TransactionMetaModel;
use App\Models\TransactionPaymentModel;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\StockLedgerModel;
use App\Models\CustomerModel;
use App\Models\TokoModel;
use App\Models\JsonResponse;
use CodeIgniter\API\ResponseTrait;

class CustomerTransactionControllerV2 extends ResourceController
{
    use ResponseTrait;

    protected $cartModel;
    protected $productModel;
    protected $stockModel;
    protected $transactionModel;
    protected $salesProductModel;
    protected $transactionMetaModel;
    protected $paymentModel;
    protected $journalModel;
    protected $journalItemModel;
    protected $accountModel;
    protected $stockLedgerModel;
    protected $customerModel;
    protected $tokoModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        helper('log');
        $this->cartModel = new CartModel();
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->transactionModel = new TransactionModel();
        $this->salesProductModel = new SalesProductModel();
        $this->transactionMetaModel = new TransactionMetaModel();
        $this->paymentModel = new TransactionPaymentModel();
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->accountModel = new AccountModel();
        $this->stockLedgerModel = new StockLedgerModel();
        $this->customerModel = new CustomerModel();
        $this->tokoModel = new TokoModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // --- CART API ---

    public function getCart()
    {
        try {
            $customerId = $this->request->customer['id'];

            $items = $this->cartModel
                ->select('cart.*, product.id as product_table_id, product.nama_barang, product.harga_jual, model_barang.nama_model, seri.seri, toko.toko_name, toko.alamat as toko_alamat')
                ->join('product', 'product.id_barang = cart.id_barang', 'left')
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->join('toko', 'toko.id = cart.id_toko', 'left')
                ->where('customer_id', $customerId)
                ->findAll();

            // Batch-fetch first image for each product in one query
            $productTableIds = array_unique(array_filter(array_column($items, 'product_table_id')));
            $imageMap = [];
            if (!empty($productTableIds)) {
                $images = $this->db->table('image')
                    ->select('kode, url')
                    ->where('type', 'product')
                    ->whereIn('kode', $productTableIds)
                    ->get()->getResultArray();

                foreach ($images as $img) {
                    // Only save the first image per product
                    if (!isset($imageMap[$img['kode']])) {
                        $imageMap[$img['kode']] = $img['url'];
                    }
                }
            }

            $grouped = [];
            foreach ($items as $item) {
                $idToko = $item['id_toko'] ?: 0;

                if (!isset($grouped[$idToko])) {
                    $grouped[$idToko] = [
                        'id_toko' => $item['id_toko'],
                        'toko_name' => $item['toko_name'] ?? 'Pusat / General',
                        'toko_alamat' => $item['toko_alamat'] ?? '',
                        'items' => []
                    ];
                }

                $namaLengkap = trim(implode(' ', array_filter([
                    $item['nama_barang'],
                    $item['nama_model'] ?? '',
                    $item['seri'] ?? ''
                ])));

                $grouped[$idToko]['items'][] = [
                    'id' => $item['id'],
                    'id_barang' => $item['id_barang'],
                    'nama_barang' => $item['nama_barang'],
                    'nama_lengkap_barang' => $namaLengkap,
                    'harga_jual' => (int) $item['harga_jual'],
                    'jumlah' => (int) $item['jumlah'],
                    'image' => $imageMap[$item['product_table_id']] ?? null
                ];
            }

            return $this->jsonResponse->oneResp('Cart items fetched', array_values($grouped));
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function saveCart()
    {
        try {
            $customerId = $this->request->customer['id'];
            $data = $this->request->getJSON();

            if (empty($data->id_barang) || empty($data->jumlah)) {
                return $this->jsonResponse->error("Product ID and quantity are required", 400);
            }

            $qty = (int) $data->jumlah;
            $idToko = $data->id_toko ?? null;

            // Check if same product and same store already in cart
            $query = $this->cartModel->where('customer_id', $customerId)
                ->where('id_barang', $data->id_barang);

            if ($idToko) {
                $query->where('id_toko', $idToko);
            } else {
                $query->where('id_toko', null);
            }

            $existing = $query->first();

            if ($existing) {
                // If exists, increment quantity
                $this->cartModel->update($existing['id'], [
                    'jumlah' => (int) $existing['jumlah'] + $qty
                ]);
                $message = "Cart item incremented";
            } else {
                // If new, insert
                $this->cartModel->insert([
                    'customer_id' => $customerId,
                    'id_barang' => $data->id_barang,
                    'jumlah' => $qty,
                    'id_toko' => $idToko
                ]);
                $message = "Item added to cart";
            }

            return $this->jsonResponse->oneResp($message, [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function updateCart($id = null)
    {
        try {
            $customerId = $this->request->customer['id'];
            $data = $this->request->getJSON();

            if (empty($data->jumlah)) {
                return $this->jsonResponse->error("Quantity is required", 400);
            }

            $item = $this->cartModel->find($id);
            if (!$item || $item['customer_id'] != $customerId) {
                return $this->jsonResponse->error("Item not found in your cart", 404);
            }

            $this->cartModel->update($id, [
                'jumlah' => (int) $data->jumlah
            ]);

            return $this->jsonResponse->oneResp('Cart item updated', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function clearCart()
    {
        try {
            $customerId = $this->request->customer['id'];
            $this->cartModel->where('customer_id', $customerId)->delete();
            return $this->jsonResponse->oneResp('Cart cleared', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function deleteCartItem($id = null)
    {
        try {
            $customerId = $this->request->customer['id'];
            $item = $this->cartModel->find($id);

            if (!$item || $item['customer_id'] != $customerId) {
                return $this->jsonResponse->error("Item not found in your cart", 404);
            }

            $this->cartModel->delete($id);
            return $this->jsonResponse->oneResp('Item removed from cart', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // --- CHECKOUT API ---

    public function checkout()
    {
        $data = $this->request->getJSON(true);
        $customerToken = $this->request->customer;
        $customerId = $customerToken['id'];

        // Fetch fresh customer data for discounts and address
        $customer = $this->customerModel->find($customerId);
        if (!$customer) {
            return $this->jsonResponse->error("Customer not found", 404);
        }

        $cartIds = $data['cart_ids'] ?? [];
        if (empty($cartIds)) {
            return $this->jsonResponse->error("No cart items selected", 400);
        }

        $this->db->transStart();

        try {
            // 1. Fetch cart items with product details
            $cartItems = $this->cartModel
                ->select('cart.*, product.nama_barang, product.harga_jual, product.harga_modal')
                ->join('product', 'product.id_barang = cart.id_barang')
                ->whereIn('cart.id', $cartIds)
                ->where('cart.customer_id', $customerId)
                ->findAll();

            if (empty($cartItems)) {
                throw new \Exception("Selected cart items not found");
            }

            // 2. Group by id_toko
            $groupedByStore = [];
            foreach ($cartItems as $item) {
                $idToko = $item['id_toko'] ?: 0;
                $groupedByStore[$idToko][] = $item;
            }

            $createdInvoices = [];

            // Build a lookup: id_toko => { pengiriman, biaya_pengiriman, discount_type, discount_amount }
            $shippingMap = [];
            foreach (($data['stores'] ?? []) as $storeShipping) {
                $storeKey = ($storeShipping['id_toko'] ?? 0) ?: 0;
                $shippingMap[$storeKey] = [
                    'pengiriman' => $storeShipping['pengiriman'] ?? '',
                    'biaya_pengiriman' => (float) ($storeShipping['biaya_pengiriman'] ?? 0),
                    'discount_type' => $storeShipping['discount_type'] ?? 'FIXED',
                    'discount_amount' => (float) ($storeShipping['discount_amount'] ?? 0)
                ];
            }

            foreach ($groupedByStore as $idToko => $items) {
                $grossAmount = 0;
                $totalModal = 0;
                $itemsProcessed = [];
                $actualIdToko = ($idToko == 0) ? null : $idToko;

                // 3. Stock Check & Pricing Logic
                foreach ($items as $item) {
                    $idBarang = $item['id_barang'];
                    $qty = (int) $item['jumlah'];

                    // Check Stock
                    $stockEntry = $this->stockModel
                        ->where('id_barang', $idBarang)
                        ->where('id_toko', $actualIdToko)
                        ->first();

                    if (!$stockEntry || $stockEntry['stock'] < $qty) {
                        throw new \Exception("Insufficient stock for {$item['nama_barang']} in selected store");
                    }

                    // Price Logic (Customer Discount)
                    $originalPrice = (float) $item['harga_jual'];
                    $itemDiscountValue = 0;
                    if (!empty($customer['discount_type'])) {
                        $itemDiscountValue = $this->calculateDiscount($originalPrice, $customer['discount_type'], (float) $customer['discount_value']);
                    }
                    $finalPrice = max(0, $originalPrice - $itemDiscountValue);

                    $grossAmount += ($originalPrice * $qty);
                    $totalModal += ($item['harga_modal'] * $qty);

                    $itemsProcessed[] = [
                        'kode_barang' => $idBarang,
                        'qty' => $qty,
                        'original_price' => $originalPrice,
                        'final_price' => $finalPrice,
                        'modal' => $item['harga_modal'],
                        'total_modal_item' => $item['harga_modal'] * $qty,
                        'discount_value' => $itemDiscountValue * $qty
                    ];
                }

                // Per-store shipping & discount
                $storeConfig = $shippingMap[$idToko] ?? [
                    'pengiriman' => '',
                    'biaya_pengiriman' => 0,
                    'discount_type' => 'FIXED',
                    'discount_amount' => 0
                ];
                $shippingCost = (float) $storeConfig['biaya_pengiriman'];
                $pengirimanCourier = $storeConfig['pengiriman'];

                $totalItemDiscount = array_sum(array_column($itemsProcessed, 'discount_value'));
                $subtotalAfterItemDiscount = $grossAmount - $totalItemDiscount;

                $txnDiscountType = $storeConfig['discount_type'];
                $txnDiscountAmount = $storeConfig['discount_amount'];
                $txnDiscountValue = $this->calculateDiscount($subtotalAfterItemDiscount, $txnDiscountType, $txnDiscountAmount);

                $finalTotalDiscount = $totalItemDiscount + $txnDiscountValue;
                $grandTotal = ($subtotalAfterItemDiscount - $txnDiscountValue) + $shippingCost;

                // 4. Create Transaction
                $trxData = [
                    'invoice' => 'CS-TMP-' . time() . '-' . rand(100, 999),
                    'id_toko' => $actualIdToko,
                    'amount' => $grossAmount,
                    'actual_total' => $grandTotal,
                    'total_payment' => 0,
                    'status' => 'WAITING_PAYMENT',
                    'delivery_status' => 'NOT_READY',
                    'discount_type' => $txnDiscountType,
                    'discount_amount' => $txnDiscountAmount,
                    'total_modal' => $totalModal,
                    'created_by' => 0, // Customer trigger
                    'date_time' => date('Y-m-d H:i:s'),
                ];

                $this->transactionModel->insert($trxData);
                $trxId = $this->transactionModel->getInsertID();

                $invoiceNo = 'INV' . date('ymd') . $trxId;
                $this->transactionModel->update($trxId, ['invoice' => $invoiceNo]);

                // 5. Meta Data
                $metaEntries = [
                    'customer_id' => $customerId,
                    'alamat' => $data['alamat'] ?? $customer['alamat'],
                    'provinsi' => $data['provinsi'] ?? $customer['provinsi'],
                    'kota_kabupaten' => $data['kota_kabupaten'] ?? $customer['kota_kabupaten'],
                    'kecamatan' => $data['kecamatan'] ?? $customer['kecamatan'],
                    'kelurahan' => $data['kelurahan'] ?? $customer['kelurahan'],
                    'kode_pos' => $data['kode_pos'] ?? $customer['kode_pos'],
                    'pengiriman' => $pengirimanCourier,
                    'biaya_pengiriman' => $shippingCost,
                    'tx_discount_value' => $txnDiscountValue,
                    'item_discount_total' => $totalItemDiscount,
                    'source' => 'CUSTOMER_PORTAL'
                ];

                foreach ($metaEntries as $k => $v) {
                    $this->transactionMetaModel->insert([
                        'transaction_id' => $trxId,
                        'key' => $k,
                        'value' => (string) $v
                    ]);
                }

                // 6. Sales Products & Stock Deduction & Journaling
                foreach ($itemsProcessed as $it) {
                    $this->salesProductModel->insert([
                        'id_transaction' => $trxId,
                        'kode_barang' => $it['kode_barang'],
                        'jumlah' => $it['qty'],
                        'harga_system' => $it['original_price'],
                        'harga_jual' => $it['final_price'],
                        'total' => $it['final_price'] * $it['qty'],
                        'modal_system' => $it['modal'],
                        'total_modal' => $it['total_modal_item'],
                        'actual_per_piece' => $it['final_price'],
                        'actual_total' => $it['final_price'] * $it['qty']
                    ]);

                    $this->deductStock($it['kode_barang'], $actualIdToko, $it['qty'], $trxId, "Invoice {$invoiceNo}");
                }

                // Accounting: Sales Journal (Matches TransactionControllerV2 logic)
                $journalId = $this->createJournal('SALES', $trxId, $invoiceNo, date('Y-m-d'), "Invoice #{$invoiceNo}", $actualIdToko);

                // Dr AR (1003)
                $this->addJournalItem($journalId, '10' . $actualIdToko . '3', $grandTotal, 0, $actualIdToko);

                // Dr Sales Discount (4002) - Combined (Item + TXN)
                if ($finalTotalDiscount > 0) {
                    $this->addJournalItem($journalId, '40' . $actualIdToko . '2', $finalTotalDiscount, 0, $actualIdToko);
                }

                // Cr Sales Revenue (4001) - Gross
                $this->addJournalItem($journalId, '40' . $actualIdToko . '1', 0, $grossAmount, $actualIdToko);

                // Cr Shipping Revenue if any
                if ($shippingCost > 0) {
                    $this->addJournalItem($journalId, '40' . $actualIdToko . '1', 0, $shippingCost, $actualIdToko);
                }

                // Accounting: COGS Journal
                if ($totalModal > 0) {
                    $cogsJournalId = $this->createJournal('COGS', $trxId, $invoiceNo, date('Y-m-d'), "COGS Invoice {$invoiceNo}", $actualIdToko);
                    $this->addJournalItem($cogsJournalId, '50' . $actualIdToko . '1', $totalModal, 0, $actualIdToko); // Dr COGS
                    $this->addJournalItem($cogsJournalId, '10' . $actualIdToko . '4', 0, $totalModal, $actualIdToko); // Cr Inventory
                }

                $createdInvoices[] = [
                    'id' => $trxId,
                    'invoice' => $invoiceNo,
                    'store' => $idToko,
                    'total' => $grandTotal
                ];
            }

            // 7. Clear successful items from cart
            $this->cartModel->whereIn('id', $cartIds)->delete();

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw new \Exception("Database transaction failed");
            }

            return $this->jsonResponse->oneResp('Checkout successful', $createdInvoices, 201);

        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // --- PAYMENT API ---

    public function uploadPaymentProof()
    {
        try {
            $customerId = $this->request->customer['id'];
            $data = $this->request->getJSON();

            if (empty($data->transaction_id) || empty($data->image_url)) {
                return $this->jsonResponse->error("Transaction ID and proof image are required", 400);
            }

            $trx = $this->transactionModel->find($data->transaction_id);
            if (!$trx) {
                return $this->jsonResponse->error("Transaction not found", 404);
            }

            // Verify it belongs to this customer
            $meta = $this->transactionMetaModel
                ->where('transaction_id', $data->transaction_id)
                ->where('key', 'customer_id')
                ->where('value', (string) $customerId)
                ->first();

            if (!$meta) {
                return $this->jsonResponse->error("Unauthorized access to this transaction", 403);
            }

            $this->db->transStart();

            $this->paymentModel->insert([
                'transaction_id' => $data->transaction_id,
                'amount' => $trx['actual_total'],
                'payment_method' => 'TRANSFER',
                'status' => 'PENDING', // Waiting verification
                'paid_at' => date('Y-m-d H:i:s'),
                'image_url' => $data->image_url,
                'note' => $data->note ?? 'Payment from customer app'
            ]);

            $this->transactionModel->update($data->transaction_id, [
                'status' => 'WAITING_VERIFICATION'
            ]);

            $this->db->transComplete();

            return $this->jsonResponse->oneResp('Payment proof uploaded successfully. Waiting for verification.', [], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
    // --- TRANSACTION HISTORY API ---
    public function getTransactions()
    {
        try {
            $customerId = $this->request->customer['id'];
            $limit = (int) $this->request->getGet('limit') ?: 10;
            $page = (int) $this->request->getGet('page') ?: 1;
            $offset = ($page - 1) * $limit;

            $builder = $this->transactionModel
                ->select('transaction.*')
                ->join('transaction_meta', 'transaction_meta.transaction_id = transaction.id')
                ->where('transaction_meta.key', 'customer_id')
                ->where('transaction_meta.value', (string) $customerId);

            $totalData = $builder->countAllResults(false);
            $totalPage = ceil($totalData / $limit);

            $transactions = $builder
                ->orderBy('transaction.date_time', 'DESC')
                ->limit($limit, $offset)
                ->findAll();

            // Fetch first item name for each transaction for summary
            foreach ($transactions as &$trx) {
                $firstItem = $this->salesProductModel
                    ->select('sales_product.kode_barang, product.nama_barang')
                    ->join('product', 'product.id_barang = sales_product.kode_barang', 'left')
                    ->where('id_transaction', $trx['id'])
                    ->first();

                $trx['summary_item'] = $firstItem['nama_barang'] ?? 'Product';

                // Fetch item count
                $itemCount = $this->salesProductModel->where('id_transaction', $trx['id'])->countAllResults();
                $trx['item_count'] = $itemCount;

                // Format status labels
                $trx['status_label'] = $this->transactionModel->getStatuses()[$trx['status']] ?? $trx['status'];
            }

            return $this->jsonResponse->multiResp('Transactions fetched', $transactions, $totalData, $totalPage, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // --- HELPERS ---

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

    private function deductStock($kodeBarang, $tokoId, $qty, $refId, $desc)
    {
        $stock = $this->stockModel
            ->where('id_barang', $kodeBarang)
            ->where('id_toko', $tokoId)
            ->first();

        $newStock = ($stock ? $stock['stock'] : 0) - $qty;

        if ($stock) {
            $this->stockModel->update($stock['id'], ['stock' => $newStock]);
        } else {
            $this->stockModel->insert([
                'id_barang' => $kodeBarang,
                'id_toko' => $tokoId,
                'stock' => -$qty,
                'barang_cacat' => 0
            ]);
        }

        $this->stockLedgerModel->insert([
            'id_barang' => $kodeBarang,
            'id_toko' => $tokoId,
            'qty' => -$qty,
            'balance' => $newStock,
            'reference_type' => 'SALES',
            'reference_id' => $refId,
            'description' => $desc
        ]);
    }

    private function calculateDiscount($subtotal, $type, $amount)
    {
        if (strtoupper($type) === 'PERCENTAGE' || strtoupper($type) === 'PERCENT') {
            return ($subtotal * $amount) / 100;
        }
        return $amount;
    }
}
