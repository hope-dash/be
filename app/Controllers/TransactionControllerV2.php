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
use App\Libraries\TenantContext;
use App\Libraries\SubscriptionService;
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
        helper(['log', 'email_helper']);
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

        $tenantId = TenantContext::id();
        $subscriptionService = new SubscriptionService($this->db);
        $quotaCheck = $subscriptionService->canCreateTransactionsThisMonth($tenantId, 1);
        if (!($quotaCheck['ok'] ?? false)) {
            return $this->jsonResponse->error($quotaCheck['message'] ?? 'Kuota transaksi bulanan habis', $quotaCheck['code'] ?? 403);
        }

        $this->db->transStart();

        try {
            $items = $data->items ?? $data->item ?? []; // Support both keys
            if (empty($items))
                throw new \Exception("Item tidak boleh kosong");

            // -- 0. Customer Handling --
            $customerId = $data->customer_id ?? null;
            if (empty($customerId) && !empty($data->customer_phone)) {
                // Check if customer exists by phone
                $existingCust = $this->customerModel->where('no_hp_customer', $data->customer_phone)->first();
                if ($existingCust) {
                    $customerId = $existingCust['id'];
                }
                else {
                    // Create new customer
                    $custData = [
                        'nama_customer' => $data->customer_name ?? 'Guest',
                        'no_hp_customer' => $data->customer_phone,
                        'alamat' => $data->alamat ?? '',
                        'provinsi' => $data->provinsi ?? null,
                        'kota_kabupaten' => $data->kota_kabupaten ?? $data->kota ?? null,
                        'kecamatan' => $data->kecamatan ?? null,
                        'kelurahan' => $data->kelurahan ?? null,
                        'kode_pos' => $data->kode_pos ?? null,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $this->customerModel->insert($custData);
                    $customerId = $this->customerModel->getInsertID();
                }
            }


            // -- 1. Calculate Totals --
            $grossAmount = 0; // Total sum of (price * qty) before any discounts
            $totalItemDiscount = 0;
            $itemsProcessed = [];
            $totalModal = 0;

            foreach ($items as $item) {
                // Compatible with both 'id_barang' and 'kode_barang'
                $idBarang = $item->id_barang ?? $item->kode_barang ?? null;
                if (!$idBarang)
                    throw new \Exception("Kode/ID item tidak ditemukan");

                // Compatible with 'price' or 'harga_jual'
                $price = $item->price ?? $item->harga_jual ?? 0;
                $qty = $item->qty ?? $item->jumlah ?? 0;

                $product = $this->productModel->where('id_barang', $idBarang)->first();
                if (!$product)
                    throw new \Exception("Product {$idBarang} not found");

                // Item Level Discount
                $itemDiscountType = $item->discount_type ?? 'FIXED';
                $itemDiscountAmount = $item->discount_amount ?? 0;
                $itemTotal = $price * $qty;
                $itemDiscountValue = 0;

                if (strtoupper($itemDiscountType) === 'PERCENTAGE' || strtoupper($itemDiscountType) === 'PERCENT') {
                    $itemDiscountType = 'PERCENTAGE';
                    $itemDiscountValue = ($itemTotal * $itemDiscountAmount) / 100;
                }
                else {
                    $itemDiscountType = 'FIXED';
                    $itemDiscountValue = $itemDiscountAmount;
                }

                // Validate Stock
                $stockEntry = $this->stockModel->where('id_barang', $idBarang)->where('id_toko', $data->id_toko)->first();
                $currentStock = $stockEntry ? $stockEntry['stock'] : 0;
                if ($currentStock < $qty) {
                    throw new \Exception("Insufficient stock for {$product['nama_barang']}");
                }

                $grossAmount += $itemTotal;
                $totalItemDiscount += $itemDiscountValue;

                $modal = $product['harga_modal'] * $qty;
                $totalModal += $modal;

                $itemsProcessed[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'price' => $price,
                    'discount_type' => $itemDiscountType,
                    'discount_amount' => $itemDiscountAmount,
                    'discount_value' => $itemDiscountValue,
                    'total_modal' => $modal
                ];
            }

            // Transaction Level Discount
            $txDiscountType = $data->discount_type ?? 'FIXED';
            $txDiscountAmount = $data->discount_amount ?? 0;
            $itemActualSubtotal = $grossAmount - $totalItemDiscount;
            $txDiscountValue = 0;

            if (strtoupper($txDiscountType) === 'PERCENTAGE' || strtoupper($txDiscountType) === 'PERCENT') {
                $txDiscountType = 'PERCENTAGE';
                $txDiscountValue = ($itemActualSubtotal * $txDiscountAmount) / 100;
            }
            else {
                $txDiscountType = 'FIXED';
                $txDiscountValue = $txDiscountAmount;
            }

            $totalDiscount = $totalItemDiscount + $txDiscountValue;
            $afterDiscountSubtotal = $itemActualSubtotal - $txDiscountValue;

            // PPN Calculation
            $ppnPercent = $data->ppn ?? 0;
            $ppnValue = ($afterDiscountSubtotal * $ppnPercent) / 100;

            // Shipping Cost Logic
            $shippingCost = $data->biaya_pengiriman ?? 0;
            $isFreeOngkir = $data->free_ongkir ?? false;

            // Grand Total Calculation
            // actual_total = total dari amount stlh discount dan ada ppn atau ongkir lain lain
            $grandTotal = $afterDiscountSubtotal + $ppnValue;

            if (!$isFreeOngkir) {
                $grandTotal += $shippingCost;
            }

            if ($grandTotal < 0)
                $grandTotal = 0;

            // -- Insert Transaction --
            $trxData = [
                'invoice' => 'INV-TMP-' . time(),
                'id_toko' => $data->id_toko,
                'amount' => $grossAmount, // amount = total dari semua total barang
                'actual_total' => $grandTotal, // actual_total = total dari amount stlh discount dan ada ppn atau ongkir lain lain
                'total_payment' => 0,
                'status' => 'WAITING_PAYMENT',
                'delivery_status' => 'NOT_READY',
                'discount_type' => $txDiscountType,
                'discount_amount' => $txDiscountAmount,
                'total_modal' => $totalModal,
                'po' => $data->po ?? false,
                'created_by' => $userId,
                'date_time' => date('Y-m-d H:i:s'),
            ];

            $this->transactionModel->insert($trxData);
            $trxId = $this->transactionModel->getInsertID();
            $subscriptionService->incrementTransactionUsed($tenantId, 1);

            // Update Invoice to use ID
            $invoice = 'INV' . date('ymd') . $trxId;
            $this->transactionModel->update($trxId, ['invoice' => $invoice]);
            $trxData['invoice'] = $invoice; // Update local variable for later use in journals/logs

            // Save Metadata (Customer, Shipping info, etc)
            $metaData = [
                'customer_id' => $customerId,
                'customer_name' => $data->customer_name ?? '',
                'customer_phone' => $data->customer_phone ?? '',
                'alamat' => $data->alamat ?? '',
                'provinsi' => $data->provinsi ?? $data->nama_rovinsi ?? '',
                'kota_kabupaten' => $data->kota_kabupaten ?? $data->kota ?? $data->nama_kota_kabupaten ?? $data->nama_kota ?? '',
                'kecamatan' => $data->kecamatan ?? $data->nama_kecamatan ?? '',
                'kelurahan' => $data->kelurahan ?? $data->nama_kelurahan ?? '',
                'kode_pos' => $data->kode_pos ?? '',
                'source' => $data->source ?? '',
                'jatuh_tempo' => $data->jatuh_tempo ?? '',
                'pengiriman' => $data->pengiriman ?? '',
                'berat' => $data->berat ?? '',
                'biaya_pengiriman' => $shippingCost,
                'free_ongkir' => $isFreeOngkir ? '1' : '0',
                'ppn' => $ppnPercent,
                'ppn_value' => $ppnValue,
                'item_discount_total' => $totalItemDiscount,
                'tx_discount_value' => $txDiscountValue
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
            $this->addJournalItem($journalId, '10' . $data->id_toko . '3', $grandTotal, 0, $data->id_toko);

            // 2. Dr Discount (if any)
            if ($totalDiscount > 0) {
                $this->addJournalItem($journalId, '40' . $data->id_toko . '2', $totalDiscount, 0, $data->id_toko);
            }

            // 3. Cr Sales Revenue (Gross Sales from Items)
            $this->addJournalItem($journalId, '40' . $data->id_toko . '1', 0, $grossAmount, $data->id_toko);

            // 4. Cr PPN Keluaran (Output Tax) (2005)
            if ($ppnValue > 0) {
                $this->addJournalItem($journalId, '20' . $data->id_toko . '5', 0, $ppnValue, $data->id_toko);
            }

            // 5. Shipping Logic
            if (!$isFreeOngkir && $shippingCost > 0) {
                $this->addJournalItem($journalId, '40' . $data->id_toko . '1', 0, $shippingCost, $data->id_toko);
            }

            if ($isFreeOngkir && $shippingCost > 0) {
                $this->addJournalItem($journalId, '50' . $data->id_toko . '6', $shippingCost, 0, $data->id_toko); // Dr Expense
                $this->addJournalItem($journalId, '20' . $data->id_toko . '1', 0, $shippingCost, $data->id_toko); // Cr Payable
            }


            // -- Process Items: Stock & COGS --
            $salesProductData = [];
            $cogsTotal = 0;

            foreach ($itemsProcessed as $itemData) {
                $p = $itemData['product'];
                $qty = $itemData['qty'];
                $price = $itemData['price'];
                $modal = $itemData['total_modal'];
                $discountValue = $itemData['discount_value'];

                // Deduct Stock
                $this->deductStock($p['id_barang'], $data->id_toko, $qty, $trxId, "Invoice {$trxData['invoice']}");

                $priceAfterDiscount = ($qty > 0) ? ($qty * $price - $discountValue) / $qty : 0;
                $totalAfterDiscount = $qty * $price - $discountValue;

                $salesProductData[] = [
                    'tenant_id' => $tenantId,
                    'id_transaction' => $trxId,
                    'kode_barang' => $p['id_barang'],
                    'jumlah' => $qty,
                    'harga_system' => $price,
                    'harga_jual' => $priceAfterDiscount,
                    'total' => $totalAfterDiscount,
                    'modal_system' => $p['harga_modal'],
                    'total_modal' => $modal,
                    'actual_per_piece' => $priceAfterDiscount,
                    'actual_total' => $totalAfterDiscount,
                    'discount_type' => $itemData['discount_type'],
                    'discount_amount' => $itemData['discount_amount']
                ];

                $cogsTotal += $modal;
            }
            $this->salesProductModel->insertBatch($salesProductData);

            // -- Accounting: COGS Journal --
            if ($cogsTotal > 0) {
                $cogsJournalId = $this->createJournal('COGS', $trxId, $trxData['invoice'], date('Y-m-d'), "COGS Invoice {$trxData['invoice']}", $data->id_toko);
                $this->addJournalItem($cogsJournalId, '50' . $data->id_toko . '1', $cogsTotal, 0, $data->id_toko); // Dr COGS
                $this->addJournalItem($cogsJournalId, '10' . $data->id_toko . '4', 0, $cogsTotal, $data->id_toko); // Cr Inventory
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->jsonResponse->error('Transaksi gagal disimpan', 500);
            }

            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'CREATE',
                'target_table' => 'transaction',
                'target_id' => $trxId,
                'description' => "Created transaction {$trxData['invoice']}",
                'detail' => $trxData
            ]);

            // -- 0. Async Notifications --
            if ($customerId) {
                try {
                    helper('email');

                    // Fetch full customer data for email
                    $customer = $this->customerModel->find($customerId);

                    // Fetch toko bank info
                    $tokoModel = new \App\Models\TokoModel();
                    $toko = $tokoModel->find($data->id_toko);

                    if ($customer && !empty($customer['email'])) {
                        $emailData = array_merge($trxData, [
                            'id' => $trxId,
                            'customer' => $customer,
                            'bank' => $toko['bank'] ?? '',
                            'nomer_rekening' => $toko['nomer_rekening'] ?? '',
                            'nama_pemilik' => $toko['nama_pemilik'] ?? '',
                            'actual_total' => $grandTotal
                        ]);

                        send_invoice_email($emailData);
                    }
                }
                catch (\Exception $e) {
                    // Log error but don't fail the transaction
                    log_message('error', 'Failed to enqueue invoice email: ' . $e->getMessage());
                }
            }

            return $this->jsonResponse->oneResp('Transaksi berhasil dibuat', ['id' => $trxId], 201);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 1.5 Calculate/Count Transaction (Preview)
    public function calculate()
    {
        try {
            $data = $this->request->getJSON();
            $items = $data->items ?? $data->item ?? [];
            if (empty($items))
                throw new \Exception("Item tidak boleh kosong");

            $grossAmount = 0;
            $totalItemDiscount = 0;

            foreach ($items as $item) {
                $price = $item->price ?? $item->harga_jual ?? 0;
                $qty = $item->qty ?? $item->jumlah ?? 0;

                $itemDiscountType = $item->discount_type ?? 'FIXED';
                $itemDiscountAmount = $item->discount_amount ?? 0;
                $itemTotal = $price * $qty;
                $itemDiscountValue = 0;

                if (strtoupper($itemDiscountType) === 'PERCENTAGE' || strtoupper($itemDiscountType) === 'PERCENT') {
                    $itemDiscountValue = ($itemTotal * $itemDiscountAmount) / 100;
                }
                else {
                    $itemDiscountValue = $itemDiscountAmount;
                }

                $grossAmount += $itemTotal;
                $totalItemDiscount += $itemDiscountValue;
            }

            // Transaction Level Discount
            $txDiscountType = $data->discount_type ?? 'FIXED';
            $txDiscountAmount = $data->discount_amount ?? 0;
            $itemActualSubtotal = $grossAmount - $totalItemDiscount;
            $txDiscountValue = 0;

            if (strtoupper($txDiscountType) === 'PERCENTAGE' || strtoupper($txDiscountType) === 'PERCENT') {
                $txDiscountValue = ($itemActualSubtotal * $txDiscountAmount) / 100;
            }
            else {
                $txDiscountValue = $txDiscountAmount;
            }

            $afterDiscountSubtotal = $itemActualSubtotal - $txDiscountValue;

            // PPN Calculation
            $ppnPercent = $data->ppn ?? 10;
            $ppnValue = ($afterDiscountSubtotal * $ppnPercent) / 100;

            // Shipping Cost
            $shippingCost = $data->biaya_pengiriman ?? 0;
            $isFreeOngkir = $data->free_ongkir ?? false;

            $chargedShipping = $isFreeOngkir ? 0 : $shippingCost;
            $grandTotal = $afterDiscountSubtotal + $ppnValue + $chargedShipping;

            return $this->jsonResponse->oneResp('Kalkulasi berhasil', [
                'subtotal' => $grossAmount,
                'diskon_item' => $totalItemDiscount,
                'diskon_tambahan' => $txDiscountValue,
                'subtotal_setelah_diskon' => $afterDiscountSubtotal,
                'ppn' => $ppnValue,
                'biaya_pengiriman' => $chargedShipping,
                'grand_total' => $grandTotal
            ], 200);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 2. Add Payment
    public function addPayment($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;

        $trx = $this->transactionModel->find($id);
        if (!$trx)
            return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);

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

            $accountCode = ($method == 'CASH') ? '10' . $trx['id_toko'] . '1' : '10' . $trx['id_toko'] . '2';
            $journalId = $this->createJournal('PAYMENT', $id, $trx['invoice'], date('Y-m-d'), "Payment for {$trx['invoice']}", $trx['id_toko']);
            $this->addJournalItem($journalId, $accountCode, $amount, 0, $trx['id_toko']); // Dr Cash
            $this->addJournalItem($journalId, '10' . $trx['id_toko'] . '3', 0, $amount, $trx['id_toko']); // Cr AR

            $newTotalPaid = $trx['total_payment'] + $amount;
            $newStatus = ($newTotalPaid >= $trx['actual_total']) ? 'PAID' : 'PARTIALLY_PAID';

            $this->transactionModel->update($id, [
                'total_payment' => $newTotalPaid,
                'status' => $newStatus,
                'delivery_status' => 'PENDING'
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

            return $this->jsonResponse->oneResp('Pembayaran berhasil ditambahkan', ['new_status' => $newStatus], 200);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function verifyPayment($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;
        $action = strtoupper($data->action ?? ''); // ACCEPT or REJECT

        if (!$id) {
            return $this->jsonResponse->error("ID wajib diisi", 400);
        }

        $trx = $this->transactionModel->find($id);
        if (!$trx) {
            return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);
        }

        // Find the pending payment for this transaction
        $payment = $this->paymentModel->where('transaction_id', $id)
            ->where('status', 'PENDING')
            ->orderBy('id', 'DESC')
            ->first();

        if (!$payment) {
            return $this->jsonResponse->error("Tidak ada pembayaran tertunda yang ditemukan untuk transaksi ini", 404);
        }

        $this->db->transStart();
        try {
            if ($action === 'REJECT') {
                // Update payment status
                $this->paymentModel->update($payment['id'], ['status' => 'REJECTED']);

                // Set transaction status back to WAITING_PAYMENT
                $newStatus = 'WAITING_PAYMENT';
                $this->transactionModel->update($id, [
                    'status' => $newStatus
                ]);

                log_aktivitas([
                    'user_id' => $userId,
                    'action_type' => 'PAYMENT_REJECTED',
                    'target_table' => 'transaction',
                    'target_id' => $id,
                    'description' => "Rejected payment of {$payment['amount']} for {$trx['invoice']}. Reason: " . ($data->reason ?? 'None'),
                    'detail' => ['payment_id' => $payment['id'], 'reason' => $data->reason ?? null]
                ]);
            }
            else if ($action === 'ACCEPT') {
                // Update payment status
                $this->paymentModel->update($payment['id'], ['status' => 'VERIFIED']);

                // Journal Entry (Following addPayment logic)
                // Transfers typically go to Bank (1002)
                $accountCode = '10' . $trx['id_toko'] . '2';
                $amount = (float)$payment['amount'];

                $journalId = $this->createJournal('PAYMENT', $id, $trx['invoice'], date('Y-m-d'), "Payment verification for {$trx['invoice']}", $trx['id_toko']);
                $this->addJournalItem($journalId, $accountCode, $amount, 0, $trx['id_toko']); // Dr Bank
                $this->addJournalItem($journalId, '10' . $trx['id_toko'] . '3', 0, $amount, $trx['id_toko']); // Cr AR

                // Update Transaction Status
                $newTotalPaid = (float)$trx['total_payment'] + $amount;
                // actual_total is the target. If it's reached, it's PAID.
                $newStatus = ($newTotalPaid >= (float)$trx['actual_total']) ? 'PAID' : 'PARTIALLY_PAID';

                $this->transactionModel->update($id, [
                    'total_payment' => $newTotalPaid,
                    'status' => $newStatus,
                    'delivery_status' => 'PENDING'
                ]);

                log_aktivitas([
                    'user_id' => $userId,
                    'action_type' => 'PAYMENT_VERIFIED',
                    'target_table' => 'transaction',
                    'target_id' => $id,
                    'description' => "Accepted payment of {$amount} for {$trx['invoice']}",
                    'detail' => ['payment_id' => $payment['id'], 'amount' => $amount]
                ]);
            }
            else {
                throw new \Exception("Aksi tidak valid: $action. Gunakan ACCEPT atau REJECT.");
            }

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new \Exception("Transaksi database gagal");
            }

            if ($this->db->transStatus() !== false) {
                // Fetch customer information for email
                $custData = $this->transactionMetaModel
                    ->select('customer.email, customer.nama_customer')
                    ->join('customer', 'customer.id = transaction_meta.value', 'left')
                    ->where('transaction_meta.transaction_id', $id)
                    ->where('transaction_meta.key', 'customer_id')
                    ->first();

                if ($custData) {
                    $trx['customer'] = $custData;
                    if ($action === 'ACCEPT') {
                        send_payment_confirmed_email($trx);
                    }
                    else if ($action === 'REJECT') {
                        send_payment_rejected_email($trx, $data->reason ?? '');
                    }
                }
            }

            return $this->jsonResponse->oneResp("Pembayaran berhasil di" . strtolower($action), ['new_status' => $newStatus ?? null], 200);

        }
        catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function adjust($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;

        $trx = $this->transactionModel->find($id);
        if (!$trx)
            return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);

        // Expecting $data->adjustments = [{ category: 'Diskon', component_name: 'Diskon Tambahan', type: 'addition', amount: 1000 }, ...]
        // For backwards compatibility, allow single object too
        $adjustments = [];
        if (isset($data->adjustments) && is_array($data->adjustments)) {
            $adjustments = $data->adjustments;
        }
        else {
            $adjustments[] = [
                'category' => $data->category ?? '',
                'component_name' => $data->component_name ?? 'Penambahan/Pengurangan',
                'type' => $data->type ?? 'addition',
                'amount' => (float)($data->amount ?? 0)
            ];
        }

        if (empty($adjustments)) {
            return $this->jsonResponse->error("Tidak ada data penyesuaian", 400);
        }

        $this->db->transStart();
        try {
            $actualTotal = (float)$trx['actual_total'];
            $newActualTotal = $actualTotal;

            $metas = $this->transactionMetaModel->where('transaction_id', $id)->findAll();
            $metaMap = [];
            foreach ($metas as $m) {
                $metaMap[$m['key']] = $m;
            }

            $ppnPercent = (float)($metaMap['ppn']['value'] ?? 0);
            $currentPpnValue = (float)($metaMap['ppn_value']['value'] ?? 0);
            $ppnValueId = $metaMap['ppn_value']['id'] ?? null;
            $ppnMetaId = $metaMap['ppn']['id'] ?? null;

            // Compute current Base Subtotal accurately
            $baseSubtotal = (float)$trx['amount']
                - (float)($metaMap['item_discount_total']['value'] ?? 0)
                - (float)($metaMap['tx_discount_value']['value'] ?? 0);

            $adjustmentsJSON = $this->transactionMetaModel->where('transaction_id', $id)->where('key', 'adjustments')->first();
            $previousAdjustments = $adjustmentsJSON ? json_decode($adjustmentsJSON['value'], true) : [];
            foreach ($previousAdjustments as $pa) {
                $pCat = strtolower(trim($pa['category'] ?? ''));
                $pComp = $pa['component_name'] ?? '';
                $isD = (!empty($pCat) && ($pCat === 'diskon' || $pCat === 'discount')) || (empty($pCat) && (stripos($pComp, 'diskon') !== false || stripos($pComp, 'discount') !== false));
                if ($isD) {
                    if (($pa['type'] ?? 'addition') === 'subtraction') {
                        $baseSubtotal -= (float)$pa['amount'];
                    }
                    else {
                        $baseSubtotal += (float)$pa['amount'];
                    }
                }
            }

            $totalArAdjustment = 0;
            $totalIncomeAdjustment = 0;
            $totalDiscountAdjustment = 0;
            $totalPpnChange = 0;
            $newPpnPercentToSave = null;
            $trackedPpnValue = $currentPpnValue;

            $logDetails = [];

            foreach ($adjustments as $adjRaw) {
                $adj = (array)$adjRaw;
                $category = strtolower(trim($adj['category'] ?? ''));
                $componentName = $adj['component_name'] ?? 'Penambahan/Pengurangan';
                $type = $adj['type'] ?? 'addition';
                $amount = (float)($adj['amount'] ?? 0);

                if (!empty($category)) {
                    $isDiscountAdjustment = ($category === 'diskon' || $category === 'discount');
                    $isPpnAdjustment = ($category === 'ppn' || $category === 'pajak');
                }
                else {
                    // Fallback to testing component_name if category missing
                    $isDiscountAdjustment = stripos($componentName, 'diskon') !== false || stripos($componentName, 'discount') !== false;
                    $isPpnAdjustment = stripos($componentName, 'ppn') !== false || stripos($componentName, 'pajak') !== false;
                }

                if ($amount < 0 || ($amount == 0 && !$isPpnAdjustment))
                    continue;

                $arAdjustment = 0;
                $incomeAdjustment = 0;
                $discountAdjustment = 0;
                $ppnChange = 0;

                if ($isDiscountAdjustment) {
                    $ppnAdjustment = ($amount * $ppnPercent) / 100;

                    if ($type === 'subtraction') {
                        $arAdjustment = -($amount + $ppnAdjustment);
                        $discountAdjustment = $amount;
                        $ppnChange = -$ppnAdjustment;
                        $baseSubtotal -= $amount;
                    }
                    else {
                        $arAdjustment = ($amount + $ppnAdjustment);
                        $discountAdjustment = -$amount;
                        $ppnChange = $ppnAdjustment;
                        $baseSubtotal += $amount;
                    }
                    $trackedPpnValue += $ppnChange;

                }
                elseif ($isPpnAdjustment) {
                    $newPpnPercent = $amount;
                    $newPpnPercentToSave = $newPpnPercent;

                    $calculatedNewPpnValue = ($baseSubtotal * $newPpnPercent) / 100;
                    $ppnChange = $calculatedNewPpnValue - $trackedPpnValue;

                    $arAdjustment = $ppnChange;

                    $ppnPercent = $newPpnPercent;
                    $trackedPpnValue = $calculatedNewPpnValue;

                }
                else {
                    if ($type === 'addition') {
                        $arAdjustment = $amount;
                        $incomeAdjustment = $amount;
                    }
                    else {
                        $arAdjustment = -$amount;
                        $incomeAdjustment = -$amount;
                    }
                }

                $totalArAdjustment += $arAdjustment;
                $totalIncomeAdjustment += $incomeAdjustment;
                $totalDiscountAdjustment += $discountAdjustment;
                $totalPpnChange += $ppnChange;

                $logDetails[] = [
                    'category' => $adj['category'] ?? '',
                    'component_name' => $componentName,
                    'type' => $type,
                    'amount' => $amount
                ];
            }

            $newActualTotal = $actualTotal + $totalArAdjustment;
            if ($newActualTotal < 0) {
                $newActualTotal = 0;
            }

            // Update Meta PPN Value if changed
            if ($totalPpnChange != 0) {
                $newPpnValue = $currentPpnValue + $totalPpnChange;
                if ($newPpnValue < 0)
                    $newPpnValue = 0;

                if ($ppnValueId) {
                    $this->transactionMetaModel->update($ppnValueId, ['value' => (string)$newPpnValue]);
                }
                else {
                    $this->transactionMetaModel->insert([
                        'transaction_id' => $id,
                        'key' => 'ppn_value',
                        'value' => (string)$newPpnValue
                    ]);
                }
            }

            // Save Meta PPN Percentage if changed
            if ($newPpnPercentToSave !== null) {
                if ($ppnMetaId) {
                    $this->transactionMetaModel->update($ppnMetaId, ['value' => (string)$newPpnPercentToSave]);
                }
                else {
                    $this->transactionMetaModel->insert([
                        'transaction_id' => $id,
                        'key' => 'ppn',
                        'value' => (string)$newPpnPercentToSave
                    ]);
                }
            }

            // Generate Journal
            // Create a general descriptive text for the journal
            $componentNames = implode(', ', array_column($logDetails, 'component_name'));
            $jId = $this->createJournal('ADJUSTMENT', $id, $trx['invoice'], date('Y-m-d'), "Invoice Adjustment: {$componentNames}", $trx['id_toko']);

            // 1. AR Booking (10x3)
            if ($totalArAdjustment > 0) {
                $this->addJournalItem($jId, '10' . $trx['id_toko'] . '3', $totalArAdjustment, 0, $trx['id_toko']);
            }
            elseif ($totalArAdjustment < 0) {
                $this->addJournalItem($jId, '10' . $trx['id_toko'] . '3', 0, abs($totalArAdjustment), $trx['id_toko']);
            }

            // 2. Discount Booking (40x2)
            if ($totalDiscountAdjustment > 0) {
                $this->addJournalItem($jId, '40' . $trx['id_toko'] . '2', $totalDiscountAdjustment, 0, $trx['id_toko']);
            }
            elseif ($totalDiscountAdjustment < 0) {
                $this->addJournalItem($jId, '40' . $trx['id_toko'] . '2', 0, abs($totalDiscountAdjustment), $trx['id_toko']);
            }

            // 3. PPN Booking (20x5)
            if ($totalPpnChange > 0) {
                $this->addJournalItem($jId, '20' . $trx['id_toko'] . '5', 0, $totalPpnChange, $trx['id_toko']);
            }
            elseif ($totalPpnChange < 0) {
                $this->addJournalItem($jId, '20' . $trx['id_toko'] . '5', abs($totalPpnChange), 0, $trx['id_toko']);
            }

            // 4. Other Income/Sales Booking (40x1)
            if ($totalIncomeAdjustment > 0) {
                $this->addJournalItem($jId, '40' . $trx['id_toko'] . '1', 0, $totalIncomeAdjustment, $trx['id_toko']);
            }
            elseif ($totalIncomeAdjustment < 0) {
                $this->addJournalItem($jId, '40' . $trx['id_toko'] . '1', abs($totalIncomeAdjustment), 0, $trx['id_toko']);
            }

            // Check if status needs to change from PAID to NEED_REFUND or PARTIALLY_PAID
            $totalPayment = (float)$trx['total_payment'];
            $newStatus = $trx['status'];
            $refundNeeded = 0;

            if ($newActualTotal < $totalPayment) {
                $newStatus = 'NEED_REFUND';
                $refundNeeded = $totalPayment - $newActualTotal;
            }
            else if ($newActualTotal == $totalPayment) {
                $newStatus = ($totalPayment > 0) ? 'PAID' : 'WAITING_PAYMENT';
            }
            else if ($totalPayment > 0 && $newActualTotal > $totalPayment) {
                $newStatus = 'PARTIALLY_PAID';
            }
            else if ($totalPayment == 0) {
                $newStatus = 'WAITING_PAYMENT';
            }

            // Handle refund_needed meta
            $refundMeta = $this->transactionMetaModel->where('transaction_id', $id)->where('key', 'refund_needed')->first();
            if ($refundNeeded > 0) {
                if ($refundMeta) {
                    $this->transactionMetaModel->update($refundMeta['id'], ['value' => $refundNeeded]);
                }
                else {
                    $this->transactionMetaModel->insert([
                        'transaction_id' => $id,
                        'key' => 'refund_needed',
                        'value' => $refundNeeded
                    ]);
                }
            }
            elseif ($refundMeta) {
                $this->transactionMetaModel->update($refundMeta['id'], ['value' => 0]);
            }

            $this->transactionModel->update($id, [
                'actual_total' => $newActualTotal,
                'status' => $newStatus
            ]);

            // Save adjustments to meta
            $adjustmentsJSON = $this->transactionMetaModel->where('transaction_id', $id)->where('key', 'adjustments')->first();
            $adjustmentsRecord = $adjustmentsJSON ? json_decode($adjustmentsJSON['value'], true) : [];

            foreach ($logDetails as $adjLog) {
                $adjustmentsRecord[] = [
                    'category' => $adjLog['category'],
                    'component_name' => $adjLog['component_name'],
                    'type' => $adjLog['type'],
                    'amount' => $adjLog['amount'],
                    'date' => date('Y-m-d H:i:s')
                ];
            }

            if ($adjustmentsJSON) {
                $this->transactionMetaModel->update($adjustmentsJSON['id'], ['value' => json_encode($adjustmentsRecord)]);
            }
            else {
                $this->transactionMetaModel->insert([
                    'transaction_id' => $id,
                    'key' => 'adjustments',
                    'value' => json_encode($adjustmentsRecord)
                ]);
            }

            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'ADJUST_INVOICE',
                'target_table' => 'transaction',
                'target_id' => $id,
                'description' => "Penyesuaian Transaksi {$trx['invoice']} - {$componentNames} Total: " . array_sum(array_column($logDetails, 'amount')),
                'detail' => [
                    'adjustments' => $logDetails,
                    'old_total' => $actualTotal,
                    'new_total' => $newActualTotal
                ]
            ]);

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new \Exception("Gagal memperbarui transaksi");
            }

            // Fetch customer information for email
            $custData = $this->transactionMetaModel
                ->select('customer.email, customer.nama_customer')
                ->join('customer', 'customer.id = transaction_meta.value', 'left')
                ->where('transaction_meta.transaction_id', $id)
                ->where('transaction_meta.key', 'customer_id')
                ->first();

            if ($custData) {
                $trx['customer'] = $custData;
                send_invoice_adjusted_email($trx, $componentNames, "Multiple", array_sum(array_column($logDetails, 'amount')), $newActualTotal);
            }

            return $this->jsonResponse->oneResp('Transaksi berhasil disesuaikan', [
                'new_actual_total' => $newActualTotal,
                'status' => $newStatus
            ], 200);

        }
        catch (\Exception $e) {
            $this->db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 3. Cancel Transaction
    public function cancel($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;

        $trx = $this->transactionModel->find($id);
        if (!$trx)
            return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);

        $this->db->transStart();
        try {
            // Restore Stock
            $items = $this->salesProductModel->where('id_transaction', $id)->findAll();
            log_message('debug', '[CancelTransaction] Found ' . count($items) . ' items to restore for transaction ID: ' . $id);
            $cogsReversal = 0;

            foreach ($items as $item) {
                log_message('debug', '[CancelTransaction] Restoring ' . $item['jumlah'] . ' of product ' . $item['kode_barang']);
                $this->addStock($item['kode_barang'], $trx['id_toko'], $item['jumlah'], $id, "Cancel Transaction {$trx['invoice']}");
                $cogsReversal += $item['total_modal'];
            }

            // Reverse COGS
            if ($cogsReversal > 0) {
                $jId = $this->createJournal('CANCEL_COGS', $id, $trx['invoice'], date('Y-m-d'), "Reversal COGS {$trx['invoice']}", $trx['id_toko']);
                $this->addJournalItem($jId, '10' . $trx['id_toko'] . '4', $cogsReversal, 0, $trx['id_toko']);
                $this->addJournalItem($jId, '50' . $trx['id_toko'] . '1', 0, $cogsReversal, $trx['id_toko']);
            }

            // Reverse Sales
            $jIdSales = $this->createJournal('CANCEL_SALES', $id, $trx['invoice'], date('Y-m-d'), "Cancellation {$trx['invoice']}", $trx['id_toko']);

            // Get meta for accurate reversal
            $metas = $this->transactionMetaModel->where('transaction_id', $id)->findAll();
            $metaMap = [];
            foreach ($metas as $m) {
                $metaMap[$m['key']] = $m['value'];
            }

            $ppnValue = (float)($metaMap['ppn_value'] ?? 0);
            $itemDiscountTotal = (float)($metaMap['item_discount_total'] ?? 0);
            $txDiscountValue = (float)($metaMap['tx_discount_value'] ?? 0);
            $totalDiscount = $itemDiscountTotal + $txDiscountValue;
            $shippingCost = (float)($metaMap['biaya_pengiriman'] ?? 0);
            $isFreeOngkir = ($metaMap['free_ongkir'] ?? '0') === '1';

            // Reverse AR
            $this->addJournalItem($jIdSales, '10' . $trx['id_toko'] . '3', 0, $trx['actual_total'], $trx['id_toko']);

            // Reverse Discount (Contra-Revenue)
            if ($totalDiscount > 0) {
                $this->addJournalItem($jIdSales, '40' . $trx['id_toko'] . '2', 0, $totalDiscount, $trx['id_toko']);
            }

            // Reverse Gross Sales
            $this->addJournalItem($jIdSales, '40' . $trx['id_toko'] . '1', $trx['amount'], 0, $trx['id_toko']);

            // Reverse PPN Keluaran
            if ($ppnValue > 0) {
                $this->addJournalItem($jIdSales, '20' . $trx['id_toko'] . '5', $ppnValue, 0, $trx['id_toko']);
            }

            // Reverse Shipping (Only if NOT delivered)
            // If DELIVERED, we assume the service is consumed and we don't refund shipping (as per refund logic),
            // so we should NOT reverse the shipping revenue/expense.
            $isDelivered = (strtoupper($trx['delivery_status'] ?? '') === 'DELIVERED');

            if (!$isDelivered) {
                if (!$isFreeOngkir && $shippingCost > 0) {
                    $this->addJournalItem($jIdSales, '40' . $trx['id_toko'] . '1', $shippingCost, 0, $trx['id_toko']);
                }
                if ($isFreeOngkir && $shippingCost > 0) {
                    $this->addJournalItem($jIdSales, '20' . $trx['id_toko'] . '1', $shippingCost, 0, $trx['id_toko']); // Reverse AP
                    $this->addJournalItem($jIdSales, '50' . $trx['id_toko'] . '6', 0, $shippingCost, $trx['id_toko']); // Reverse Expense
                }
            }

            $newStatus = 'CANCEL';
            $refundNeeded = 0;

            // Store cancel reason if provided
            if (isset($data->cancel_reason) && !empty($data->cancel_reason)) {
                $this->transactionMetaModel->insert([
                    'transaction_id' => $id,
                    'key' => 'cancel_reason',
                    'value' => $data->cancel_reason
                ]);
            }

            if ($trx['total_payment'] > 0) {
                $newStatus = 'NEED_REFUND';
                $refundNeeded = (float)$trx['total_payment'];

                // Logic: Ongkir tidak dikembalikan jika status pengiriman DELIVERED (dan bukan free ongkir)
                if (strtoupper($trx['delivery_status'] ?? '') === 'DELIVERED' && !$isFreeOngkir && $shippingCost > 0) {
                    $refundNeeded -= $shippingCost;
                    if ($refundNeeded < 0)
                        $refundNeeded = 0;
                }

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

            return $this->jsonResponse->oneResp('Transaksi berhasil dibatalkan', ['status' => $newStatus], 200);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 4. Return Product
    public function returnProduct($id = null)
    {
        $data = $this->request->getJSON();
        $userId = $this->request->user['user_id'] ?? 0;

        $trx = $this->transactionModel->find($id);
        if (!$trx)
            return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);

        $this->db->transStart();
        try {
            $cogsReversal = 0;
            $revenueReduction = 0;
            $returnDetails = [];
            $returnSummary = [];

            foreach ($data->items as $item) {
                $saleItem = $this->salesProductModel
                    ->where('id_transaction', $id)
                    ->where('kode_barang', $item->kode_barang)
                    ->first();

                if (!$saleItem)
                    continue;

                $qty = $item->qty;
                $isDamaged = ($item->condition === 'bad');
                $conditionText = $isDamaged ? 'CACAT' : 'BAIK';

                // Get product name for logging
                $product = $this->productModel->where('id_barang', $item->kode_barang)->first();
                $productName = $product['nama_barang'] ?? $item->kode_barang;

                $this->addStock($item->kode_barang, $trx['id_toko'], $qty, $id, "Retur Barang ({$item->condition})", $isDamaged);

                $modalOne = $saleItem['total_modal'] / $saleItem['jumlah'];
                $cogsReversal += ($modalOne * $qty);

                $priceOne = $saleItem['total'] / $saleItem['jumlah'];
                $revenueReduction += ($priceOne * $qty);

                // Store detailed return info
                $returnDetails[] = [
                    'kode_barang' => $item->kode_barang,
                    'nama_barang' => $productName,
                    'qty' => $qty,
                    'condition' => $item->condition,
                    'is_damaged' => $isDamaged,
                    'unit_price' => $priceOne,
                    'total_value' => $priceOne * $qty
                ];

                // Create individual product log
                log_aktivitas([
                    'user_id' => $userId,
                    'action_type' => 'RETUR_PRODUCT',
                    'target_table' => 'product',
                    'target_id' => $product['id'] ?? 0,
                    'description' => "Retur: {$productName} ({$item->kode_barang}) - Qty: {$qty} - Kondisi: {$conditionText}" . ($isDamaged ? " - Masuk ke Barang Cacat" : " - Masuk ke Stock Normal"),
                    'detail' => [
                        'transaction_id' => $id,
                        'invoice' => $trx['invoice'],
                        'kode_barang' => $item->kode_barang,
                        'nama_barang' => $productName,
                        'qty' => $qty,
                        'condition' => $item->condition,
                        'is_damaged' => $isDamaged,
                        'stock_type' => $isDamaged ? 'barang_cacat' : 'stock_normal'
                    ]
                ]);

                $returnSummary[] = "{$productName} ({$item->kode_barang}): {$qty} pcs - {$conditionText}";
            }

            // Store return details in transaction_meta
            $this->transactionMetaModel->insert([
                'transaction_id' => $id,
                'key' => 'return_details',
                'value' => json_encode($returnDetails)
            ]);

            if ($cogsReversal > 0) {
                $jid = $this->createJournal('RETUR_COGS', $id, $trx['invoice'], date('Y-m-d'), "Retur COGS Reversal", $trx['id_toko']);
                $this->addJournalItem($jid, '10' . $trx['id_toko'] . '4', $cogsReversal, 0, $trx['id_toko']);
                $this->addJournalItem($jid, '50' . $trx['id_toko'] . '1', 0, $cogsReversal, $trx['id_toko']);
            }

            if ($revenueReduction > 0) {
                $jid = $this->createJournal('RETUR_SALES', $id, $trx['invoice'], date('Y-m-d'), "Retur Sales Reduction", $trx['id_toko']);
                $this->addJournalItem($jid, '40' . $trx['id_toko'] . '3', $revenueReduction, 0, $trx['id_toko']);
                $this->addJournalItem($jid, '10' . $trx['id_toko'] . '3', 0, $revenueReduction, $trx['id_toko']);
            }

            $this->db->transComplete();

            // Transaction-level summary log
            $summaryText = "Retur Transaksi {$trx['invoice']} - " . count($returnDetails) . " item(s): " . implode(", ", $returnSummary);
            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'RETUR_TRANSACTION',
                'target_table' => 'transaction',
                'target_id' => $id,
                'description' => $summaryText,
                'detail' => [
                    'invoice' => $trx['invoice'],
                    'total_items' => count($returnDetails),
                    'items' => $returnDetails,
                    'total_revenue_reduction' => $revenueReduction,
                    'refund_money' => $data->refund_money ?? false
                ]
            ]);

            // If return involves refund (money back), set status NEED_REFUND
            $refundMoney = $data->refund_money ?? false;

            if ($refundMoney) {
                $this->transactionModel->update($id, ['status' => 'NEED_REFUND']);
                $this->transactionMetaModel->insert([
                    'transaction_id' => $id,
                    'key' => 'refund_needed',
                    'value' => $revenueReduction
                ]);
            }

            return $this->jsonResponse->oneResp('Retur berhasil diproses', [
                'items_returned' => count($returnDetails),
                'total_value' => $revenueReduction
            ], 200);
        }
        catch (\Exception $e) {
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
        if (!$trx)
            return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);

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
            $this->addJournalItem($jid, '10' . $trx['id_toko'] . '3', $amount, 0, $trx['id_toko']);
            $this->addJournalItem($jid, '10' . $trx['id_toko'] . '2', 0, $amount, $trx['id_toko']);

            $refundMeta = $this->transactionMetaModel->where('transaction_id', $id)->where('key', 'refund_needed')->first();
            $newStatus = 'PARTIALLY_REFUNDED';

            if ($refundMeta) {
                // User Request: newTotalPaid derived from meta (refund_needed) - amount
                $remaining = (float)$refundMeta['value'] - $amount;

                if ($remaining <= 100) {
                    $remaining = 0;
                    $newStatus = 'REFUNDED';
                }

                $this->transactionMetaModel->update($refundMeta['id'], ['value' => $remaining]);
                $newTotalPaid = $remaining;
            }
            else {
                // Fallback
                $newTotalPaid = $trx['total_payment'] - $amount;
                if ($newTotalPaid <= 0)
                    $newStatus = 'REFUNDED';
            }

            $this->transactionModel->update($id, [
                'total_payment' => $newTotalPaid,
                'status' => $newStatus
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

            return $this->jsonResponse->oneResp('Refund berhasil diproses', [], 200);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // 6. Update Delivery Status
    public function updateDeliveryStatus($id = null)
    {
        $data = $this->request->getJSON();

        $trx = $this->transactionModel->find($id);
        if (!$trx)
            return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);

        $status = $data->status ?? null;
        $resi = $data->resi ?? null;
        $courier = $data->courier ?? null;

        if (!$status && !$resi && !$courier) {
            return $this->jsonResponse->error("Parameter status, resi, atau kurir wajib diisi", 400);
        }

        $this->db->transStart();
        try {
            // Update Delivery Status locally in transaction table (if using that column)
            // or just in Meta. Documentation says Meta.
            // But we also added `delivery_status` column in migration calling it "Shipping Status"

            if ($status) {
                $this->transactionModel->update($id, ['delivery_status' => $status]);

                // Also sync to meta for consistency
                $this->updateMeta($id, 'shipping_status', $status);

                // Create journal for shipping expenditure when status is updated to SHIPPED
                if (strtoupper($status) === 'SHIPPED' && strtoupper($trx['delivery_status'] ?? '') !== 'SHIPPED') {
                    $biayaMeta = $this->transactionMetaModel->where('transaction_id', $id)->where('key', 'biaya_pengiriman')->first();
                    $freeMeta = $this->transactionMetaModel->where('transaction_id', $id)->where('key', 'free_ongkir')->first();

                    $shippingCost = (float)($biayaMeta['value'] ?? 0);
                    $isFreeOngkir = ($freeMeta['value'] ?? '0') === '1';

                    if ($shippingCost > 0) {
                        // Check if shipping journal already exists for this transaction to avoid duplicates
                        $existingJournal = $this->journalModel->where('reference_type', 'SHIPPING_OUT')->where('reference_id', $id)->first();

                        if (!$existingJournal) {
                            $journalId = $this->createJournal('SHIPPING_OUT', $id, $trx['invoice'], date('Y-m-d'), "Shipping fee paid for {$trx['invoice']}", $trx['id_toko']);

                            // If free shipping, we settle the payable (2001) that was created during invoice creation.
                            // Otherwise, record it as a direct shipping expense (5006).
                            $debitCode = $isFreeOngkir ? '20' . $trx['id_toko'] . '1' : '50' . $trx['id_toko'] . '6';

                            $this->addJournalItem($journalId, $debitCode, $shippingCost, 0); // Dr Expense/Payable
                            $this->addJournalItem($journalId, '10' . $trx['id_toko'] . '2', 0, $shippingCost); // Cr Cash
                        }
                    }
                }
            }

            if ($resi) {
                $this->updateMeta($id, 'resi', $resi);
            }
            if ($courier) {
                $this->updateMeta($id, 'courier', $courier);
            }

            $this->db->transComplete();

            if ($this->db->transStatus() !== false) {
                // Fetch customer information for email
                $custData = $this->transactionMetaModel
                    ->select('customer.email, customer.nama_customer')
                    ->join('customer', 'customer.id = transaction_meta.value', 'left')
                    ->where('transaction_meta.transaction_id', $id)
                    ->where('transaction_meta.key', 'customer_id')
                    ->first();

                if ($custData) {
                    $trx['customer'] = $custData;
                    if (strtoupper($status ?? '') === 'READY') {
                        send_order_ready_email($trx);
                    }
                    else if (strtoupper($status ?? '') === 'SHIPPED') {
                        send_order_shipped_email($trx, $resi, $courier);
                    }
                    else if (strtoupper($status ?? '') === 'DELIVERED') {
                        send_order_delivered_email($trx);
                    }
                }
            }

            return $this->jsonResponse->oneResp("Status pengiriman berhasil diperbarui", [], 200);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    private function updateMeta($trxId, $key, $value)
    {
        $existing = $this->transactionMetaModel->where('transaction_id', $trxId)->where('key', $key)->first();
        if ($existing) {
            $this->transactionMetaModel->update($existing['id'], ['value' => $value]);
        }
        else {
            $this->transactionMetaModel->insert([
                'transaction_id' => $trxId,
                'key' => $key,
                'value' => $value
            ]);
        }
    }

    // --- Helpers ---

    private function createJournal($refType, $refId, $refNo, $date, $desc, $tokoId = null)
    {
        $data = [
            'id_toko' => $tokoId,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'reference_no' => $refNo,
            'date' => $date,
            'description' => $desc,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $this->journalModel->insert($data);
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

    private function deductStock($productCode, $tokoId, $qty, $trxId, $reason)
    {
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

        // Activity Log
        $product = $this->productModel->where('id_barang', $productCode)->first();
        log_aktivitas([
            'user_id' => $this->request->user['user_id'] ?? 0,
            'action_type' => 'STOCK_OUT',
            'target_table' => 'product',
            'target_id' => $product ? $product['id'] : 0,
            'description' => "Pengurangan Stock: Produk $productCode di Toko #$tokoId. Qty: -$qty, Sisa: $newStock. Ref: $reason"
        ]);
    }

    private function addStock($productCode, $tokoId, $qty, $trxId, $reason, $isDamaged = false)
    {
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

        // Activity Log
        $product = $this->productModel->where('id_barang', $productCode)->first();
        log_aktivitas([
            'user_id' => $this->request->user['user_id'] ?? 0,
            'action_type' => 'STOCK_IN',
            'target_table' => 'product',
            'target_id' => $product ? $product['id'] : 0,
            'description' => "Penambahan Stock (Cancel): Produk $productCode di Toko #$tokoId. Qty: +$qty, Total: $newStock. Ref: $reason"
        ]);
    }

    // Get Full Transaction Detail
    public function getDetail($id = null)
    {
        try {
            if (!$id)
                return $this->jsonResponse->error("ID wajib diisi", 400);

            $db = \Config\Database::connect();

            // 1. Get Transaction with Toko info
            $transaction = $this->transactionModel
                ->select('transaction.*, toko.toko_name, toko.alamat as toko_alamat, toko.phone_number as toko_phone, toko.image_logo as toko_logo, toko.bank, toko.nomer_rekening, toko.nama_pemilik')
                ->join('toko', 'transaction.id_toko = toko.id AND toko.tenant_id = transaction.tenant_id', 'left')
                ->find($id);

            if (!$transaction)
                return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);

            // 2. Get All Metadata
            $metas = $this->transactionMetaModel->where('transaction_id', $id)->findAll();
            $metaMap = [];
            foreach ($metas as $m) {
                $metaMap[$m['key']] = $m['value'];
            }

            // Resolve Regional Names if they are stored as IDs
            $regions = [
                'provinsi' => 'provincy',
                'kota_kabupaten' => 'kota_kabupaten',
                'kecamatan' => 'kecamatan',
                'kelurahan' => 'kelurahan'
            ];
            foreach ($regions as $key => $table) {
                if (isset($metaMap[$key]) && is_numeric($metaMap[$key]) && !empty($metaMap[$key])) {
                    $regionalData = $db->table($table)->where('code', $metaMap[$key])->get()->getRowArray();
                    if ($regionalData) {
                        $metaMap[$key] = $regionalData['name'];
                    }
                }
            }

            $transaction['meta'] = $metaMap;

            // Security Check: If hit by Customer, verify ownership
            if (property_exists($this->request, 'customer') && isset($this->request->customer)) {
                $customerId = $this->request->customer['id'];
                if (!isset($metaMap['customer_id']) || (int)$metaMap['customer_id'] !== (int)$customerId) {
                    return $this->jsonResponse->error("Akses Ditolak: Anda tidak memiliki izin untuk melihat transaksi ini.", 403);
                }
            }

            // 3. Get Items (Sales Product)
            $items = $db->table('sales_product sp')
                ->select("
                    sp.*,
                    p.nama_barang,
                    p.berat,
                    mb.nama_model,
                    s.seri,
                    CONCAT(COALESCE(p.nama_barang,''), ' ', COALESCE(mb.nama_model,''), ' ', COALESCE(s.seri,'')) as nama_lengkap_barang
                ")
                ->join('product p', 'sp.kode_barang = p.id_barang AND p.tenant_id = sp.tenant_id', 'left')
                ->join('model_barang mb', 'p.id_model_barang = mb.id AND mb.tenant_id = sp.tenant_id', 'left')
                ->join('seri s', 'p.id_seri_barang = s.id AND s.tenant_id = sp.tenant_id', 'left')
                ->where('sp.id_transaction', $id)
                ->where('sp.tenant_id', TenantContext::id())
                ->get()
                ->getResultArray();

            $transaction['items'] = $items;

            // 4. Get Payments
            $payments = $this->paymentModel
                ->where('transaction_id', $id)
                ->orderBy('paid_at', 'DESC')
                ->findAll();

            $transaction['payments'] = $payments;

            return $this->jsonResponse->oneResp('Sukses', $transaction, 200);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Get Transactions by Status
    public function getTransactionsByStatus()
    {
        try {
            $status = $this->request->getGet('status'); // e.g., PAID, PARTIALLY_PAID
            $idToko = $this->request->getGet('id_toko');
            $limit = (int)$this->request->getGet('limit') ?: 20;
            $page = (int)$this->request->getGet('page') ?: 1;
            $offset = ($page - 1) * $limit;

            $builder = $this->transactionModel;

            if (!empty($status)) {
                $builder = $builder->where('status', $status);
            }

            if (!empty($idToko)) {
                $builder = $builder->where('id_toko', $idToko);
            }

            $totalData = $builder->countAllResults(false);
            $totalPage = ceil($totalData / $limit);

            $transactions = $builder
                ->orderBy('created_at', 'DESC')
                ->limit($limit, $offset)
                ->findAll();

            // Enrich with meta data
            foreach ($transactions as &$trx) {
                $meta = $this->transactionMetaModel
                    ->where('transaction_id', $trx['id'])
                    ->findAll();

                $trx['meta'] = [];
                foreach ($meta as $m) {
                    $trx['meta'][$m['key']] = $m['value'];
                }
            }

            return $this->jsonResponse->multiResp('', $transactions, $totalData, $totalPage, $page, $limit, 200);
        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Add Transaction Meta
    public function addTransactionMeta($id = null)
    {
        try {
            $data = $this->request->getJSON();
            $userId = $this->request->user['user_id'] ?? 0;

            if (!$id) {
                return $this->jsonResponse->error("ID Transaksi wajib diisi", 400);
            }

            $trx = $this->transactionModel->find($id);
            if (!$trx) {
                return $this->jsonResponse->error("Transaksi tidak ditemukan", 404);
            }

            if (empty($data->key) || !isset($data->value)) {
                return $this->jsonResponse->error("Key dan value wajib diisi", 400);
            }

            // Check if meta key already exists
            $existingMeta = $this->transactionMetaModel
                ->where('transaction_id', $id)
                ->where('key', $data->key)
                ->first();

            if ($existingMeta) {
                // Update existing
                $this->transactionMetaModel->update($existingMeta['id'], [
                    'value' => $data->value
                ]);
            }
            else {
                // Insert new
                $this->transactionMetaModel->insert([
                    'transaction_id' => $id,
                    'key' => $data->key,
                    'value' => $data->value
                ]);
            }

            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'UPDATE_META',
                'target_table' => 'transaction',
                'target_id' => $id,
                'description' => "Added/Updated transaction meta: {$data->key}",
                'detail' => [
                    'invoice' => $trx['invoice'],
                    'key' => $data->key,
                    'value' => $data->value
                ]
            ]);

            return $this->jsonResponse->oneResp('Meta transaksi berhasil diperbarui', [], 200);
        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}