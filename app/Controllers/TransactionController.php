<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\CustomerModel;
use App\Models\JsonResponse;
use App\Models\StockModel;
use App\Models\TransactionMetaModel;
use App\Models\TransactionModel;
use App\Models\SalesProductModel;
use App\Models\ProductModel;
use App\Models\CashflowModel;
use App\Models\AccountModel;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTime;
use PhpParser\Node\Scalar\Float_;

class TransactionController extends BaseController
{
    protected $transactions;
    protected $jsonResponse;
    protected $transactionMeta;
    protected $customer;
    protected $SalesProductModel;
    protected $ProductModel;
    protected $db;
    protected $stockModel;

    protected $CashflowModel;
    protected $accountModel;
    protected $journalModel;
    protected $journalItemModel;

    public function __construct()
    {
        helper('log');
        $this->jsonResponse = new JsonResponse();
        $this->transactions = new TransactionModel();
        $this->transactionMeta = new TransactionMetaModel();
        $this->customer = new CustomerModel();
        $this->SalesProductModel = new SalesProductModel();
        $this->ProductModel = new ProductModel();
        $this->stockModel = new StockModel();
        $this->CashflowModel = new CashflowModel();
        $this->accountModel = new AccountModel();
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->db = \Config\Database::connect(); // Memuat database
    }

    public function dropdownStatusTransaction()
    {
        try {
            // Ambil status dari model

            $statuses = $this->transactions->getStatuses();

            // Ubah format ke label-value
            $formattedStatuses = [];
            foreach ($statuses as $key => $label) {
                $formattedStatuses[] = [
                    'label' => $label,
                    'value' => $key
                ];
            }

            return $this->jsonResponse->oneResp('', $formattedStatuses, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    private function checkAndUpdateStockBatch($user_id, $id_toko, $items, $insertID)
    {
        $kodeBarangList = array_column($items, 'kode_barang');
        $stockLogs = [];

        // Ambil semua stock sekaligus
        $stocks = $this->stockModel
            ->select('stock.*, product.id AS product_id, stock.id_barang')
            ->join('product', 'product.id_barang = stock.id_barang')
            ->where('stock.id_toko', $id_toko)
            ->whereIn('stock.id_barang', $kodeBarangList)
            ->get()
            ->getResultArray();

        $stockMap = [];
        foreach ($stocks as $stock) {
            $stockMap[$stock['id_barang']] = $stock;
        }

        // Validasi stok tersedia
        foreach ($items as $item) {
            if (!isset($stockMap[$item->kode_barang])) {
                throw new \Exception("Stok untuk produk {$item->kode_barang} tidak ditemukan.");
            }

            $stock = $stockMap[$item->kode_barang];

            if ((int) $stock['stock'] < $item->jumlah) {
                throw new \Exception("Stok tidak mencukupi untuk produk {$item->kode_barang}. Stok tersedia: {$stock['stock']}, dibutuhkan: {$item->jumlah}");
            }
        }

        // Update stock dalam batch dengan logging
        foreach ($items as $item) {
            if (isset($stockMap[$item->kode_barang]) && empty($stockMap[$item->kode_barang]['dropship'])) {

                $stock = $stockMap[$item->kode_barang];
                $oldStock = (int) $stock['stock'];
                $newStock = $oldStock - $item->jumlah;

                $this->stockModel
                    ->where('id_toko', $id_toko)
                    ->where('id_barang', $item->kode_barang)
                    ->set('stock', $newStock)
                    ->update();

                // Simpan data log untuk nanti
                $stockLogs[] = [
                    'kode_barang' => $item->kode_barang,
                    'product_id' => $stock['product_id'],
                    'old_stock' => $oldStock,
                    'reduced' => $item->jumlah,
                    'new_stock' => $newStock
                ];

                // LOG SEKARANG - hanya sekali untuk setiap produk
                log_aktivitas([
                    'user_id' => $user_id,
                    'action_type' => 'UPDATE',
                    'target_table' => 'product',
                    'target_id' => $stock['product_id'],
                    'description' => "Mengurangi stok produk {$item->kode_barang} pada toko {$id_toko} sebanyak {$item->jumlah} untuk update transaksi {$insertID}",
                    'detail' => [
                        'sebelum' => $oldStock,
                        'dikurangi' => (int) $item->jumlah,
                        'sisa' => $newStock,
                        'kode_barang' => $item->kode_barang,
                        'transaction_id' => $insertID,
                        'reason' => 'Penyesuaian stok untuk update transaksi'
                    ]
                ]);
            }
        }

        return $stockLogs;
    }
    private function getOrCreateCustomer($customer_name, $customer_phone, $customer_alamat)
    {
        if (empty($customer_phone)) {
            return null;
        }

        $customer = $this->customer->where('no_hp_customer', $customer_phone)->first();
        if (!$customer) {
            $this->customer->insert([
                'nama_customer' => $customer_name,
                'no_hp_customer' => $customer_phone,
                'alamat' => $customer_alamat
            ]);
            return $this->customer->insertID();
        }

        return $customer['id'];
    }
    private function calculateTransactionTotals($items, $discountValue, $discountType, $ppn, $pengiriman, $freeOngkir = false)
    {
        $totalAmount = array_reduce($items, function ($carry, $item) {
            return $carry + ($item->jumlah * $item->harga_jual);
        }, 0);

        // Calculate discount amount
        $discountAmount = 0;
        if ($discountType === 'percentage') {
            $discountAmount = ($totalAmount * $discountValue) / 100;
        } else {
            $discountAmount = $discountValue;
        }

        $totalPpn = ($totalAmount * $ppn) / 100;

        // Hitung potongan ongkir jika free ongkir
        $potongan_ongkir = $freeOngkir ? $pengiriman : 0;
        $grandTotal = $totalAmount + $totalPpn + $pengiriman - $discountAmount - $potongan_ongkir;

        return [$totalAmount, $totalPpn, $grandTotal, $potongan_ongkir, $discountAmount];
    }
    private function saveTransactionMeta($transactionId, $data)
    {
        // Daftar key yang BOLEH diupdate (tidak termasuk preserved keys)
        $updatableKeys = [
            'ppn',
            'ppn_value',
            'totalAmount',
            'grand_total',
            'discount',
            'discount_rate',
            'alamat',
            'pengiriman',
            'biaya_pengiriman',
            'free_ongkir',
            'potongan_ongkir',
            'source',
            'customer_id',
            'customer_name',
            'customer_phone',
            'jatuh_tempo',
            'refunded_amount',
            'complaint',
            'discount_type',
            'discount_value',
            'discount_percentage'
        ];

        $metaData = [
            'ppn' => $data['ppn'],
            'ppn_value' => $data['ppn_value'],
            'grand_total' => $data['totalAmount'],
            'discount' => $data['discount'],
            'discount_rate' => $data['discount_rate'] ?? null,
            'discount_type' => $data['discount_type'] ?? 'fixed',
            'discount_value' => $data['discount_value'] ?? 0,
            'alamat' => $data['alamat'],
            'pengiriman' => $data['pengiriman'],
            'biaya_pengiriman' => $data['biaya_pengiriman'],
            'free_ongkir' => $data['free_ongkir'] ?? false,
            'potongan_ongkir' => $data['potongan_ongkir'] ?? 0,
            'source' => $data['source'] ?? null,
            'customer_id' => empty($data['customer_id']) ? null : $data['customer_id'],
            'customer_name' => empty($data['customer_id']) ? $data['customer_name'] : null,
            'customer_phone' => empty($data['customer_id']) ? $data['customer_phone'] : null,
            'jatuh_tempo' => $data['jatuh_tempo'] ?? null,
            'refunded_amount' => $data['refunded_amount'] ?? null,
            'complaint' => $data['complaint'] ?? null,
        ];

        // Filter null values
        $metaData = array_filter($metaData, function ($value) {
            return $value !== null;
        });

        // Hanya proses keys yang boleh diupdate
        foreach ($metaData as $key => $value) {
            if (in_array($key, $updatableKeys)) {
                // Cek apakah data sudah ada
                $existingMeta = $this->transactionMeta
                    ->where('transaction_id', $transactionId)
                    ->where('key', $key)
                    ->first();

                $formattedValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

                if ($existingMeta) {
                    // Update existing
                    $this->transactionMeta->update($existingMeta['id'], [
                        'value' => $formattedValue
                    ]);
                } else {
                    // Insert baru
                    $this->transactionMeta->insert([
                        'transaction_id' => $transactionId,
                        'key' => $key,
                        'value' => $formattedValue
                    ]);
                }
            }
        }
    }
    public function createTransaction()
    {
        $data = $this->request->getJSON();
        $token = $this->request->user;

        $db = \Config\Database::connect();
        $db->transStart();

        try {
            $customerId = null;

            // 1. Prepare data
            if ($data->customer_id) {
                $customerId = $data->customer_id;
            } else {
                $customerId = $this->getOrCreateCustomer($data->customer_name, $data->customer_phone, $data->alamat);
            }
            // 2. Get products in batch
            $kodeBarangList = array_column($data->item, 'kode_barang');
            $products = $this->ProductModel->whereIn('id_barang', $kodeBarangList)->findAll();

            if (count($products) !== count($kodeBarangList)) {
                throw new \Exception("Beberapa produk tidak ditemukan.");
            }

            $productMap = array_column($products, null, 'id_barang');

            // 3. Calculate totals dengan free_ongkir
            $freeOngkir = isset($data->free_ongkir) ? (bool) $data->free_ongkir : false;
            $discountType = $data->discount_type ?? 'fixed';

            [$totalAmount, $ppn_value, $grandTotal, $potongan_ongkir, $discountAmount] = $this->calculateTransactionTotals(
                $data->item,
                $data->discount_value ?? 0,
                $discountType,
                $data->ppn ?? 0,
                $data->biaya_pengiriman ?? 0,
                $freeOngkir
            );

            $discount_rate = 0;
            if ($totalAmount > 0) {
                $discount_rate = $discountAmount / $totalAmount;
            }

            // 4. Create transaction
            $transactionData = [
                'amount' => $grandTotal,
                'status' => 'WAITING_PAYMENT',
                'po' => $data->po,
                'id_toko' => $data->id_toko,
                'created_by' => $token['user_id'],
                'date_time' => date('Y-m-d H:i:s'),
                'invoice' => "INV" . date('ymd'), // temporary
            ];

            if (!$this->transactions->insert($transactionData)) {
                throw new \Exception("Gagal menyimpan transaksi.");
            }

            $insertID = $this->transactions->insertID();
            $invoice = "INV" . date('ymd') . $insertID;

            // 5. Check and update stock in batch
            $this->checkAndUpdateStockBatch($token['user_id'], $data->id_toko, $data->item, $insertID);

            // 6. Prepare sales data dengan perhitungan sekaligus
            $salesData = [];
            $total_modal = 0;
            $total_actual = 0;

            foreach ($data->item as $item) {
                $product = $productMap[$item->kode_barang];
                $actual_per_piece = $item->harga_jual * (1 - $discount_rate);
                $actual_total = $actual_per_piece * $item->jumlah;



                $dropship_suplier = $product['suplier'] ?? null;


                $salesData[] = [
                    'id_transaction' => $insertID,
                    'kode_barang' => $item->kode_barang,
                    'jumlah' => $item->jumlah,
                    'harga_jual' => $item->harga_jual,
                    'total' => $item->harga_jual * $item->jumlah,
                    'modal_system' => $product['harga_modal'],
                    'total_modal' => $product['harga_modal'] * $item->jumlah,
                    'actual_per_piece' => $actual_per_piece,
                    'actual_total' => $actual_total,
                    'dropship_suplier' => $dropship_suplier,
                ];

                $total_modal += $product['harga_modal'] * $item->jumlah;
                $total_actual += $actual_total;
            }


            // 7. Update transaction dengan data final
            $this->transactions->update($insertID, [
                'invoice' => $invoice,
                'actual_total' => $total_actual,
                'total_modal' => $total_modal
            ]);

            // 8. Save metadata dengan free_ongkir
            $this->saveTransactionMeta($insertID, [
                'ppn' => $data->ppn,
                'ppn_value' => $ppn_value,
                'totalAmount' => $totalAmount,
                'discount' => $discountAmount,
                'discount_value' => $data->discount_value ?? 0,
                'discount_type' => $discountType,
                'discount_percentage' => ($discountType === 'percentage') ? ($data->discount_value ?? 0) : 0,
                'discount_rate' => $discount_rate,
                'source' => $data->source,
                'customer_id' => $customerId,
                'customer_phone' => $data->customer_phone,
                'jatuh_tempo' => $data->jatuh_tempo,
                'alamat' => $data->alamat,
                'pengiriman' => $data->pengiriman,
                'biaya_pengiriman' => $data->biaya_pengiriman,
                'free_ongkir' => $freeOngkir,
                'potongan_ongkir' => $potongan_ongkir,
                'customer_name' => $data->customer_name
            ]);

            // 9. Insert sales data
            if (!$this->SalesProductModel->insertBatch($salesData)) {
                throw new \Exception("Gagal menyimpan data penjualan.");
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \Exception("Terjadi kesalahan saat menyimpan transaksi.");
            }

            // Logging dengan info free ongkir
            $logDescription = 'Membuat transaksi baru';
            if ($freeOngkir) {
                $logDescription .= ' dengan FREE ONGKIR';
            }

            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'CREATE',
                'target_table' => 'transactions',
                'target_id' => $insertID,
                'description' => $logDescription,
                'new' => $data
            ]);

            return $this->jsonResponse->oneResp('Transaksi berhasil diproses', $insertID, 201);

        } catch (\Exception $e) {
            $db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
    public function countTransaction()
    {
        $data = $this->request->getJSON();

        try {
            // Validasi input
            if (!isset($data->item) || !is_array($data->item) || empty($data->item)) {
                throw new \Exception("Item data is required and cannot be empty");
            }

            $kodeBarangList = array_column($data->item, 'kode_barang');
            $products = $this->ProductModel->whereIn('id_barang', $kodeBarangList)->findAll();

            if (empty($products)) {
                throw new \Exception("No products found for the given IDs.");
            }

            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product['id_barang']] = $product;
            }

            $totalAmount = 0;

            foreach ($data->item as $item) {
                if (!isset($productMap[$item->kode_barang])) {
                    throw new \Exception("Product {$item->kode_barang} not found.");
                }

                $product = $productMap[$item->kode_barang];
                $jumlah = $item->jumlah;
                $harga_final_satuan = $item->harga_jual;
                $total = $harga_final_satuan * $jumlah;
                $totalAmount += $total;
            }

            // Handle PPN
            if (empty($data->ppn)) {
                $ppn = 0;
            } else {
                $ppn = $data->ppn * $totalAmount / 100;
            }

            // Handle biaya pengiriman dengan free_ongkir
            $biaya_pengiriman = 0;
            if (isset($data->free_ongkir) && $data->free_ongkir === true) {
                $biaya_pengiriman = 0; // Gratis ongkir
            } else {
                $biaya_pengiriman = $data->biaya_pengiriman ?? 0;
            }

            // Handle discount
            $discountValue = $data->discount_value ?? 0;
            $discountType = $data->discount_type ?? 'fixed';
            $discountAmount = 0;

            if ($discountType === 'percentage') {
                $discountAmount = ($totalAmount * $discountValue) / 100;
            } else {
                $discountAmount = $discountValue;
            }

            // Calculate grand total
            $grand_total = $totalAmount + $ppn + $biaya_pengiriman - $discountAmount;

            // Pastikan grand total tidak negatif
            if ($grand_total < 0) {
                $grand_total = 0;
            }

            // Prepare response data
            $transactionData = [
                'discount' => (float) $discountAmount,
                'discount_type' => $discountType,
                'discount_value' => (float) $discountValue,
                'biaya_pengiriman' => (float) $data->biaya_pengiriman,
                'sub_total' => (float) $totalAmount,
                'ppn' => (float) $ppn,
                'grand_total' => (float) $grand_total,
                'free_ongkir' => isset($data->free_ongkir) ? (bool) $data->free_ongkir : false
            ];

            return $this->jsonResponse->oneResp('Transaction successfully processed', $transactionData, 201);

        } catch (\Exception $e) {
            log_message('error', 'Count transaction error: ' . $e->getMessage());
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function getListTransaction()
    {
        $db = \Config\Database::connect();
        $request = $this->request;

        $status = $request->getGet('status');
        $delivery_status = $request->getGet('delivery_status');
        $id_toko = $request->getGet('id_toko');
        $date_start = $request->getGet('date_start');
        $date_end = $request->getGet('date_end');
        $role = $request->getGet('role');
        $search = $request->getGet('search');
        $total_min = $request->getGet('total_min');
        $total_max = $request->getGet('total_max');
        $sortBy = $request->getGet('sortBy') ?: 't.id';
        $sortMethod = strtolower($request->getGet('sortMethod') ?: 'desc');
        $limit = max((int) ($request->getGet('limit') ?: 10), 1);
        $page = max((int) ($request->getGet('page') ?: 1), 1);
        $offset = ($page - 1) * $limit;

        if (is_string($role)) {
            $role = array_filter(array_map('intval', explode(',', $role)));
        }

        // Determine if we can use the optimized path (Deferred Joins)
        // We cannot use it if we are sorting by joined columns (complex sort)
        // BUT we CAN use it for searching now by using subqueries/EXISTS
        $complexSortColumns = ['customer_name', 'jatuh_tempo']; // Columns not in 'transaction' or 'toko'
        $isComplexQuery = in_array($sortBy, $complexSortColumns);

        if (!$isComplexQuery) {
            // ==========================================
            // OPTIMIZED PATH (Search with Subqueries, Simple Sort)
            // ==========================================
            $builder = $db->table('transaction t')
                ->select('t.id AS transaction_id, t.invoice AS invoice_number, t.amount,t.actual_total, t.po, t.total_payment, t.status, t.id_toko, t.date_time, toko.toko_name')
                ->join('toko', 't.id_toko = toko.id', 'left');

            // Apply Basic Filters
            if ($status) {
                if (strpos($status, ',') !== false) {
                    $builder->whereIn('t.status', explode(',', $status));
                } else {
                    $builder->where('t.status', $status);
                }
            }
            if ($delivery_status)
                $builder->like('t.delivery_status', $delivery_status, 'both');
            if (!empty($role) && !$id_toko)
                $builder->whereIn('t.id_toko', $role);
            if ($id_toko)
                $builder->where('t.id_toko', $id_toko);
            if ($date_start && $date_end) {
                $builder->where('t.date_time >=', "{$date_start} 00:00:00");
                $builder->where('t.date_time <=', "{$date_end} 23:59:59");
            } elseif ($date_start) {
                $builder->where('t.date_time >=', "{$date_start} 00:00:00");
            } elseif ($date_end) {
                $builder->where('t.date_time <=', "{$date_end} 23:59:59");
            }
            if ($total_min !== null && $total_min !== '' && is_numeric($total_min))
                $builder->where('t.total_payment >=', (float) $total_min);
            if ($total_max !== null && $total_max !== '' && is_numeric($total_max))
                $builder->where('t.total_payment <=', (float) $total_max);

            // Apply Search using EXISTS subqueries to maintain performance
            if ($search) {
                $builder->groupStart();

                // 1. Search by Invoice (Direct Column)
                $builder->like('t.invoice', $search);

                // 2. Search by Customer Name/Phone (Linked via Meta -> Customer table)
                // exists (select 1 from transaction_meta tm join customer c on tm.value = c.id where tm.transaction_id = t.id and tm.key = 'customer_id' and (c.nama_customer like ... or c.no_hp_customer like ...))
                $builder->orWhere("EXISTS (
                    SELECT 1 FROM transaction_meta tm 
                    JOIN customer c ON tm.value = c.id 
                    WHERE tm.transaction_id = t.id 
                    AND tm.key = 'customer_id' 
                    AND (c.nama_customer LIKE '%{$db->escapeLikeString($search)}%' OR c.no_hp_customer LIKE '%{$db->escapeLikeString($search)}%')
                )");

                // 3. Search by Guest Name (Directly in Meta)
                // exists (select 1 from transaction_meta tm where tm.transaction_id = t.id and tm.key = 'customer_name' and tm.value like ...)
                $builder->orWhere("EXISTS (
                    SELECT 1 FROM transaction_meta tm 
                    WHERE tm.transaction_id = t.id 
                    AND tm.key = 'customer_name' 
                    AND tm.value LIKE '%{$db->escapeLikeString($search)}%'
                )");

                $builder->groupEnd();
            }

            // Clone for count BEFORE limit
            $countBuilder = clone $builder;
            $total_data = (int) $countBuilder->countAllResults();

            // Apply Sort & Limit
            $builder->orderBy($sortBy, $sortMethod);
            $builder->limit($limit, $offset);
            $result = $builder->get()->getResultArray();

            // Hydrate Data (Deferred Join logic)
            if (!empty($result)) {
                $transactionIds = array_column($result, 'transaction_id');

                // 1. Get Transaction Meta (Customer Info & Jatuh Tempo)
                $metas = $db->table('transaction_meta')
                    ->whereIn('transaction_id', $transactionIds)
                    ->whereIn('key', ['customer_id', 'customer_name', 'jatuh_tempo'])
                    ->get()
                    ->getResultArray();

                $metaMap = [];
                $customerIds = [];
                foreach ($metas as $meta) {
                    $metaMap[$meta['transaction_id']][$meta['key']] = $meta['value'];
                    if ($meta['key'] === 'customer_id') {
                        $customerIds[] = $meta['value'];
                    }
                }

                // 2. Get Customer Data
                $customerMap = [];
                if (!empty($customerIds)) {
                    $customers = $db->table('customer')
                        ->whereIn('id', array_unique($customerIds))
                        ->get()
                        ->getResultArray();
                    foreach ($customers as $cust) {
                        $customerMap[$cust['id']] = $cust;
                    }
                }

                // 3. Merge Data
                foreach ($result as &$row) {
                    $tid = $row['transaction_id'];
                    $tMeta = $metaMap[$tid] ?? [];

                    $custId = $tMeta['customer_id'] ?? null;
                    $custNameMeta = $tMeta['customer_name'] ?? null;
                    $jatuhTempoVal = $tMeta['jatuh_tempo'] ?? null;

                    // Determine Customer Name
                    if ($custId && isset($customerMap[$custId])) {
                        $row['customer_name'] = $customerMap[$custId]['nama_customer'];
                        $row['customer_phone'] = $customerMap[$custId]['no_hp_customer'];
                    } else {
                        $row['customer_name'] = $custNameMeta;
                        $row['customer_phone'] = null;
                    }

                    // Determine Jatuh Tempo
                    $row['jatuh_tempo_pada'] = $jatuhTempoVal;
                    $row['jatuh_tempo'] = false;
                    if ($jatuhTempoVal && $jatuhTempoVal != '' && $jatuhTempoVal <= date('Y-m-d')) {
                        $row['jatuh_tempo'] = true;
                    }
                }
                unset($row);
            }

        } else {
            // ==========================================
            // COMPLEX PATH (Sort by Joined Col ONLY)
            // ==========================================
            // Use original rigorous query with all joins

            $builder = $db->table('transaction t')
                ->select("
                t.id AS transaction_id,
                t.invoice AS invoice_number,
                t.actual_total,
                t.amount,
                t.po,
                t.total_payment,
                t.status,
                t.id_toko,
                t.date_time,
                toko.toko_name,
                COALESCE(c.nama_customer, tm_name.value) AS customer_name,
                c.no_hp_customer AS customer_phone,
                NULLIF(tm_jatuh_tempo.value, '') AS jatuh_tempo_pada,
                CASE 
                    WHEN tm_jatuh_tempo.value IS NOT NULL AND tm_jatuh_tempo.value != '' AND tm_jatuh_tempo.value <= CURDATE() THEN TRUE
                    ELSE FALSE
                END AS jatuh_tempo
            ")
                ->join('transaction_meta tm_cust', 't.id = tm_cust.transaction_id AND tm_cust.key = "customer_id"', 'left')
                ->join('customer c', 'tm_cust.value = c.id', 'left')
                ->join('transaction_meta tm_name', 't.id = tm_name.transaction_id AND tm_name.key = "customer_name"', 'left')
                ->join('transaction_meta tm_jatuh_tempo', 't.id = tm_jatuh_tempo.transaction_id AND tm_jatuh_tempo.key = "jatuh_tempo"', 'left')
                ->join('toko', 't.id_toko = toko.id', 'left');

            // Apply Filters
            if ($status) {
                if (strpos($status, ',') !== false) {
                    $builder->whereIn('t.status', explode(',', $status));
                } else {
                    $builder->where('t.status', $status);
                }
            }
            if (!empty($role) && !$id_toko)
                $builder->whereIn('t.id_toko', $role);
            if ($id_toko)
                $builder->where('t.id_toko', $id_toko);
            if ($date_start && $date_end) {
                $builder->where('t.date_time >=', "{$date_start} 00:00:00");
                $builder->where('t.date_time <=', "{$date_end} 23:59:59");
            } elseif ($date_start) {
                $builder->where('t.date_time >=', "{$date_start} 00:00:00");
            } elseif ($date_end) {
                $builder->where('t.date_time <=', "{$date_end} 23:59:59");
            }
            if ($total_min !== null && $total_min !== '' && is_numeric($total_min))
                $builder->where('t.total_payment >=', (float) $total_min);
            if ($total_max !== null && $total_max !== '' && is_numeric($total_max))
                $builder->where('t.total_payment <=', (float) $total_max);

            if ($search) {
                $builder->groupStart()
                    ->like('c.nama_customer', $search)
                    ->orLike('c.no_hp_customer', $search)
                    ->orLike('tm_name.value', $search)
                    ->orLike('t.invoice', $search)
                    ->groupEnd();
            }

            // Clone for count BEFORE limit
            // IMPORTANT: This fixes the bug where search didn't affect count
            $countBuilder = clone $builder;
            $total_data = (int) $countBuilder->countAllResults();

            // Apply Sort & Limit
            $builder->orderBy($sortBy, $sortMethod);
            $builder->limit($limit, $offset);
            $result = $builder->get()->getResultArray();
        }

        // Common final processing
        $total_page = ceil($total_data / $limit);

        return $this->jsonResponse->multiResp(
            'Success',
            $result,
            $total_data,
            $total_page,
            $page,
            $limit,
            200
        );
    }


    public function getTransactionDetailById($id = null)
    {
        $db = \Config\Database::connect();

        // Gunakan single query dengan conditional aggregation untuk meta data
        $builder = $db->table('transaction t')
            ->select("
            t.id AS transaction_id,
            t.invoice AS invoice_number,
            t.amount,
            t.po,
            t.total_payment,
            t.status,
            t.id_toko,
            t.date_time,
            t.actual_total,
            t.total_modal,
            tk.toko_name,
            tk.image_logo,
            tk.alamat as alamat_toko,
            tk.phone_number as nomer_toko,
            tk.bank as bank_toko,
            tk.nama_pemilik as nama_pemilik_toko,
            tk.nomer_rekening as nomer_rekening_toko,
            COALESCE(c.nama_customer, MAX(CASE WHEN tm.key = 'customer_name' THEN tm.value END)) AS customer_name,
            c.no_hp_customer AS customer_phone, 
            c.id AS customer_id, 
            MAX(CASE WHEN tm.key = 'partialy_paid_at' THEN tm.value END) AS partially_paid_at,
            MAX(CASE WHEN tm.key = 'metode_pembayaran_dp' THEN tm.value END) AS metode_pembayaran_dp,
            MAX(CASE WHEN tm.key = 'paid_at' THEN tm.value END) AS paid_at,
            MAX(CASE WHEN tm.key = 'ppn' THEN tm.value END) AS ppn,
            MAX(CASE WHEN tm.key = 'ppn_value' THEN tm.value END) AS ppn_value,
            MAX(CASE WHEN tm.key = 'total_dp' THEN tm.value END) AS total_dp,
            MAX(CASE WHEN tm.key = 'grand_total' THEN tm.value END) AS grand_total,
            MAX(CASE WHEN tm.key = 'metode_pembayaran_pelunasan' THEN tm.value END) AS metode_pembayaran_pelunasan,
            MAX(CASE WHEN tm.key = 'cancel_at' THEN tm.value END) AS cancel_at,
            MAX(CASE WHEN tm.key = 'cancel_reason' THEN tm.value END) AS cancel_reason,
            MAX(CASE WHEN tm.key = 'refunded_at' THEN tm.value END) AS refunded_at,
            MAX(CASE WHEN tm.key = 'refunded_amount' THEN tm.value END) AS refunded_amount,
            MAX(CASE WHEN tm.key = 'source' THEN tm.value END) AS source,
            MAX(CASE WHEN tm.key = 'discount' THEN tm.value END) AS discount,
            MAX(CASE WHEN tm.key = 'discount_type' THEN tm.value END) AS discount_type,
            MAX(CASE WHEN tm.key = 'discount_value' THEN tm.value END) AS discount_value,
            MAX(CASE WHEN tm.key = 'jatuh_tempo' THEN tm.value END) AS jatuh_tempo,
            MAX(CASE WHEN tm.key = 'alamat' THEN tm.value END) AS alamat,
            MAX(CASE WHEN tm.key = 'pengiriman' THEN tm.value END) AS pengiriman,
            MAX(CASE WHEN tm.key = 'biaya_pengiriman' THEN tm.value END) AS biaya_pengiriman,
            MAX(CASE WHEN tm.key = 'notes' THEN tm.value END) AS notes,
            MAX(CASE WHEN tm.key = 'free_ongkir' THEN tm.value END) AS free_ongkir,
            MAX(CASE WHEN tm.key = 'potongan_ongkir' THEN tm.value END) AS potongan_ongkir,
            MAX(CASE WHEN tm.key = 'provinsi' THEN tm.value END) AS provinsi,
            MAX(CASE WHEN tm.key = 'kota_kabupaten' THEN tm.value END) AS kota_kabupaten,
            MAX(CASE WHEN tm.key = 'kode_pos' THEN tm.value END) AS kode_pos
        ")
            ->join('toko tk', 't.id_toko = tk.id', 'left')
            ->join('transaction_meta tm_cust', "t.id = tm_cust.transaction_id AND tm_cust.key = 'customer_id'", 'left')
            ->join('customer c', 'tm_cust.value = c.id', 'left')
            ->join('transaction_meta tm', 't.id = tm.transaction_id', 'left')
            ->where('t.id', $id)
            ->groupBy('t.id, tk.id, c.id');

        $transaction = $builder->get()->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found', null, 404);
        }

        // Hitung jatuh tempo
        if (!empty($transaction['jatuh_tempo'])) {
            $jatuhTempo = new \DateTime($transaction['jatuh_tempo']);
            $today = new \DateTime();
            $interval = $today->diff($jatuhTempo);
            $transaction['jatuh_tempo_pada'] = ($interval->invert == 0) ? $interval->days + 1 : 0;
        }

        // Ambil produk dengan single query
        $products = $db->table('sales_product sp')
            ->select("
            sp.id,
            sp.kode_barang,
            sp.jumlah,
            sp.harga_jual,
            sp.harga_system,
            sp.total,
            sp.modal_system as harga_modal,
            sp.total_modal,
            sp.actual_per_piece,
            sp.actual_total,
            sp.discount_type,
            sp.discount_amount,
            CONCAT(
                COALESCE(p.nama_barang, ''), 
                ' ', 
                COALESCE(mb.nama_model, ''), 
                ' ', 
                COALESCE(s.seri, '')
            ) as nama_lengkap_barang,
            p.nama_barang,
            mb.nama_model,
            s.seri
        ")
            ->join('product p', 'sp.kode_barang = p.id_barang', 'left')
            ->join('model_barang mb', 'mb.id = p.id_model_barang', 'left')
            ->join('seri s', 's.id = p.id_seri_barang', 'left')
            ->where('sp.id_transaction', $id)
            ->get()
            ->getResultArray();

        $transaction['item'] = $products;

        // Ambil cashflow records dengan single query (menggunakan IN instead of loop)
        $cashflowIds = $db->table('transaction_meta')
            ->select('value as cashflow_id')
            ->where('transaction_id', $id)
            ->where('key', 'cashflow_id')
            ->get()
            ->getResultArray();

        if (!empty($cashflowIds)) {
            $ids = array_column($cashflowIds, 'cashflow_id');
            $cashflowRecords = $db->table('cashflow')
                ->whereIn('id', $ids)
                ->orderBy('date_time', 'DESC')
                ->get()
                ->getResultArray();
            $transaction['cashflow_records'] = $cashflowRecords;
        } else {
            $transaction['cashflow_records'] = [];
        }

        // Ambil retur records
        $transaction['retur'] = $db->table('retur')
            ->where('transaction_id', $id)
            ->get()
            ->getResultArray();

        return $this->jsonResponse->oneResp('Success', $transaction, 200);
    }

    public function createUpdateNotesTransaction()
    {
        $transactionId = $this->request->getVar('transaction_id');
        $notes = $this->request->getVar('notes');
        $key = 'notes';

        // Cek data sudah ada atau belum
        $existingMeta = $this->transactionMeta
            ->where('transaction_id', $transactionId)
            ->where('key', $key)
            ->first();

        if ($existingMeta) {
            $result = $this->transactionMeta->update($existingMeta['id'], ['value' => $notes]);
        } else {
            $result = $this->transactionMeta->insert([
                'transaction_id' => $transactionId,
                'key' => $key,
                'value' => $notes
            ]);
        }
        return $this->jsonResponse->oneResp('Success', $result, 200);
    }
    public function calculateRevenueAndProfit()
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');
        $role = $this->request->getGet('role');

        try {
            $start_val = $date_start ? $date_start . ' 00:00:00' : null;
            $end_val = $date_end ? $date_end . ' 23:59:59' : null;
            if (is_string($role)) {
                $role = array_map('intval', explode(',', $role));
            }

            $mainResult = $this->getRevenueProfitData($start_val, $end_val, $id_toko, $role);
            $gantungResult = $this->getTransactionGantung($start_val, $end_val, $id_toko, $role);
            $waitingResult = $this->getWaitingPayment($start_val, $end_val, $id_toko, $role);

            $transaksi_gantung = floatval($gantungResult->transaction_gantung ?? 0);

            $data = [
                'total_revenue' => floatval($mainResult->total_amount ?? 0),
                'total_actual' => floatval($mainResult->total_actual ?? 0),
                'total_modal' => floatval($mainResult->total_modal ?? 0),
                'total_profit' => floatval($mainResult->total_amount - $mainResult->total_modal ?? 0),
                'total_beban' => floatval($mainResult->total_beban ?? 0),
                'transaction_gantung' => $transaksi_gantung,
                'transaksi_waiting_payment' => floatval($waitingResult->transaksi_waiting_payment ?? 0)
            ];

            return $this->jsonResponse->oneResp("Data berhasil diambil", $data, 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    public function getSalesProductWithTransactionQuery($params = [])
    {
        $sortBy = $params['sortBy'] ?? 'sp.id';
        $sortMethod = strtolower($params['sortMethod'] ?? 'asc');
        $id_toko = $params['id_toko'] ?? null;
        $start_date = $params['date_start'] ?? null;
        $end_date = $params['date_end'] ?? null;
        $role = $params['role'] ?? null;
        $search = $params['search'] ?? null;

        $start_val = $start_date ? $start_date . ' 00:00:00' : null;
        $end_val = $end_date ? $end_date . ' 23:59:59' : null;

        // Subquery untuk meta data transaksi
        $subPaid = $this->db->table('transaction_meta')
            ->select('transaction_id, MAX(value) AS value')
            ->where('key', 'paid_at')
            ->groupBy('transaction_id');

        $subPartial = $this->db->table('transaction_meta')
            ->select('transaction_id, MAX(value) AS value')
            ->where('key', 'partialy_paid_at')
            ->groupBy('transaction_id');

        $subRefunded = $this->db->table('transaction_meta')
            ->select('transaction_id, MAX(value) AS refunded_at')
            ->where('key', 'refunded_at')
            ->groupBy('transaction_id');

        $builder = $this->db->table('sales_product sp')
            ->join('transaction t', 'sp.id_transaction = t.id', 'left')
            ->join('product p', 'sp.kode_barang = p.id_barang', 'left')
            ->join('model_barang mb', 'p.id_model_barang = mb.id', 'left')
            ->join('seri s', 'p.id_seri_barang = s.id', 'left')
            ->join("({$subPaid->getCompiledSelect()}) tm_paid", 'tm_paid.transaction_id = t.id', 'left')
            ->join("({$subPartial->getCompiledSelect()}) tm_partial", 'tm_partial.transaction_id = t.id', 'left')
            ->join("({$subRefunded->getCompiledSelect()}) tm_refunded", 'tm_refunded.transaction_id = t.id', 'left');

        // WHERE Status transaksi & logika DP/Paid/Refunded
        $builder->groupStart();
        // Status normal
        $builder->whereIn('t.status', [
            'SUCCESS',
            'PAID',
            'PACKING',
            'IN_DELIVERY',
            'PARTIALLY_PAID',
            'RETUR'
        ]);

        // Status REFUNDED (hanya kalau ada DP atau Paid)
        $builder->orGroupStart();
        $builder->where('t.status', 'REFUNDED');
        $builder->groupStart();
        $builder->where('tm_paid.value IS NOT NULL');
        $builder->orWhere('tm_partial.value IS NOT NULL');
        $builder->groupEnd();
        $builder->groupEnd();
        $builder->groupEnd();

        // Filter toko
        if (!empty($role)) {
            $builder->whereIn('t.id_toko', $role);
        }
        if (!empty($id_toko)) {
            $builder->like('t.id_toko', (string) $id_toko, 'both');
        }

        // Filter tanggal
        if ($start_val && $end_val) {
            $builder->where("
                (
                    tm_paid.value BETWEEN '{$start_val}' AND '{$end_val}' OR
                    tm_partial.value BETWEEN '{$start_val}' AND '{$end_val}' OR
                    tm_refunded.refunded_at BETWEEN '{$start_val}' AND '{$end_val}'
                )
            ");
        }

        // Filter pencarian
        if ($search) {
            $builder->groupStart()
                ->like('t.invoice', $search)
                ->orLike('p.nama_barang', $search)
                ->orLike('mb.nama_model', $search)
                ->orLike('s.seri', $search)
                ->groupEnd();
        }

        // SELECT + logika pengurangan REFUNDED jika dalam range tanggal
        $select = "
            sp.*,
            t.invoice, t.date_time, t.status, t.id_toko,
            p.nama_barang, p.id_barang, mb.nama_model, s.seri,
            tm_paid.value as paid_at,
            tm_partial.value as dp_at,
            tm_refunded.refunded_at as refunded_at,
            CONCAT(
                COALESCE(p.nama_barang, ''), ' ',
                COALESCE(mb.nama_model, ''), ' ',
                COALESCE(s.seri, '')
            ) AS nama_lengkap_barang,

            IF(
                tm_refunded.refunded_at BETWEEN '{$start_val}' AND '{$end_val}',
                0,
                sp.actual_total
            ) AS actual_total,

            IF(
                tm_refunded.refunded_at BETWEEN '{$start_val}' AND '{$end_val}',
                0,
                sp.total_modal
            ) AS total_modal
        ";

        return $builder->select($select, FALSE)
            ->orderBy($sortBy, $sortMethod);
    }
    public function getRevenueProfitData($start_val, $end_val, $id_toko = null, $role = null)
    {
        $db = \Config\Database::connect();


        $builder = $db->table('transaction');
        $builder->select('
            SUM(total_payment) AS total_amount,
            SUM(actual_total) AS total_actual,
            SUM(total_modal) AS total_modal
        ');
        $builder->whereNotIn('transaction.status', ['WAITING_PAYMENT', 'REFUNDED']);

        $builder->where('DATE(updated_at) >=', $start_val);
        $builder->where('DATE(updated_at) <=', $end_val);

        // Filter toko (role banyak atau id_toko tunggal)
        if (!empty($role)) {
            $builder->whereIn('transaction.id_toko', $role);
        }

        if (!empty($id_toko)) {
            $builder->like('transaction.id_toko', (string) $id_toko, 'both');
        }

        return $builder->get()->getRow();
    }
    private function getTransactionGantung($start_val, $end_val, $id_toko, $role)
    {
        $query = $this->db->table('transaction')
            ->select('SUM(amount) - SUM(total_payment) AS transaction_gantung')
            ->where('status', 'PARTIALLY_PAID');

        if (!empty($role) && !$id_toko) {
            $query->whereIn('id_toko', $role);
        }
        if ($id_toko) {
            $query->where('id_toko', $id_toko);
        }

        return $query->get()->getRow();
    }
    private function getWaitingPayment($start_val, $end_val, $id_toko, $role)
    {
        $query = $this->db->table('transaction')
            ->select('SUM(amount) AS transaksi_waiting_payment')
            ->where('status', 'WAITING_PAYMENT');

        if (!empty($role) && !$id_toko) {
            $query->whereIn('id_toko', $role);
        }
        if ($id_toko) {
            $query->where('id_toko', $id_toko);
        }

        return $query->get()->getRow();
    }
    public function listSalesProductWithTransaction()
    {
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $page = (int) $this->request->getGet('page') ?: 1;
        $role = $this->request->getGet('role');
        $offset = ($page - 1) * $limit;

        if (is_string($role)) {
            $role = array_filter(array_map('intval', explode(',', $role)), fn($v) => $v > 0);
        }


        $params = [
            'sortBy' => $this->request->getGet('sortBy'),
            'sortMethod' => $this->request->getGet('sortMethod'),
            'id_toko' => $this->request->getGet('id_toko'),
            'date_start' => $this->request->getGet('date_start'),
            'date_end' => $this->request->getGet('date_end'),
            'role' => $role,
            'search' => $this->request->getGet('search'),
        ];

        $query = $this->getSalesProductWithTransactionQuery($params);

        $total_data = $query->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        $result = $query->limit($limit, $offset)->get()->getResult();

        $start_val = $params['date_start'] ? $params['date_start'] . ' 00:00:00' : null;
        $end_val = $params['date_end'] ? $params['date_end'] . ' 23:59:59' : null;

        $mainResult = $this->getRevenueProfitData($start_val, $end_val, $params['id_toko'], $params['role']);
        $gantungResult = $this->getTransactionGantung($start_val, $end_val, $params['id_toko'], $params['role']);
        $waitingResult = $this->getWaitingPayment($start_val, $end_val, $params['id_toko'], $params['role']);
        $transaksi_gantung = floatval($gantungResult->transaction_gantung ?? 0);

        return $this->jsonResponse->multiResp(
            '',
            [
                'sum' => [
                    'total_revenue' => ($mainResult->total_revenue ?? 0),
                    'total_modal' => floatval($mainResult->total_modal ?? 0),
                    'total_profit' => ($mainResult->total_profit ?? 0),
                    'transaction_gantung' => $transaksi_gantung,
                    'transaksi_waiting_payment' => floatval($waitingResult->transaksi_waiting_payment ?? 0)
                ],
                'result' => $result
            ],
            $total_data,
            $total_page,
            $page,
            $limit,
            200
        );
    }
    public function listSalesProductWithTransactionBaru()
    {
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $page = (int) $this->request->getGet('page') ?: 1;
        $offset = ($page - 1) * $limit;

        $sortBy = $this->request->getGet('sortBy') ?: 'transaction.created_at';
        $sortMethod = $this->request->getGet('sortMethod') === 'desc' ? 'DESC' : 'ASC';
        $idToko = $this->request->getGet('id_toko');
        $search = $this->request->getGet('search');
        $dateStart = $this->request->getGet('date_start');
        $dateEnd = $this->request->getGet('date_end');
        $status = $this->request->getGet('status');
        $role = $this->request->getGet('role');

        // Base builder for main query
        $baseBuilder = $this->db->table('sales_product sp')
            ->select("sp.*, transaction.invoice, transaction.date_time, transaction.status, transaction.id_toko,
          p.nama_barang, p.id_barang, mb.nama_model, s.seri,
          CONCAT(
              COALESCE(p.nama_barang, ''), ' ',
              COALESCE(mb.nama_model, ''), ' ',
              COALESCE(s.seri, '')
          ) AS nama_lengkap_barang")
            ->join('transaction', 'sp.id_transaction = transaction.id')
            ->join('product p', 'sp.kode_barang = p.id_barang', 'left')
            ->join('model_barang mb', 'p.id_model_barang = mb.id', 'left')
            ->join('seri s', 'p.id_seri_barang = s.id', 'left');

        if (is_string($role)) {
            $role = array_map('intval', explode(',', $role));
        }

        if (!empty($role) && !$idToko) {
            $baseBuilder->whereIn('transaction.id_toko', $role);
        }
        if ($idToko) {
            $baseBuilder->where('sp.id_toko', $idToko); // Fixed: changed to alias sp
        }

        if ($dateStart) {
            $baseBuilder->where('transaction.date_time >=', $dateStart . ' 00:00:00');
        }

        if ($dateEnd) {
            $baseBuilder->where('transaction.date_time <=', $dateEnd . ' 23:59:59');
        }

        if ($search) {
            $baseBuilder->groupStart()
                ->like('sp.kode_barang', $search)
                ->orLike('transaction.invoice', $search)
                ->orLike('p.nama_barang', $search)
                ->orLike('mb.nama_model', $search)
                ->orLike('s.seri', $search)
                ->groupEnd();
        }

        if ($status === 'paid') {
            $baseBuilder->whereIn('transaction.status', [
                'SUCCESS',
                'PAID',
                'PACKING',
                'IN_DELIVERY',
                'PARTIALLY_PAID',
                'RETUR'
            ]);
        } elseif ($status === 'unpaid') {
            $baseBuilder->where('transaction.status', 'WAITING_PAYMENT');
        }

        // Clone builder for count
        $countBuilder = clone $baseBuilder;
        $total_data = $countBuilder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        // Clone builder for sum
        $sumBuilder = clone $baseBuilder;
        $sumBuilder->select('SUM(sp.actual_total) as total_actual, SUM(sp.total_modal) as total_modal');
        $sumResult = $sumBuilder->get()->getRow();

        $total_actual = floatval($sumResult->total_actual ?? 0);
        $total_modal = floatval($sumResult->total_modal ?? 0);

        // Query paginated data
        $result = $baseBuilder
            ->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        // Return response
        return $this->jsonResponse->multiResp(
            '',
            [
                'sum' => [
                    'total_modal' => $total_modal,
                    'total_actual' => $total_actual,
                ],
                'result' => $result,
            ],
            $total_data,
            $total_page,
            $page,
            $limit,
            200
        );
    }
    public function calculateExpenseAllocation()
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');
        $type = $this->request->getGet('type');
        $role = $this->request->getGet('role');

        try {
            $query = $this->db->table('cashflow')
                ->select('type, SUM(credit) AS total_credit')
                ->where('credit >', 0)
                ->groupBy('type');

            if ($date_start && $date_end) {
                $start_val = $date_start . ' 00:00:00';
                $end_val = $date_end . ' 23:59:59';
                $query->where("date_time BETWEEN '{$start_val}' AND '{$end_val}'");

            } elseif ($date_start) {
                $start_val = $date_start . ' 00:00:00';
                $query->where("date_time >= '{$start_val}'");

            } elseif ($date_end) {
                $end_val = $date_end . ' 23:59:59';
                $query->where("date_time <= '{$end_val}'");
            }

            if (is_string($role)) {
                $role = array_map('intval', explode(',', $role));
            }

            if (!empty($role) && !$id_toko) {
                $query->whereIn('id_toko', $role);
            }

            if ($id_toko) {
                $query->where('id_toko', $id_toko);
            }

            if (!empty($type)) {
                $types = explode(',', $type);
                $query = $query->whereIn('type', array_map('trim', $types));
            }

            $results = $query->get()->getResult();

            $categories = [];
            $sales = [];

            foreach ($results as $row) {
                $percentage = $row->total_credit;
                $categories[] = $row->type;
                $sales[] = round($percentage, 2);
            }

            return $this->jsonResponse->oneResp("Data berhasil diambil", [
                "categories" => $categories,
                "series" => $sales
            ], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    public function topCustomers($limit = 10)
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');
        $role = $this->request->getGet('role');
        try {
            // Perbaiki query untuk memastikan join dan kondisi WHERE tepat
            $query = $this->db->table('transaction t')
                ->select('c.id AS customer_id, c.nama_customer, COUNT(DISTINCT t.id) AS total_transactions, SUM(t.amount) AS total_amount_spent')
                ->join('transaction_meta tm', 't.id = tm.transaction_id AND tm.key = "customer_id" AND tm.value IS NOT NULL AND tm.value != ""', 'inner')
                ->join('customer c', 'c.id = tm.value', 'left')
                ->whereIn('t.status', ['SUCCESS', 'PAID', 'PACKING', 'IN_DELIVERY', 'PARTIALLY_PAID', 'RETUR'])
                ->groupBy('c.id, c.nama_customer')
                ->orderBy('total_transactions', 'DESC')
                ->limit($limit);

            if ($date_start && $date_end) {
                $start_val = $date_start . ' 00:00:00';
                $end_val = $date_end . ' 23:59:59';
                $query->where("t.date_time BETWEEN '{$start_val}' AND '{$end_val}'");

            } elseif ($date_start) {
                $start_val = $date_start . ' 00:00:00';
                $query->where("t.date_time >= '{$start_val}'");

            } elseif ($date_end) {
                $end_val = $date_end . ' 23:59:59';
                $query->where("t.date_time <= '{$end_val}'");
            }

            if (is_string($role)) {
                $role = array_map('intval', explode(',', $role));
            }

            if (!empty($role) && !$id_toko) {
                $query->whereIn('t.id_toko', $role);
            }

            // Menambahkan filter toko jika ada
            if ($id_toko) {
                $query->where('t.id_toko', $id_toko);
            }

            // Mendapatkan hasil query
            $results = $query->get()->getResult();

            if ($results) {
                return $this->jsonResponse->oneResp("Data berhasil diambil", $results, 200);
            } else {
                return $this->jsonResponse->error("Tidak ada data untuk kriteria ini", 404);
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    public function topSoldProducts()
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $role = $this->request->getGet('role');
        $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
        $page = max((int) ($this->request->getGet('page') ?: 1), 1);
        $offset = ($page - 1) * $limit;
        $namaProduct = $this->request->getGet('namaProduct') ?? '';

        try {
            $query = $this->db->table('sales_product')
                ->select('sales_product.kode_barang, product.nama_barang, model_barang.nama_model, 
                    COALESCE(seri.seri, "Tidak Ada Seri") AS seri, 
                    SUM(sales_product.jumlah) AS total_sold,  
                    CONCAT(COALESCE(product.nama_barang, ""), " ", COALESCE(model_barang.nama_model, ""), " ", COALESCE(seri.seri, "")) as nama_lengkap_barang,
                    (
                        SELECT COALESCE(SUM(stock.stock), 0) 
                        FROM stock 
                        WHERE stock.id_barang = sales_product.kode_barang 
                        AND stock.dropship = 0
                    ) AS total_stock')
                ->join('transaction', 'sales_product.id_transaction = transaction.id')
                ->join('product', 'sales_product.kode_barang = product.id_barang')
                ->join('model_barang', 'product.id_model_barang = model_barang.id')
                ->join('seri', 'product.id_seri_barang = seri.id', 'left')
                ->whereIn('transaction.status', ['SUCCESS', 'PAID', 'PACKING', 'IN_DELIVERY', 'PARTIALLY_PAID', 'RETUR'])
                ->groupBy(['sales_product.kode_barang', 'product.nama_barang', 'model_barang.nama_model', 'seri.seri'])
                ->orderBy('total_sold', 'DESC');

            if (!empty($namaProduct)) {
                $query->groupStart()
                    ->like("CONCAT_WS(' ', product.nama_barang, model_barang.nama_model, seri.seri)", $namaProduct)
                    ->orLike("product.id_barang", $namaProduct)
                    ->groupEnd();
            }

            if ($date_start && $date_end) {
                $start_val = $date_start . ' 00:00:00';
                $end_val = $date_end . ' 23:59:59';
                $query->where("transaction.date_time BETWEEN '{$start_val}' AND '{$end_val}'");

            } elseif ($date_start) {
                $start_val = $date_start . ' 00:00:00';
                $query->where("transaction.date_time >= '{$start_val}'");

            } elseif ($date_end) {
                $end_val = $date_end . ' 23:59:59';
                $query->where("transaction.date_time <= '{$end_val}'");
            }

            if (is_string($role)) {
                $role = array_map('intval', explode(',', $role));
            }

            if (!empty($role)) {
                $query->whereIn('transaction.id_toko', $role);
            }

            $total_data = $query->countAllResults(false); // Hitung total data
            $query->limit($limit, $offset);

            $result = $query->get()->getResultArray();

            $total_page = ceil($total_data / $limit);


            return $this->jsonResponse->multiResp(
                'Success',
                $result,
                $total_data,
                $total_page,
                $page,
                $limit,
                200
            );


        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    public function listKeluarBarang()
    {
        $role = $this->request->getGet('role');
        $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
        $page = max((int) ($this->request->getGet('page') ?: 1), 1);
        $offset = ($page - 1) * $limit;
        $kode = $this->request->getGet('kode') ?? '';

        try {
            $builder = $this->db->table('sales_product')
                ->select([
                    'sales_product.*',
                    'transaction.status',
                    'product.nama_barang',
                    'model_barang.nama_model',
                    'seri.seri',
                    'toko.toko_name',
                    'transaction.invoice',
                    'transaction.date_time',
                    'COALESCE(c.nama_customer, tm_name.value) AS customer_name',
                    'CONCAT(product.nama_barang, " ", model_barang.nama_model, " ", COALESCE(seri.seri, "")) AS nama_lengkap_barang'
                ])
                ->join('transaction', 'transaction.id = sales_product.id_transaction', 'left')
                ->whereIn('transaction.status', ['SUCCESS', 'PAID', 'PACKING', 'IN_DELIVERY', 'PARTIALLY_PAID', 'WAITING_PAYMENT', 'RETUR'])
                ->join('product', 'product.id_barang = sales_product.kode_barang', 'left')
                ->join('model_barang', 'model_barang.id = product.id_model_barang', 'left')
                ->join('seri', 'seri.id = product.id_seri_barang', 'left')
                ->join('toko', 'transaction.id_toko = toko.id', 'left')
                ->join('transaction_meta tm_cust', 'transaction.id = tm_cust.transaction_id AND tm_cust.key = "customer_id"', 'left')
                ->join('customer c', 'c.id = tm_cust.value', 'left')
                ->join('transaction_meta tm_name', 'tm_name.transaction_id = transaction.id AND tm_name.key = "customer_name"', 'left');

            if (!empty($kode)) {
                $builder->like('sales_product.kode_barang', $kode);
            }

            if (!empty($role)) {
                if (is_string($role)) {
                    $role = array_map('intval', explode(',', $role));
                }
                $builder->whereIn('transaction.id_toko', $role);
            }

            $total_data = $builder->countAllResults(false); // hitung total, tanpa reset query
            $builder->limit($limit, $offset);
            $result = $builder->get()->getResultArray();
            $total_page = ceil($total_data / $limit);

            return $this->jsonResponse->multiResp(
                'Success',
                $result,
                $total_data,
                $total_page,
                $page,
                $limit,
                200
            );

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    public function getFinancialSummary()
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');
        $role = $this->request->getGet('role');

        try {
            $query = $this->db->table('transaction')
                ->select("DATE(date_time) AS tanggal, 
                      COUNT(id) AS sales, 
                      SUM(CASE WHEN status IN ('SUCCESS', 'PAID', 'PACKING', 'IN_DELIVERY', 'PARTIALLY_PAID') THEN amount ELSE 0 END) AS revenue")
                ->groupBy('tanggal')
                ->orderBy('tanggal', 'ASC');

            if ($date_start && $date_end) {
                $start_val = $date_start . ' 00:00:00';
                $end_val = $date_end . ' 23:59:59';
                $query->where("date_time BETWEEN '{$start_val}' AND '{$end_val}'");

            } elseif ($date_start) {
                $start_val = $date_start . ' 00:00:00';
                $query->where("date_time >= '{$start_val}'");

            } elseif ($date_end) {
                $end_val = $date_end . ' 23:59:59';
                $query->where("date_time <= '{$end_val}'");
            }


            if (is_string($role)) {
                $role = array_map('intval', explode(',', $role));
            }

            if (!empty($role) && !$id_toko) {
                $query->whereIn('id_toko', $role);
            }

            if ($id_toko) {
                $query->where('transaction.id_toko', $id_toko);
            }

            $data = $query->get()->getResultArray();

            // Formatting the output
            $categories = [];
            $salesData = [];
            $revenueData = [];

            foreach ($data as $row) {
                $categories[] = $row['tanggal'];
                $salesData[] = (float) $row['sales'];
                $revenueData[] = (float) $row['revenue'];
            }


            return $this->jsonResponse->oneResp("Data berhasil diambil", [
                'categories' => $categories,
                'series' => [
                    [
                        'name' => 'Sales',
                        'data' => $salesData,
                    ],
                    [
                        'name' => 'Revenue',
                        'data' => $revenueData,
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function updateTransactionStatusToRefunded($transactionId)
    {
        $token = $this->request->user;
        $db = \Config\Database::connect();

        $db->transBegin();

        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->whereIn('status', ['CANCEL', 'NEED_REFUNDED'])
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found or not eligible for refund', null, 404);
        }

        // Cek nilai refund
        $refundedAmountMeta = $db->table('transaction_meta')
            ->where('transaction_id', $transactionId)
            ->where('key', 'refunded_amount')
            ->get()
            ->getRowArray();

        $refundValue = $refundedAmountMeta
            ? (float) $refundedAmountMeta['value']
            : (float) $transaction['total_payment'];

        // Update nominal pembayaran
        $db->table('transaction')
            ->where('id', $transactionId)
            ->update([
                'total_payment' => $transaction['total_payment'] - $refundValue,
                'updated_at' => date('Y-m-d H:i:s'),
                'closing' => $transaction['closing'] !== "0" ? 2 : 0
            ]);

        // Insert ke cashflow
        $cashflowData = [
            'debit' => 0,
            'credit' => $refundValue,
            'noted' => "Refund Transaksi " . $transaction['invoice'],
            'type' => 'Transaction',
            'status' => 'SUCCESS',
            'date_time' => date('Y-m-d H:i:s'),
            'id_toko' => $transaction['id_toko']
        ];
        $db->table('cashflow')->insert($cashflowData);
        $cashflowId = $db->insertID();



        // Cek apakah ada complaint
        $hasComplaint = $db->table('transaction_meta')
            ->where('transaction_id', $transactionId)
            ->where('key', 'complaint')
            ->countAllResults() > 0;

        $finalStatus = $hasComplaint ? 'RETUR' : 'REFUNDED';

        // Update status transaksi
        $db->table('transaction')
            ->where('id', $transactionId)
            ->update([
                'status' => $finalStatus,
                'updated_by' => $token['user_id']
            ]);

        // Simpan metadata tambahan
        $metaData = [
            ['transaction_id' => $transactionId, 'key' => 'refunded_at', 'value' => date('Y-m-d H:i:s')],
            ['transaction_id' => $transactionId, 'key' => 'cashflow_id', 'value' => (string) $cashflowId],
        ];

        if (!$refundedAmountMeta) {
            $metaData[] = [
                'transaction_id' => $transactionId,
                'key' => 'refunded_amount',
                'value' => (string) $refundValue
            ];
        }

        foreach ($metaData as $data) {
            $db->table('transaction_meta')->insert($data);
        }

        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->jsonResponse->oneResp('Failed to update transaction', null, 500);
        } else {
            $db->transCommit();
            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'UPDATE',
                'target_table' => 'transactions',
                'target_id' => $transactionId,
                'description' => "Refund transaksi $transactionId sebesar $refundValue",
            ]);
            return $this->jsonResponse->oneResp("Transaction status updated to $finalStatus", null, 200);
        }
    }
    public function updateTransactionStatusToCancel($transactionId)
    {
        $token = $this->request->user;
        $db = \Config\Database::connect();

        // Start a transaction
        $db->transBegin();

        try {
            // Retrieve the transaction
            $transaction = $db->table('transaction')
                ->where('id', $transactionId)
                ->whereIn('status', ['SUCCESS', 'PAID', 'WAITING_PAYMENT', 'PARTIALLY_PAID'])
                ->get()
                ->getRowArray();

            if (!$transaction) {
                return $this->jsonResponse->oneResp('Transaction not found or not eligible for cancellation', null, 404);
            }

            $data = $this->request->getJSON();
            $validation = \Config\Services::validation();
            $validation->setRules([
                'cancel_reason' => 'required',
                'barang_cacat' => 'required',
            ]);

            if (!$validation->run((array) $data)) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $cancelReason = $data->cancel_reason;
            $barangCacat = $data->barang_cacat === "true";

            // Tentukan status baru
            $newStatus = ($transaction['status'] === 'WAITING_PAYMENT') ? 'CANCEL' : 'NEED_REFUNDED';

            // Update status transaksi
            $updateData = [
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s'),
                'closing' => $transaction['closing'] !== "0" ? 2 : 0,
                'updated_by' => $token['user_id']
            ];

            $db->table('transaction')
                ->where('id', $transactionId)
                ->update($updateData);

            // Ambil produk terkait transaksi
            $products = $db->table('sales_product')
                ->where('id_transaction', $transactionId)
                ->get()
                ->getResultArray();

            // Ambil semua kode_barang
            $kodeBarangList = array_column($products, 'kode_barang');

            // Ambil mapping kode_barang -> id dari tabel product
            $productRows = $db->table('product')
                ->select('id_barang, id')
                ->whereIn('id_barang', $kodeBarangList)
                ->get()
                ->getResultArray();

            $productMap = [];
            foreach ($productRows as $p) {
                $productMap[$p['id_barang']] = $p['id'];
            }

            // Kembalikan stok & log aktivitas
            foreach ($products as $product) {
                $updateField = $barangCacat ? 'barang_cacat' : 'stock';

                $db->table('stock')
                    ->where('id_barang', $product['kode_barang'])
                    ->where('id_toko', $transaction['id_toko'])
                    ->set($updateField, "$updateField + {$product['jumlah']}", false)
                    ->update();

                $idProduct = $productMap[$product['kode_barang']] ?? null;
                if ($idProduct) {
                    log_aktivitas([
                        'user_id' => $token['user_id'],
                        'action_type' => 'UPDATE',
                        'target_table' => 'product',
                        'target_id' => $idProduct,
                        'description' => "Mengembalikan stok produk {$product['kode_barang']} pada toko {$transaction['id_toko']} sebanyak {$product['jumlah']} $updateField dari transaksi $transactionId.",
                        'detail' => []
                    ]);
                }
            }

            // Simpan metadata pembatalan
            $metaData = [
                [
                    'transaction_id' => $transactionId,
                    'key' => 'cancel_at',
                    'value' => date('Y-m-d H:i:s')
                ],
                [
                    'transaction_id' => $transactionId,
                    'key' => 'cancel_reason',
                    'value' => $cancelReason
                ]
            ];

            foreach ($metaData as $data) {
                $db->table('transaction_meta')->insert($data);
            }

            $db->transCommit();

            // Log aktivitas transaksi
            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'UPDATE',
                'target_table' => 'transactions',
                'target_id' => $transactionId,
                'description' => "Update transaksi $transactionId menjadi $newStatus karena $cancelReason",
            ]);

            return $this->jsonResponse->oneResp(
                "Transaction status updated to $newStatus",
                null,
                200
            );

        } catch (\Exception $e) {
            $db->transRollback();
            return $this->jsonResponse->oneResp(
                'Failed to update transaction: ' . $e->getMessage(),
                null,
                500
            );
        }
    }
    public function updateTransactionStatusToPartiallyPaid($transactionId)
    {
        $token = $this->request->user;

        $db = \Config\Database::connect();
        $db->transBegin();

        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->where('status', 'WAITING_PAYMENT')
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->error('Transaction not found or not in waiting payment status', 404);
        }

        $data = $this->request->getJSON();
        $metode = $data->metode_pembayaran;
        $amount = $data->amount;

        // Validasi input
        $validation = \Config\Services::validation();
        $validation->setRules([
            'amount' => 'required',
            'metode_pembayaran' => 'required',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }


        $newTotalPayment = $transaction['total_payment'] + $amount;

        if ((float) $amount > (float) (95 * $transaction['amount'] / 100)) {
            return $this->jsonResponse->oneResp('Jumlah Pembayaran tidak valid', null, 400);
        }

        $db->table('transaction')
            ->where('id', $transactionId)
            ->update([
                'status' => 'PARTIALLY_PAID',
                'updated_at' => date('Y-m-d H:i:s'),
                'closing' => $transaction['closing'] !== "0" ? 2 : 0,
                'updated_by' => $token['user_id'],
                'total_payment' => $newTotalPayment
            ]);

        $cashflowData = [
            'debit' => $amount,
            'credit' => 0,
            'noted' => "DP Transaksi " . $transaction['invoice'],
            'type' => 'Transaction',
            'status' => 'SUCCESS',
            'date_time' => date('Y-m-d H:i:s'),
            'id_toko' => $transaction['id_toko'],
            'metode' => $metode
        ];

        $db->table('cashflow')->insert($cashflowData);
        $cashflowId = $db->insertID();


        $metaData = [
            [
                'transaction_id' => (string) $transactionId,
                'key' => 'partialy_paid_at',
                'value' => date('Y-m-d H:i:s')
            ],
            [
                'transaction_id' => (string) $transactionId,
                'key' => 'cashflow_id',
                'value' => (string) $cashflowId
            ],
            [
                'transaction_id' => (string) $transactionId,
                'key' => 'metode_pembayaran_dp',
                'value' => (string) $metode
            ],
            [
                'transaction_id' => (string) $transactionId,
                'key' => 'total_dp',
                'value' => (string) $amount
            ]
        ];

        foreach ($metaData as $data) {
            $data['key'] = (string) $data['key'];
            $data['value'] = (string) $data['value'];

            $db->table('transaction_meta')->insert($data);
        }




        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->jsonResponse->error('Failed to update transaction', 500);
        } else {
            $db->transCommit();
            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'UPDATE',
                'target_table' => 'transactions',
                'target_id' => $transactionId,
                'description' => "Update transaksi {$transactionId} menjadi DP sebesar {$amount} menggunakan metode {$metode}",
            ]);
            return $this->jsonResponse->oneResp('Transaction status updated to partially paid', null, 200);
        }
    }

    public function updateTransactionStatusToFullyPaid($transactionId)
    {
        $token = $this->request->user;
        $db = \Config\Database::connect();

        $db->transStart();

        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->whereIn('status', ['WAITING_PAYMENT', 'PARTIALLY_PAID'])
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->error('Transaksi tidak ditemukan / tidak dapat diproses', 404);
        }

        $data = $this->request->getJSON();
        $validation = \Config\Services::validation();
        $validation->setRules([
            'metode_pembayaran' => 'required',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $newTotalPayment = $transaction['amount'] - $transaction['total_payment'];
        $newTotalPembayaran = $newTotalPayment + $transaction['total_payment'];

        // CEK ONGKIR YANG BENAR: dari transaction_meta -> cashflow
        $ongkirAlreadyPaid = false;
        $cashflowIds = $db->table('transaction_meta')
            ->select('value as cashflow_id')
            ->where('transaction_id', $transactionId)
            ->where('key', 'cashflow_id')
            ->get()
            ->getResultArray();

        if (!empty($cashflowIds)) {
            $cashflowIdList = array_column($cashflowIds, 'cashflow_id');

            $existingOngkir = $db->table('cashflow')
                ->whereIn('id', $cashflowIdList)
                ->where('credit >', 0)
                ->like('noted', 'Ongkos Kirim')
                ->countAllResults();

            $ongkirAlreadyPaid = $existingOngkir > 0;
        }

        $ongkirMeta = $db->table('transaction_meta')
            ->select('value')
            ->where('transaction_id', $transactionId)
            ->where('key', 'biaya_pengiriman')
            ->get()
            ->getRowArray();

        $ongkir = isset($ongkirMeta['value']) ? (float) $ongkirMeta['value'] : 0;



        // Update status transaksi
        $db->table('transaction')
            ->where('id', $transactionId)
            ->update([
                'status' => 'PAID',
                'updated_by' => $token['user_id'],
                'updated_at' => date('Y-m-d H:i:s'),
                'closing' => $transaction['closing'] !== "0" ? 2 : 0,
                'total_payment' => $newTotalPembayaran
            ]);

        $dateTime = date('Y-m-d H:i:s');

        // Update paid_at jika sudah ada, atau insert baru
        $existingPaidAt = $db->table('transaction_meta')
            ->where('transaction_id', $transactionId)
            ->where('key', 'paid_at')
            ->get()
            ->getRowArray();

        if ($existingPaidAt) {
            $db->table('transaction_meta')
                ->where('id', $existingPaidAt['id'])
                ->update(['value' => $dateTime]);
        } else {
            $db->table('transaction_meta')->insert([
                'transaction_id' => $transactionId,
                'key' => 'paid_at',
                'value' => $dateTime
            ]);
        }

        // Update metode pembayaran pelunasan
        $existingMetode = $db->table('transaction_meta')
            ->where('transaction_id', $transactionId)
            ->where('key', 'metode_pembayaran_pelunasan')
            ->get()
            ->getRowArray();

        if ($existingMetode) {
            $db->table('transaction_meta')
                ->where('id', $existingMetode['id'])
                ->update(['value' => (string) $data->metode_pembayaran]);
        } else {
            $db->table('transaction_meta')->insert([
                'transaction_id' => $transactionId,
                'key' => 'metode_pembayaran_pelunasan',
                'value' => (string) $data->metode_pembayaran
            ]);
        }

        // Insert pemasukan produk
        $cashflowIdProduk = $this->CashflowModel->insert([
            'debit' => $newTotalPayment,
            'credit' => 0,
            'noted' => "Pelunasan Produk Transaksi " . $transaction['invoice'],
            'type' => 'Transaction',
            'status' => 'SUCCESS',
            'date_time' => $dateTime,
            'id_toko' => $transaction['id_toko'],
            'metode' => $data->metode_pembayaran,
            'transaction_id' => $transactionId
        ]);

        if ($cashflowIdProduk) {
            // Cek apakah cashflow_id sudah ada
            $existingCashflow = $db->table('transaction_meta')
                ->where('transaction_id', $transactionId)
                ->where('key', 'cashflow_id')
                ->where('value', (string) $cashflowIdProduk)
                ->get()
                ->getRowArray();

            if (!$existingCashflow) {
                $db->table('transaction_meta')->insert([
                    'transaction_id' => $transactionId,
                    'key' => 'cashflow_id',
                    'value' => (string) $cashflowIdProduk
                ]);
            }
        }

        // Insert pengeluaran ongkir HANYA jika belum pernah dibayar
        if ($ongkir > 0 && !$ongkirAlreadyPaid) {
            $cashflowIdOngkir = $this->CashflowModel->insert([
                'debit' => 0,
                'credit' => $ongkir,
                'noted' => "Ongkos Kirim Transaksi " . $transaction['invoice'],
                'type' => 'Transaction',
                'status' => 'SUCCESS',
                'date_time' => $dateTime,
                'id_toko' => $transaction['id_toko'],
                'metode' => $data->metode_pembayaran,
                'transaction_id' => $transactionId
            ]);

            if ($cashflowIdOngkir) {
                // Cek apakah cashflow_id sudah ada
                $existingCashflowOngkir = $db->table('transaction_meta')
                    ->where('transaction_id', $transactionId)
                    ->where('key', 'cashflow_id')
                    ->where('value', (string) $cashflowIdOngkir)
                    ->get()
                    ->getRowArray();

                if (!$existingCashflowOngkir) {
                    $db->table('transaction_meta')->insert([
                        'transaction_id' => $transactionId,
                        'key' => 'cashflow_id',
                        'value' => (string) $cashflowIdOngkir
                    ]);
                }
            }
        }



        // Selesaikan transaction
        $db->transComplete();

        // Cek status transaction
        if ($db->transStatus() === false) {
            return $this->jsonResponse->oneResp('Gagal memperbarui transaksi', null, 500);
        }

        $logDescription = "Update transaksi {$transactionId} menjadi Full Payment menggunakan metode {$data->metode_pembayaran}";
        if ($ongkir > 0 && !$ongkirAlreadyPaid) {
            $logDescription .= " + ongkos kirim";
        } elseif ($ongkir > 0 && $ongkirAlreadyPaid) {
            $logDescription .= " (ongkos kirim sudah dibayar sebelumnya)";
        }

        log_aktivitas([
            'user_id' => $token['user_id'],
            'action_type' => 'UPDATE',
            'target_table' => 'transactions',
            'target_id' => $transactionId,
            'description' => $logDescription,
            'detail' => [
                'amount_paid' => $newTotalPayment,
                'ongkir_paid' => !$ongkirAlreadyPaid && $ongkir > 0,
                'ongkir_already_paid' => $ongkirAlreadyPaid,
                'previous_status' => $transaction['status'],
                'new_total_payment' => $newTotalPembayaran
            ]
        ]);

        return $this->jsonResponse->oneResp('Transaksi berhasil dilunasi dan dicatat di cashflow', null, 200);
    }
    public function complainProduct($transactionId)
    {
        $token = $this->request->user;
        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            // Get transaction data
            $transaction = $db->table('transaction')
                ->where('id', $transactionId)
                ->whereIn('status', ['SUCCESS'])
                ->get()
                ->getRowArray();

            if (!$transaction) {
                log_message('error', 'Transaction not found or not eligible for complaint');
                return $this->jsonResponse->oneResp('Transaction not found or not eligible for complaint', null, 404);
            }

            // Get discount from transaction_meta
            $discountMeta = $db->table('transaction_meta')
                ->where('transaction_id', $transactionId)
                ->like('key', 'discount')
                ->get()
                ->getRowArray();

            $discount = $discountMeta ? (float) $discountMeta['value'] : 0;

            $data = $this->request->getJSON();
            if (!isset($data->products)) {
                return $this->jsonResponse->oneResp('Invalid request data', null, 400);
            }

            $products = $data->products;
            $totalRefundAmount = 0;

            foreach ($products as $product) {
                // Validate product data
                if (!isset($product->kode_barang) || !isset($product->jumlah) || !isset($product->barang_cacat) || !isset($product->solution)) {
                    throw new \RuntimeException('Invalid product data structure');
                }

                $id_toko = $transaction['id_toko'];
                $kode_barang = $product->kode_barang;
                $jumlah = (int) $product->jumlah;
                $barang_cacat = (bool) $product->barang_cacat;
                $solution = $product->solution;

                // Get product stock
                $stock = $this->stockModel
                    ->select('stock.*, product.id AS product_id')
                    ->join('product', 'product.id_barang = stock.id_barang')
                    ->where('stock.id_toko', $id_toko)
                    ->where('stock.id_barang', $kode_barang)
                    ->get()
                    ->getRowArray();


                if (!$stock) {
                    return $this->jsonResponse->oneResp('Stock not found for product: ' . $kode_barang, null, 404);
                }

                // Validate exchange
                if ($solution === 'exchange') {
                    $isDropship = $stock['is_dropship'] ?? 0;
                    if ($isDropship == 0 && $stock['stock'] < $jumlah) {
                        return $this->jsonResponse->oneResp('Stock not available for exchange product: ' . $kode_barang, null, 400);
                    }

                    if ($barang_cacat) {
                        $db->table('stock')
                            ->where('id_barang', $kode_barang)
                            ->where('id_toko', $id_toko)
                            ->update([
                                'barang_cacat' => $stock['barang_cacat'] + $jumlah,
                                'stock' => $stock['stock'] - $jumlah,
                            ]);

                        log_aktivitas([
                            'user_id' => $token['user_id'],
                            'action_type' => 'UPDATE',
                            'target_table' => 'stock',
                            'target_id' => $stock['product_id'],
                            'description' => "Transaksi $transactionId Retur. Stok produk {$kode_barang} dikurangi sebanyak {$jumlah} karena barang cacat pada stock toko {$id_toko}.",
                            'detail' => [
                                'stok_sebelum' => (int) $stock['stock'],
                                'barang_cacat_sebelum' => (int) $stock['barang_cacat'],
                                'jumlah_dikurangi' => (int) $jumlah,
                                'stok_setelah' => (int) $stock['stock'] - $jumlah,
                                'barang_cacat_setelah' => (int) $stock['barang_cacat'] + $jumlah,
                            ]
                        ]);
                    }
                }


                // Save retur
                $returData = [
                    'transaction_id' => $transactionId,
                    'kode_barang' => $kode_barang,
                    'barang_cacat' => $barang_cacat,
                    'jumlah' => $jumlah,
                    'solution' => $solution,
                ];

                if (!$db->table('retur')->insert($returData)) {
                    throw new \RuntimeException('Failed to insert retur for product: ' . $kode_barang);
                }

                // Process refund
                if ($solution === 'refund') {
                    // Get sales data
                    $salesProduct = $db->table('sales_product')
                        ->where('id_transaction', $transactionId)
                        ->where('kode_barang', $kode_barang)
                        ->get()
                        ->getRowArray();

                    if (!$salesProduct) {
                        return $this->jsonResponse->oneResp('Product not found in sales: ' . $kode_barang, null, 404);
                    }

                    // Validate refund quantity
                    if ($salesProduct['jumlah'] < $jumlah) {
                        return $this->jsonResponse->oneResp('Refund quantity exceeds purchase quantity for: ' . $kode_barang, null, 400);
                    }

                    // Calculate actual price per piece
                    $actual_per_piece = $salesProduct['harga_jual'] * (1 - ($discount / $transaction['amount']));
                    $newJumlah = $salesProduct['jumlah'] - $jumlah;

                    // Update sales product
                    $updateData = [
                        'jumlah' => $newJumlah,
                        'total' => $salesProduct['harga_jual'] * $newJumlah,
                        'actual_per_piece' => $actual_per_piece,
                        'actual_total' => $actual_per_piece * $newJumlah
                    ];

                    if (
                        !$db->table('sales_product')
                            ->where('id_transaction', $transactionId)
                            ->where('kode_barang', $kode_barang)
                            ->update($updateData)
                    ) {
                        throw new \RuntimeException('Failed to update sales product: ' . $kode_barang);
                    }


                    $refundAmount = $jumlah * $actual_per_piece;
                    $totalRefundAmount += $refundAmount;

                    // Return product to stock
                    $updateField = $barang_cacat ? 'barang_cacat' : 'stock';
                    $updateValue = $stock[$updateField] + $jumlah;

                    if (
                        !$db->table('stock')
                            ->where('id_barang', $kode_barang)
                            ->where('id_toko', $id_toko)
                            ->update([$updateField => $updateValue])
                    ) {
                        throw new \RuntimeException('Failed to update stock for product: ' . $kode_barang);
                    }
                    log_aktivitas([
                        'user_id' => $token['user_id'],
                        'action_type' => 'UPDATE',
                        'target_table' => 'stock',
                        'target_id' => $stock['product_id'],
                        'description' => "Transaksi $transactionId Retur. Produk {$kode_barang} sebanyak {$jumlah} dikembalikan ke `{$updateField}` pada stok toko {$id_toko}.",
                        'detail' => [
                            'tipe_refund' => $barang_cacat ? 'Barang Cacat' : 'Barang Normal',
                            'stok_sebelum' => (int) $stock['stock'],
                            'barang_cacat_sebelum' => (int) $stock['barang_cacat'],
                            'jumlah_dikembalikan' => (int) $jumlah,
                            'stok_setelah' => $barang_cacat ? (int) $stock['stock'] : (int) $stock['stock'] + $jumlah,
                            'barang_cacat_setelah' => $barang_cacat ? (int) $stock['barang_cacat'] + $jumlah : (int) $stock['barang_cacat'],
                        ]
                    ]);
                }
            }

            // Update transaction status
            $updateStatus = $db->table('transaction')
                ->where('id', $transactionId)
                ->update([
                    'status' => $totalRefundAmount > 0 ? 'NEED_REFUNDED' : 'RETUR',
                    'closing' => $transaction['closing'] !== "0" ? 2 : 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'updated_by' => $token['user_id']
                ]);

            if (!$updateStatus) {
                throw new \RuntimeException('Failed to update transaction status');
            }

            // Process refund amount
            if ($totalRefundAmount > 0) {
                // Update transaction amount
                if (
                    !$db->table('transaction')
                        ->where('id', $transactionId)
                        ->set('amount', 'amount - ' . $totalRefundAmount, false)
                        ->update()
                ) {
                    throw new \RuntimeException('Failed to update transaction amount');
                }

                // Insert refund metadata
                $metas = [
                    ['transaction_id' => $transactionId, 'key' => 'refunded_amount', 'value' => $totalRefundAmount],
                    ['transaction_id' => $transactionId, 'key' => 'complaint', 'value' => true],
                ];

                foreach ($metas as $meta) {
                    if (!$db->table('transaction_meta')->insert($meta)) {
                        throw new \RuntimeException('Failed to insert transaction metadata: ' . $meta['key']);
                    }
                }


                // Update grand total
                $grandTotal = $db->table('transaction_meta')
                    ->where('transaction_id', $transactionId)
                    ->where('key', 'grand_total')
                    ->get()
                    ->getRowArray();

                if ($grandTotal) {
                    $newGrandTotal = (float) $grandTotal['value'] - $totalRefundAmount;
                    if (
                        !$db->table('transaction_meta')
                            ->where('id', $grandTotal['id'])
                            ->update(['value' => $newGrandTotal])
                    ) {
                        throw new \RuntimeException('Failed to update grand total');
                    }
                }
            }

            $db->transCommit();
            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'UPDATE',
                'target_table' => 'transactions',
                'target_id' => $transactionId,
                'description' => `Transaksi $transactionId terdapat complain`,
                'detail' => $data
            ]);
            return $this->jsonResponse->oneResp('Complaint processed successfully', null, 200);

        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', 'Error in complainProduct: ' . $e->getMessage());
            return $this->jsonResponse->oneResp('Failed to process complaint: ' . $e->getMessage(), null, 500);
        }
    }
    public function updateTransaction($transactionId)
    {
        $data = $this->request->getJSON();
        $token = $this->request->user;

        $db = \Config\Database::connect();
        $db->transStart();

        try {
            // 1. Validasi transaksi exists
            $transaction = $this->transactions->find($transactionId);
            if (!$transaction) {
                throw new \Exception("Transaksi tidak ditemukan.");
            }

            // 2. Validasi status transaksi
            if (in_array($transaction['status'], ['CANCELLED', 'REFUNDED'])) {
                throw new \Exception("Tidak dapat mengubah transaksi yang sudah dibatalkan/direfund.");
            }

            // 3. Get or create customer
            $customerId = null;

            // 1. Prepare data
            if (isset($data->customer_id) && !empty($data->customer_id)) {
                $customerId = $data->customer_id;
            } else {
                $customerId = $this->getOrCreateCustomer(
                    $data->customer_name,
                    $data->customer_phone,
                    $data->alamat
                );
            }

            // 4. Ambil data lama untuk comparison
            $oldItems = $this->SalesProductModel->where('id_transaction', $transactionId)->findAll();
            $oldItemMap = array_column($oldItems, null, 'kode_barang');

            // Ambil meta data lama untuk comparison
            $oldMeta = $this->getTransactionMetaData($transactionId);

            // 5. Validasi dan prepare produk baru
            $kodeBarangBaru = array_column($data->item, 'kode_barang');
            $products = $this->ProductModel->whereIn('id_barang', $kodeBarangBaru)->findAll();

            if (count($products) !== count($kodeBarangBaru)) {
                throw new \Exception("Beberapa produk tidak ditemukan.");
            }

            $productMap = array_column($products, null, 'id_barang');

            // 6. Calculate new totals dengan free_ongkir
            $freeOngkir = isset($data->free_ongkir) ? (bool) $data->free_ongkir : false;

            // Calculate discount based on type
            $discount_type = $data->discount_type ?? 'fixed';
            $discount_value = $data->discount_value ?? 0;

            [$totalAmount, $ppn_value, $grandTotal, $potongan_ongkir, $discount_nominal] = $this->calculateTransactionTotals(
                $data->item,
                $discount_value,
                $discount_type,
                $data->ppn ?? 0,
                $data->biaya_pengiriman ?? 0,
                $freeOngkir
            );

            $discount_rate = ($totalAmount > 0) ? ($discount_nominal / $totalAmount) : 0;

            // 7. Handle stock adjustment dengan logging
            $stockChanges = $this->handleStockAdjustment(
                $token['user_id'],
                $data->id_toko,
                $oldItemMap,
                $data->item,
                $transactionId
            );

            // 8. Calculate modal totals
            $total_modal = 0;
            $total_actual = 0;
            $salesData = [];

            foreach ($data->item as $item) {
                $product = $productMap[$item->kode_barang];
                $actual_per_piece = $item->harga_jual * (1 - $discount_rate);
                $actual_total = $actual_per_piece * $item->jumlah;

                // Tambahan logika untuk dropship
                $dropship_suplier = null;
                if (isset($product['dropship']) && $product['dropship'] == 1) {
                    $dropship_suplier = $product['suplier'] ?? null; // suplier pasti string
                }

                $salesData[] = [
                    'kode_barang' => $item->kode_barang,
                    'jumlah' => $item->jumlah,
                    'harga_jual' => $item->harga_jual,
                    'total' => $item->harga_jual * $item->jumlah,
                    'modal_system' => $product['harga_modal'],
                    'total_modal' => $product['harga_modal'] * $item->jumlah,
                    'actual_per_piece' => $actual_per_piece,
                    'actual_total' => $actual_total,
                    'id_transaction' => $transactionId,
                    'dropship_suplier' => $dropship_suplier, // kolom baru
                ];

                $total_modal += $product['harga_modal'] * $item->jumlah;
                $total_actual += $actual_total;
            }


            // 9. Update transaction data
            $updateTransaction = [
                'amount' => $grandTotal,
                'actual_total' => $total_actual,
                'total_modal' => $total_modal,
                'po' => $data->po,
                'id_toko' => $data->id_toko,
                "updated_by" => $token['user_id'],
                'date_time' => date('Y-m-d H:i:s'),
                'closing' => $transaction['closing'] !== "0" ? 2 : 0
            ];

            // 10. Handle status changes based on payment
            $updateTransaction = $this->handleStatusUpdate(
                $transaction,
                $updateTransaction,
                $grandTotal
            );

            // 11. Prepare meta data
            $metaData = [
                'ppn' => $data->ppn,
                'ppn_value' => $ppn_value,
                'totalAmount' => $totalAmount,
                'discount' => $discount_nominal,
                'discount_value' => $discount_value,
                'discount_type' => $discount_type,
                'discount_rate' => $discount_rate,
                'jatuh_tempo' => $data->jatuh_tempo,
                'source' => $data->source,
                'alamat' => $data->alamat,
                'pengiriman' => $data->pengiriman,
                'biaya_pengiriman' => $data->biaya_pengiriman,
                'free_ongkir' => $freeOngkir,
                'potongan_ongkir' => $potongan_ongkir,
                'customer_id' => $customerId,
                'customer_name' => $data->customer_name,
                'customer_phone' => $data->customer_phone,
            ];

            // 12. Handle refund amount calculation
            if ($updateTransaction['status'] === 'NEED_REFUNDED') {
                $refundAmount = $transaction['total_payment'] - $grandTotal;
                if ($refundAmount > 0) {
                    $metaData['refunded_amount'] = $refundAmount;
                }
            }

            // 13. Execute updates
            if (!$this->transactions->update($transactionId, $updateTransaction)) {
                throw new \Exception("Gagal memperbarui transaksi.");
            }

            // Delete old sales data and insert new
            $this->SalesProductModel->where('id_transaction', $transactionId)->delete();
            if (!$this->SalesProductModel->insertBatch($salesData)) {
                throw new \Exception("Gagal memperbarui data penjualan.");
            }

            // Update meta data
            $this->saveTransactionMeta($transactionId, $metaData);

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \Exception("Terjadi kesalahan saat memperbarui transaksi.");
            }

            // 14. Log activity
            $this->logTransactionUpdate(
                $token['user_id'],
                $transactionId,
                $transaction,
                $updateTransaction,
                $oldItemMap,
                $data->item,
                $stockChanges,
                $oldMeta,
                $metaData
            );

            return $this->jsonResponse->oneResp('Transaksi berhasil diperbarui', $transactionId, 200);

        } catch (\Exception $e) {
            $db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    private function handleStockAdjustment($user_id, $id_toko, $oldItemMap, $newItems, $transactionId)
    {
        $stockChanges = [];
        $kodeBarangBaru = array_column($newItems, 'kode_barang');

        // Handle removed items - restore stock
        foreach ($oldItemMap as $kode_barang => $oldItem) {
            if (!in_array($kode_barang, $kodeBarangBaru)) {
                $this->restoreStock($user_id, $id_toko, $kode_barang, $oldItem['jumlah'], $transactionId);
                $stockChanges[] = [
                    'action' => 'RESTORE',
                    'kode_barang' => $kode_barang,
                    'jumlah' => $oldItem['jumlah'],
                    'reason' => 'Item dihapus dari transaksi'
                ];
            }
        }

        // Handle updated items - adjust stock
        $itemsToReduce = [];
        foreach ($newItems as $item) {
            $kode_barang = $item->kode_barang;
            $newJumlah = $item->jumlah;
            $oldJumlah = isset($oldItemMap[$kode_barang]) ? $oldItemMap[$kode_barang]['jumlah'] : 0;
            $diffJumlah = $newJumlah - $oldJumlah;

            if ($diffJumlah > 0) {
                // Need more stock - collect for batch processing
                $itemsToReduce[] = (object) [
                    'kode_barang' => $kode_barang,
                    'jumlah' => $diffJumlah
                ];
                // HAPUS logging dari sini, nanti dilakukan di checkAndUpdateStockBatch
            } elseif ($diffJumlah < 0) {
                // Restore excess stock
                $this->restoreStock($user_id, $id_toko, $kode_barang, abs($diffJumlah), $transactionId);
                // HAPUS logging dari sini, sudah dilakukan di restoreStock
            }
        }

        // Process batch stock reduction
        if (!empty($itemsToReduce)) {
            $this->checkAndUpdateStockBatch($user_id, $id_toko, $itemsToReduce, $transactionId);
        }

        return $stockChanges;
    }

    private function handleStatusUpdate($oldTransaction, $updateData, $newGrandTotal)
    {
        $status = $oldTransaction['status'];
        $totalPayment = $oldTransaction['total_payment'];

        if (in_array($status, ['WAITING_PAYMENT', 'PENDING'])) {
            $updateData['status'] = 'WAITING_PAYMENT';
            return $updateData;
        }

        // Only adjust status for paid transactions
        if (in_array($status, ['PAID', 'PARTIALLY_PAID'])) {
            if ($newGrandTotal == $totalPayment) {
                $updateData['status'] = 'PAID';
            } elseif ($newGrandTotal > $totalPayment) {
                $updateData['status'] = 'PARTIALLY_PAID';
            } else {
                $updateData['status'] = 'NEED_REFUNDED';
            }
        }

        return $updateData;
    }

    private function getTransactionMetaData($transactionId)
    {
        $metaRows = $this->transactionMeta
            ->where('transaction_id', $transactionId)
            ->get()
            ->getResultArray();

        $metaData = [];
        foreach ($metaRows as $row) {
            $metaData[$row['key']] = $row['value'];
        }

        return $metaData;
    }


    private function restoreStock($user_id, $id_toko, $kode_barang, $jumlah, $transactionId = null)
    {
        $stock = $this->stockModel
            ->select('stock.*, product.id AS product_id, stock.stock as current_stock')
            ->join('product', 'product.id_barang = stock.id_barang')
            ->where('stock.id_toko', $id_toko)
            ->where('stock.id_barang', $kode_barang)
            ->get()
            ->getRowArray();

        if (!$stock) {
            throw new \Exception("Stok untuk produk {$kode_barang} tidak ditemukan.");
        }

        // Skip dropship products
        if (!empty($stock['dropship']) && (int) $stock['dropship'] === 1) {
            return true;
        }

        $oldStock = (int) $stock['current_stock'];
        $newStock = $oldStock + $jumlah;

        $result = $this->stockModel
            ->where('id_toko', $id_toko)
            ->where('id_barang', $kode_barang)
            ->set('stock', $newStock)
            ->update();

        if ($result) {
            $description = $transactionId
                ? "Mengembalikan stok produk {$kode_barang} sebanyak {$jumlah} untuk update transaksi {$transactionId}"
                : "Mengembalikan stok produk {$kode_barang} sebanyak {$jumlah}";

            // LOG HANYA DI SINI - tidak double
            log_aktivitas([
                'user_id' => $user_id,
                'action_type' => 'UPDATE',
                'target_table' => 'product',
                'target_id' => $stock['product_id'],
                'description' => $description,
                'detail' => [
                    'sebelum' => $oldStock,
                    'ditambahkan' => (int) $jumlah,
                    'sisa' => $newStock,
                    'kode_barang' => $kode_barang,
                    'transaction_id' => $transactionId,
                    'reason' => 'Penyesuaian stok untuk update transaksi'
                ]
            ]);
        }

        return $result;
    }

    private function logTransactionUpdate($user_id, $transactionId, $oldTransaction, $newTransaction, $oldItems, $newItems, $stockChanges, $oldMeta, $newMeta)
    {
        $changes = [];

        // Track transaction changes
        foreach ($newTransaction as $key => $value) {
            if (isset($oldTransaction[$key]) && $oldTransaction[$key] != $value) {
                $changes[$key] = [
                    'from' => $oldTransaction[$key],
                    'to' => $value
                ];
            }
        }

        // Track meta changes
        foreach ($newMeta as $key => $value) {
            if (!isset($oldMeta[$key]) || $oldMeta[$key] != $value) {
                $changes['meta_' . $key] = [
                    'from' => $oldMeta[$key] ?? null,
                    'to' => $value
                ];
            }
        }

        $description = "Memperbarui transaksi {$transactionId}";
        if (!empty($stockChanges)) {
            $description .= " dengan penyesuaian stok";
        }
        if (isset($changes['status'])) {
            $description .= " - Status berubah dari {$changes['status']['from']} ke {$changes['status']['to']}";
        }

        log_aktivitas([
            'user_id' => $user_id,
            'action_type' => 'UPDATE',
            'target_table' => 'transactions',
            'target_id' => $transactionId,
            'description' => $description,
            'detail' => [
                'changes' => $changes,
                'stock_adjustments' => $stockChanges,
                'items_updated' => count($newItems)
            ]
        ]);
    }

    public function getUpcomingDueTransactions()
    {
        $db = \Config\Database::connect();

        $today = date('Y-m-d');
        $futureDate = date('Y-m-d', strtotime('+7 days'));

        $builder = $db->table('transaction_meta tm')
            ->select("
            t.id AS transaction_id,
            t.invoice AS invoice_number,
            t.amount,
            t.po,
            t.total_payment,
            t.status,
            t.id_toko,
            t.date_time,
            toko.toko_name,
            COALESCE(c.nama_customer, tm_name.value) AS customer_name,
            c.no_hp_customer AS customer_phone,
            tm.value AS jatuh_tempo_pada
        ")
            ->join('transaction t', 'tm.transaction_id = t.id', 'left')
            ->join('transaction_meta tm_cust', 't.id = tm_cust.transaction_id AND tm_cust.key = "customer_id"', 'left')
            ->join('customer c', 'tm_cust.value = c.id', 'left')
            ->join('transaction_meta tm_name', 't.id = tm_name.transaction_id AND tm_name.key = "customer_name"', 'left')
            ->join('toko', 't.id_toko = toko.id', 'left')
            ->where('tm.key', 'jatuh_tempo')
            ->where('tm.value >=', $today)
            ->where('tm.value <=', $futureDate)
            ->orderBy('tm.value', 'ASC');

        $result = $builder->get()->getResultArray();

        // Return response
        return $this->jsonResponse->multiResp('Success', $result, count($result), 1, 1, count($result), 200);
    }
    public function updateTransactionStatus($transactionId)
    {
        $token = $this->request->user;
        $db = \Config\Database::connect();
        $data = $this->request->getJSON();

        // Validasi input
        if (!isset($data->status)) {
            return $this->jsonResponse->oneResp('Status wajib diisi.', null, 400);
        }

        $newStatus = strtoupper(trim($data->status)); // Pastikan status dalam huruf besar
        $allowedStatus = ['PACKING', 'IN_DELIVERY', 'SUCCESS'];

        if (!in_array($newStatus, $allowedStatus)) {
            return $this->jsonResponse->oneResp('Status tidak valid.', null, 400);
        }

        // Cek apakah transaksi ada dan statusnya PAID
        $builder = $db->table('transaction');
        $transaction = $builder->where('id', $transactionId)
            ->whereIn('status', ['PAID', 'PACKING', 'IN_DELIVERY', 'RETUR'])
            ->get()->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaksi tidak ditemukan atau status bukan PAID.', null, 404);
        }


        // Update status transaksi
        $builder->where('id', $transactionId)->update([
            'status' => $newStatus,
            'updated_by' => $token['user_id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonResponse->oneResp('Status transaksi berhasil diperbarui.', [
            'transaction_id' => $transactionId,
            'new_status' => $newStatus
        ], 200);
    }
    private function generateTransactionLog(int $userId, array $oldTransaction, array $newTransaction, array $oldItems, array $newItems): string
    {
        $logDescription = "Transaksi diperbarui oleh user {$userId}. ";

        // Bandingkan field transaksi
        foreach ($newTransaction as $key => $newVal) {
            $oldVal = $oldTransaction[$key] ?? null;
            if ($oldVal != $newVal && !in_array($key, ['updated_by', 'date_time'])) {
                $logDescription .= ucfirst($key) . " berubah dari {$oldVal} menjadi {$newVal}. ";
            }
        }

        // Buat map item baru (kode_barang => object)
        $newItemMap = [];
        foreach ($newItems as $item) {
            $newItemMap[$item->kode_barang] = $item;
        }

        // Cek perubahan dan penambahan item
        foreach ($newItemMap as $kode => $item) {
            if (!isset($oldItems[$kode])) {
                $logDescription .= "Item {$kode} ditambahkan dengan jumlah {$item->jumlah} dan harga jual {$item->harga_jual}. ";
            } else {
                $oldItem = $oldItems[$kode];
                if ($oldItem['jumlah'] != $item->jumlah) {
                    $logDescription .= "Item {$kode} jumlah berubah dari {$oldItem['jumlah']} menjadi {$item->jumlah}. ";
                }
                if ($oldItem['harga_jual'] != $item->harga_jual) {
                    $logDescription .= "Item {$kode} harga jual berubah dari {$oldItem['harga_jual']} menjadi {$item->harga_jual}. ";
                }
            }
        }

        // Cek item yang dihapus
        foreach ($oldItems as $kode => $item) {
            if (!isset($newItemMap[$kode])) {
                $logDescription .= "Item {$kode} dihapus dari transaksi. ";
            }
        }

        return trim($logDescription);
    }
}
