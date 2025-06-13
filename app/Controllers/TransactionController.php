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
    private function checkAndUpdateStock($user_id, $id_toko, $kode_barang, $jumlah)
    {
        // Ambil data stok dari toko
        $stock = $this->stockModel
            ->select('stock.*, product.id AS product_id')
            ->join('product', 'product.id_barang = stock.id_barang')
            ->where('stock.id_toko', $id_toko)
            ->where('stock.id_barang', $kode_barang)
            ->get()
            ->getRowArray();


        if (!$stock) {
            throw new \Exception("Stok untuk produk {$kode_barang} tidak ditemukan.");
        }

        if (!empty($stock['dropship']) && (int) $stock['dropship'] === 1) {
            return true;
        }

        if ((int) $stock['stock'] < $jumlah) {
            throw new \Exception("Stok tidak mencukupi untuk produk {$kode_barang}.");
        }

        $result = $this->stockModel
            ->where('id_toko', $id_toko)
            ->where('id_barang', $kode_barang)
            ->set('stock', 'stock - ' . $jumlah, false)
            ->update();

        if ($result) {
            log_aktivitas([
                'user_id' => $user_id,
                'action_type' => 'UPDATE',
                'target_table' => 'product',
                'target_id' => $stock['product_id'],
                'description' => "Mengurangi stok produk {$kode_barang} pada toko {$id_toko} sebanyak {$jumlah}",
                'detail' => [
                    'sebelum' => (int) $stock['stock'],
                    'dikurangi' => (int) $jumlah,
                    'sisa' => (int) $stock['stock'] - $jumlah,
                ]
            ]);
        }

        return $result;
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
    private function calculateTransactionTotals($items, $discount, $ppn, $pengiriman)
    {
        $totalAmount = 0;
        foreach ($items as $item) {
            $totalAmount += $item->jumlah * $item->harga_jual;
        }

        $totalPpn = ($totalAmount * $ppn) / 100;
        $grandTotal = $totalAmount + $totalPpn + $pengiriman - $discount;

        return [$totalAmount, $totalPpn, $grandTotal];
    }
    private function saveTransactionMeta($transactionId, $data)
    {
        $metaData = [
            'ppn' => $data['ppn'],
            'ppn_value' => $data['ppn_value'],
            'grand_total' => $data['totalAmount'],
            'discount' => $data['discount'],
            'alamat' => $data['alamat'],
            'pengiriman' => $data['pengiriman'],
        ];

        if (!empty($data['jatuh_tempo'])) {
            $metaData['jatuh_tempo'] = $data['jatuh_tempo'];
        }

        if (!empty($data['biaya_pengiriman'])) {
            $metaData['biaya_pengiriman'] = $data['biaya_pengiriman'];
        }

        if (!empty($data['source'])) {
            $metaData['source'] = $data['source'];
        }

        if (!empty($data['refunded_amount'])) {
            $metaData['refunded_amount'] = $data['refunded_amount'];
        }

        if (!empty($data['complaint'])) {
            $metaData['complaint'] = $data['complaint'];
        }

        if (empty($data['customerId'])) {
            $metaData['customer_name'] = $data['customer_name'];
        } else {
            $metaData['customer_id'] = $data['customerId'];
        }

        foreach ($metaData as $key => $value) {
            // Cek apakah data meta sudah ada
            $existingMeta = $this->transactionMeta
                ->where('transaction_id', $transactionId)
                ->where('key', $key)
                ->first();


            if ($existingMeta) {
                // Jika sudah ada, update data
                $this->transactionMeta->update($existingMeta['id'], ['value' => $value]);
            } else {
                // Jika belum ada, insert data baru
                $this->transactionMeta->insert([
                    'transaction_id' => $transactionId,
                    'key' => $key,
                    'value' => $value
                ]);
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
            $customerId = $this->getOrCreateCustomer($data->customer_name, $data->customer_phone, $data->alamat);

            $kodeBarangList = array_column($data->item, 'kode_barang');
            $products = $this->ProductModel->whereIn('id_barang', $kodeBarangList)->findAll();

            if (empty($products)) {
                throw new \Exception("Tidak ada produk yang ditemukan.");
            }

            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product['id_barang']] = $product;
            }

            // Menghitung Total, PPN, dan Grand Total
            [$totalAmount, $ppn_value, $grandTotal] = $this->calculateTransactionTotals($data->item, $data->discount, $data->ppn, $data->biaya_pengiriman);

            // Hitung Discount Rate
            $discount_rate = ($totalAmount > 0) ? ($data->discount / $totalAmount) : 0;

            $salesData = [];
            foreach ($data->item as $item) {
                if (!isset($productMap[$item->kode_barang])) {
                    throw new \Exception("Produk {$item->kode_barang} tidak ditemukan.");
                }

                $product = $productMap[$item->kode_barang];

                $this->checkAndUpdateStock($token['user_id'], $data->id_toko, $item->kode_barang, $item->jumlah);


                // Harga modal setelah diskon
                $actual_per_piece = $item->harga_jual * (1 - $discount_rate);
                $total_actual = $actual_per_piece * $item->jumlah;

                $salesData[] = [
                    'kode_barang' => $item->kode_barang,
                    'jumlah' => $item->jumlah,
                    'harga_jual' => $item->harga_jual,
                    'total' => $item->harga_jual * $item->jumlah,
                    'modal_system' => $product['harga_modal'],
                    'total_modal' => $product['harga_modal'] * $item->jumlah,
                    'actual_per_piece' => $actual_per_piece,
                    'actual_total' => $total_actual
                ];
            }

            $transactionData = [
                'amount' => $grandTotal,
                'status' => 'WAITING_PAYMENT',
                'po' => $data->po,
                'id_toko' => $data->id_toko,
                "created_by" => $token['user_id'],
                'date_time' => date('Y-m-d H:i:s')
            ];

            if (!$this->transactions->insert($transactionData)) {
                throw new \Exception("Gagal menyimpan transaksi.");
            }

            $insertID = $this->transactions->insertID();
            $invoice = "INV/" . date('y/m/d') . '/' . $insertID;

            if (!$this->transactions->update($insertID, ['invoice' => $invoice])) {
                throw new \Exception("Gagal memperbarui nomor invoice.");
            }

            $this->saveTransactionMeta($insertID, [
                'ppn' => $data->ppn,
                'ppn_value' => $ppn_value,
                'totalAmount' => $totalAmount,
                'discount' => $data->discount,
                'discount_rate' => $discount_rate, // Simpan discount rate untuk referensi
                'source' => $data->source,
                'customerId' => $customerId,
                'jatuh_tempo' => $data->jatuh_tempo,
                'alamat' => $data->alamat,
                'pengiriman' => $data->pengiriman,
                'biaya_pengiriman' => $data->biaya_pengiriman,
                'customer_name' => $data->customer_name
            ]);



            foreach ($salesData as &$item) {
                $item['id_transaction'] = $insertID;
            }

            if (!$this->SalesProductModel->insertBatch($salesData)) {
                throw new \Exception("Gagal menyimpan data penjualan.");
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \Exception("Terjadi kesalahan saat menyimpan transaksi.");
            }

            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'CREATE',
                'target_table' => 'transactions',
                'target_id' => $insertID,
                'description' => 'Membuat transaksi baru',
                'detail' => [
                    'new' => $data
                ],
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
            if (empty($data->ppn)) {
                $ppn = 0;
            } else {
                $ppn = $data->ppn * $totalAmount / 100;
            }

            // Simpan transaksi
            $transactionData = [
                'discount' => $data->discount,
                'biaya_pengiriman' => $data->biaya_pengiriman,
                'sub_total' => $totalAmount,
                'ppn' => $ppn,
                'grand_total' => $totalAmount + $ppn + $data->biaya_pengiriman - $data->discount,

            ];



            return $this->jsonResponse->oneResp('Transaction successfully processed', $transactionData, 201);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
    public function getListTransaction()
    {
        $db = \Config\Database::connect();
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
                toko.toko_name,
                COALESCE(c.nama_customer, tm_name.value) AS customer_name,
                c.no_hp_customer AS customer_phone,
                tm_jatuh_tempo.value AS jatuh_tempo_pada,
                CASE 
                    WHEN tm_jatuh_tempo.value IS NOT NULL AND tm_jatuh_tempo.value <= CURDATE() THEN TRUE
                    ELSE FALSE
                END AS jatuh_tempo
            ")
            ->join('transaction_meta tm_cust', 't.id = tm_cust.transaction_id AND tm_cust.key = "customer_id"', 'left')
            ->join('customer c', 'tm_cust.value = c.id', 'left')
            ->join('transaction_meta tm_name', 't.id = tm_name.transaction_id AND tm_name.key = "customer_name"', 'left')
            ->join('transaction_meta tm_jatuh_tempo', 't.id = tm_jatuh_tempo.transaction_id AND tm_jatuh_tempo.key = "jatuh_tempo"', 'left')
            ->join('toko', 't.id_toko = toko.id', 'left')
            ->orderBy('t.date_time', 'DESC');

        // **FILTERS**
        $status = $this->request->getGet('status');
        $id_toko = $this->request->getGet('id_toko');
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $role = $this->request->getGet('role');
        $search = $this->request->getGet('search');
        $total_min = $this->request->getGet('total_min');
        $total_max = $this->request->getGet('total_max');

        if ($status) {
            $builder->where('t.status', $status);
        }

        if (is_string($role)) {
            $role = array_map('intval', explode(',', $role));
        }

        if (!empty($role) && !$id_toko) {
            $builder->whereIn('t.id_toko', $role);
        }

        if ($id_toko) {
            $builder->where('t.id_toko', $id_toko);
        }


        if ($date_start && $date_end) {
            $start_val = $date_start . ' 00:00:00';
            $end_val = $date_end . ' 23:59:59';
            $builder->where("t.date_time BETWEEN '{$start_val}' AND '{$end_val}'");

        } elseif ($date_start) {
            $start_val = $date_start . ' 00:00:00';
            $builder->where("t.date_time >= '{$start_val}'");

        } elseif ($date_end) {
            $end_val = $date_end . ' 23:59:59';
            $builder->where("t.date_time <= '{$end_val}'");
        }


        if (
            isset($total_min) && $total_min !== '' && is_numeric($total_min) &&
            isset($total_max) && $total_max !== '' && is_numeric($total_max)
        ) {
            $builder->where('t.total_payment >=', (float) $total_min);
            $builder->where('t.total_payment <=', (float) $total_max);
        } elseif (isset($total_min) && $total_min !== '' && is_numeric($total_min)) {
            $builder->where('t.total_payment >=', (float) $total_min);
        } elseif (isset($total_max) && $total_max !== '' && is_numeric($total_max)) {
            $builder->where('t.total_payment <=', (float) $total_max);
        }


        // **SEARCH (customer_name, customer_phone, invoice_number)**
        if ($search) {
            $builder->groupStart()
                ->like('c.nama_customer', $search)
                ->orLike('c.no_hp_customer', $search)
                ->orLike('tm_name.value', $search)
                ->orLike('t.invoice', $search)
                ->groupEnd();
        }

        // **SORTING & PAGINATION**
        $sortBy = $this->request->getGet('sortBy') ?: 't.id';
        $sortMethod = strtolower($this->request->getGet('sortMethod') ?: 'asc');
        $limit = max((int) ($this->request->getGet('limit') ?: 10), 1);
        $page = max((int) ($this->request->getGet('page') ?: 1), 1);
        $offset = ($page - 1) * $limit;

        $builder->orderBy($sortBy, $sortMethod);
        $total_data = $builder->countAllResults(false); // Hitung total data
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
    public function getTransactionDetailById($id = null)
    {
        $db = \Config\Database::connect();
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
                toko.toko_name,
                toko.image_logo,
                toko.alamat as alamat_toko,
                toko.phone_number as nomer_toko,
                COALESCE(c.nama_customer, tm_name.value) AS customer_name,
                c.no_hp_customer AS customer_phone,
                tm_partial.value AS partially_paid_at,
                tm_dp.value AS metode_pembayaran_dp,
                tm_paid.value AS paid_at,
                tm_ppn.value AS ppn,
                tm_ppn_value.value AS ppn_value,
                tm_grand_total.value AS grand_total,
                tm_pelunasan.value AS metode_pembayaran_pelunasan,
                tm_cancel.value AS cancel_at,
                tm_reason.value AS cancel_reason,
                tm_refunded.value AS refunded_at,
                tm_refunded_amount.value AS refunded_amount,
                tm_total_dp.value AS total_dp,
                tm_source.value AS source,
                tm_discount.value AS discount,
                tm_jatuh_tempo.value AS jatuh_tempo,
                tm_alamat.value AS alamat,
                tm_pengiriman.value AS pengiriman,
                tm_biaya_pengiriman.value AS biaya_pengiriman,
            ")
            ->join('transaction_meta tm_cust', 't.id = tm_cust.transaction_id AND tm_cust.key = "customer_id"', 'left')
            ->join('customer c', 'tm_cust.value = c.id', 'left')
            ->join('transaction_meta tm_name', 't.id = tm_name.transaction_id AND tm_name.key = "customer_name"', 'left')
            ->join('toko', 't.id_toko = toko.id', 'left')
            ->join('transaction_meta tm_partial', 't.id = tm_partial.transaction_id AND tm_partial.key = "partialy_paid_at"', 'left')
            ->join('transaction_meta tm_dp', 't.id = tm_dp.transaction_id AND tm_dp.key = "metode_pembayaran_dp"', 'left')
            ->join('transaction_meta tm_paid', 't.id = tm_paid.transaction_id AND tm_paid.key = "paid_at"', 'left')
            ->join('transaction_meta tm_ppn', 't.id = tm_ppn.transaction_id AND tm_ppn.key = "ppn"', 'left')
            ->join('transaction_meta tm_ppn_value', 't.id = tm_ppn_value.transaction_id AND tm_ppn_value.key = "ppn_value"', 'left')
            ->join('transaction_meta tm_total_dp', 't.id = tm_total_dp.transaction_id AND tm_total_dp.key = "total_dp"', 'left')
            ->join('transaction_meta tm_grand_total', 't.id = tm_grand_total.transaction_id AND tm_grand_total.key = "grand_total"', 'left')
            ->join('transaction_meta tm_pelunasan', 't.id = tm_pelunasan.transaction_id AND tm_pelunasan.key = "metode_pembayaran_pelunasan"', 'left')
            ->join('transaction_meta tm_cancel', 't.id = tm_cancel.transaction_id AND tm_cancel.key = "cancel_at"', 'left')
            ->join('transaction_meta tm_reason', 't.id = tm_reason.transaction_id AND tm_reason.key = "cancel_reason"', 'left')
            ->join('transaction_meta tm_refunded', 't.id = tm_refunded.transaction_id AND tm_refunded.key = "refunded_at"', 'left')
            ->join('transaction_meta tm_refunded_amount', 't.id = tm_refunded_amount.transaction_id AND tm_refunded_amount.key = "refunded_amount"', 'left')
            ->join('transaction_meta tm_source', 't.id = tm_source.transaction_id AND tm_source.key = "source"', 'left')
            ->join('transaction_meta tm_discount', 't.id = tm_discount.transaction_id AND tm_discount.key = "discount"', 'left')
            ->join('transaction_meta tm_jatuh_tempo', 't.id = tm_jatuh_tempo.transaction_id AND tm_jatuh_tempo.key = "jatuh_tempo"', 'left')
            ->join('transaction_meta tm_alamat', 't.id = tm_alamat.transaction_id AND tm_alamat.key = "alamat"', 'left')
            ->join('transaction_meta tm_pengiriman', 't.id = tm_pengiriman.transaction_id AND tm_pengiriman.key = "pengiriman"', 'left')
            ->join('transaction_meta tm_biaya_pengiriman', 't.id = tm_biaya_pengiriman.transaction_id AND tm_biaya_pengiriman.key = "biaya_pengiriman"', 'left')
            ->where('t.id', $id);


        $transaction = $builder->get()->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found', null, 404);
        }

        if (!empty($transaction['jatuh_tempo'])) {
            $jatuhTempo = new \DateTime($transaction['jatuh_tempo']);
            $today = new \DateTime();
            $interval = $today->diff($jatuhTempo);
            $transaction['jatuh_tempo_pada'] = ($interval->invert == 0) ? $interval->days + 1 : 0;
        }

        // Fetch products for the transaction
        $productBuilder = $db->table('sales_product sp')
            ->join('product p', 'sp.kode_barang = p.id_barang', 'left')
            ->join('model_barang', 'model_barang.id = p.id_model_barang', 'left')
            ->join('seri', 'seri.id = p.id_seri_barang', 'left')
            ->select("
                sp.id,
                sp.kode_barang,
                sp.jumlah,
                sp.harga_jual,
                sp.total,
                sp.modal_system as harga_modal,
                sp.total_modal,
                CONCAT(p.nama_barang, ' ', model_barang.nama_model, ' ', COALESCE(seri.seri, '')) as nama_lengkap_barang,
            ")
            ->where('sp.id_transaction', $id);


        $products = $productBuilder->get()->getResultArray();
        $transaction['item'] = $products;

        $cashflowBuilder = $db->table('transaction_meta tm')
            ->select('tm.value AS cashflow_id')
            ->where('tm.transaction_id', $id)
            ->where('tm.key', 'cashflow_id');

        $cashflowMeta = $cashflowBuilder->get()->getResultArray();
        $cashflowRecords = [];

        foreach ($cashflowMeta as $meta) {
            $cashflowId = $meta['cashflow_id'];

            $cashflowDetails = $db->table('cashflow')
                ->select('*')
                ->where('id', $cashflowId)
                ->orderBy('date_time', 'DESC')
                ->get()
                ->getResultArray();
            $cashflowRecords = array_merge($cashflowRecords, $cashflowDetails);
        }

        $transaction['cashflow_records'] = $cashflowRecords;

        $retur = $db->table('retur rt')
            ->where('rt.transaction_id', $id)
            ->get()
            ->getResultArray();
        $transaction['retur'] = $retur;



        return $this->jsonResponse->oneResp('Success', $transaction, 200);
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
                'total_revenue' => floatval($mainResult->total_revenue ?? 0),
                'total_modal' => floatval($mainResult->total_modal ?? 0),
                'total_profit' => floatval($mainResult->total_profit ?? 0),
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

    // Subquery untuk mengambil tanggal pembayaran (bisa digabungkan untuk efisiensi)
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
        
    // ====================================================================
    // PERBAIKAN UTAMA: Logika WHERE untuk Status Transaksi
    // ====================================================================
    $builder->groupStart(); // Membuka grup utama untuk kondisi WHERE

        // 1. Kondisi untuk status selain REFUNDED
        $builder->whereIn('t.status', ['SUCCESS', 'PAID', 'PACKING', 'IN_DELIVERY', 'PARTIALLY_PAID', 'RETUR']);

        // 2. Kondisi KHUSUS untuk status REFUNDED (menggunakan OR)
        $builder->orGroupStart(); // Membuka grup untuk kondisi OR
            $builder->where('t.status', 'REFUNDED');
            // Hanya jika paid_at ATAU dp_at tidak null
            $builder->groupStart(); // Membuka grup untuk kondisi tanggal bayar
                $builder->where('tm_paid.value IS NOT NULL');
                $builder->orWhere('tm_partial.value IS NOT NULL');
            $builder->groupEnd(); // Menutup grup kondisi tanggal bayar
        $builder->groupEnd(); // Menutup grup kondisi OR

    $builder->groupEnd(); // Menutup grup utama

    // Filter lainnya (tetap sama)
    if (!empty($role)) {
        $builder->whereIn('t.id_toko', $role);
    }
    if (!empty($id_toko)) {
        $builder->like('t.id_toko', (string) $id_toko, 'both');
    }

    // Filter tanggal (Saran: Gunakan query binding untuk keamanan)
    if ($start_val && $end_val) {
        $builder->where("(
            DATE(tm_paid.value) BETWEEN '{$start_val}' AND '{$end_val}' OR 
            DATE(tm_partial.value) BETWEEN '{$start_val}' AND '{$end_val}' OR 
            DATE(tm_refunded.refunded_at) BETWEEN '{$start_val}' AND '{$end_val}'
        )");
    }
    // ... (kondisi start_val atau end_val saja)

    if ($search) {
        $builder->groupStart()
            ->like('t.invoice', $search)
            ->orLike('p.nama_barang', $search)
            // ... (filter pencarian lainnya)
            ->groupEnd();
    }

    // ====================================================================
    // PERBAIKAN PADA SELECT: Logika Negasi yang Lebih Sederhana
    // ====================================================================
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
        -- Cukup cek status, karena filter tanggal sudah di handle di WHERE
        IF(t.status = 'REFUNDED', -sp.actual_total, sp.actual_total) AS actual_total,
        IF(t.status = 'REFUNDED', -sp.total_modal, sp.total_modal) AS total_modal
    ";

    return $builder->select($select, FALSE)
        ->orderBy($sortBy, $sortMethod);
}

    public function getRevenueProfitData($start_val, $end_val, $id_toko = null, $role = null)
    {
        // Subquery untuk mengambil paid_at & partialy_paid_at terbaru per transaksi
        $subPaid = "(SELECT transaction_id, MAX(value) as value FROM transaction_meta WHERE `key` = 'paid_at' GROUP BY transaction_id)";
        $subPartial = "(SELECT transaction_id, MAX(value) as value FROM transaction_meta WHERE `key` = 'partialy_paid_at' GROUP BY transaction_id)";

        // === 1. Revenue ===
        $revenueQuery = $this->db->table('sales_product sp')
            ->select('SUM(sp.actual_total) AS total_revenue,SUM(sp.total_modal) AS total_modal')
            ->join('transaction t', 't.id = sp.id_transaction')
            ->join("{$subPaid} tm_paid", 'tm_paid.transaction_id = t.id', 'left')
            ->join("{$subPartial} tm_partial", 'tm_partial.transaction_id = t.id', 'left')
            ->whereIn('t.status', ['SUCCESS', 'PAID', 'PACKING', 'IN_DELIVERY', 'PARTIALLY_PAID', 'RETUR']);

        if ($start_val && $end_val) {
            $revenueQuery->where("DATE(tm_paid.value) BETWEEN '{$start_val}' AND '{$end_val}'", null, false);
            $revenueQuery->orWhere("DATE(tm_partial.value) BETWEEN '{$start_val}' AND '{$end_val}'", null, false);
        } elseif ($start_val) {
            $revenueQuery->where("DATE(tm_paid.value) >= '{$start_val}'", null, false);
            $revenueQuery->orWhere("DATE(tm_partial.value) >= '{$start_val}'", null, false);
        } elseif ($end_val) {
            $revenueQuery->where("DATE(tm_paid.value) <= '{$end_val}'", null, false);
            $revenueQuery->orWhere("DATE(tm_partial.value) <= '{$end_val}'", null, false);
        }

        if (!empty($role)) {
            $revenueQuery->whereIn('t.id_toko', $role);
        }

        if (!empty($id_toko)) {
            $revenueQuery->like('t.id_toko', (string) $id_toko, 'both');
        }

        $revenueResult = $revenueQuery->get()->getRow();
        $total_revenue = $revenueResult->total_revenue ?? 0;
        $total_modal = $revenueResult->total_modal ?? 0;

        // === 2. Beban ===
        $bebanQuery = $this->db->query(
            "
                 SELECT SUM(
                    CASE
                        -- STATUS PAID
                        WHEN t.status = 'PAID' THEN
                        CASE
                            -- Jika tm_paid dan tm_partial tanggal sama → beban = 0
                            WHEN DATE(tm_paid.value) = DATE(tm_partial.value) 
                                AND DATE(tm_paid.value) BETWEEN '{$start_val}' AND '{$end_val}' THEN 0

                            -- Jika paid_at dalam range → ambil DP
                            WHEN DATE(tm_paid.value) BETWEEN '{$start_val}' AND '{$end_val}' THEN COALESCE(tm_dp.value, 0)

                            -- Jika partialy_paid_at dalam range → grand_total - discount - DP
                            WHEN DATE(tm_partial.value) BETWEEN '{$start_val}' AND '{$end_val}' THEN
                                COALESCE(CAST(tm_grand.value AS DECIMAL(20,2)), 0) 
                            - COALESCE(CAST(tm_disc.value AS DECIMAL(20,2)), 0)
                            - COALESCE(tm_dp.value, 0)

                            ELSE 0
                        END

                        -- STATUS PARTIALLY_PAID
                        WHEN t.status = 'PARTIALLY_PAID' THEN
                        CASE
                            -- Jika partialy_paid_at dalam range → grand_total - discount - DP
                            WHEN DATE(tm_partial.value) BETWEEN '{$start_val}' AND '{$end_val}' THEN
                                COALESCE(CAST(tm_grand.value AS DECIMAL(20,2)), 0) 
                            - COALESCE(CAST(tm_disc.value AS DECIMAL(20,2)), 0)
                            - COALESCE(tm_dp.value, 0)

                            -- Jika tidak ada partialy_paid_at, tapi paid_at dalam range → grand_total - discount - total_payment
                            WHEN (tm_partial.value IS NULL OR DATE(tm_partial.value) NOT BETWEEN '{$start_val}' AND '{$end_val}')
                                AND DATE(tm_paid.value) BETWEEN '{$start_val}' AND '{$end_val}' THEN
                                COALESCE(CAST(tm_grand.value AS DECIMAL(20,2)), 0)
                            - COALESCE(CAST(tm_disc.value AS DECIMAL(20,2)), 0)
                            - COALESCE(t.total_payment, 0)

                            ELSE 0
                        END

                        ELSE 0
                    END
                    ) AS total_beban
                    FROM transaction t
                    LEFT JOIN ({$subPaid}) tm_paid ON tm_paid.transaction_id = t.id
                    LEFT JOIN ({$subPartial}) tm_partial ON tm_partial.transaction_id = t.id
                    LEFT JOIN transaction_meta tm_grand 
                    ON tm_grand.transaction_id = t.id AND tm_grand.key = 'grand_total'
                    LEFT JOIN transaction_meta tm_dp 
                    ON tm_dp.transaction_id = t.id AND tm_dp.key = 'total_dp'
                    LEFT JOIN transaction_meta tm_disc 
                    ON tm_disc.transaction_id = t.id AND tm_disc.key = 'discount'
                    WHERE t.status IN ('PAID', 'PARTIALLY_PAID')
                    AND (
                        (DATE(tm_paid.value) BETWEEN '{$start_val}' AND '{$end_val}')
                        OR (DATE(tm_partial.value) BETWEEN '{$start_val}' AND '{$end_val}')
                    );

            "
        );
        $bebanResult = $bebanQuery->getRow();
        $total_beban = $bebanResult->total_beban ?? 0;

        // === 4. Final Result ===
        $revenue = $total_revenue - $total_beban;

        return (object) [
            'total_revenue' => ceil($revenue),
            'total_beban' => ceil($total_beban),
            'total_modal' => ceil($total_modal),
            'total_profit' => ceil($revenue - $total_modal)
        ];
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
            ->whereIn('status', ['cancel', 'need_refunded'])
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
                'total_payment' => $transaction['total_payment'] - $refundValue
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

        // Retrieve the transaction
        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->whereIn('status', ['SUCCESS', 'PAID', 'WAITING_PAYMENT', 'PARTIALLY_PAID'])
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found or not eligible for cancellation', null, 404);
        }

        // Update transaction status to CANCEL
        $db->table('transaction')
            ->where('id', $transactionId)
            ->update(['status' => 'CANCEL', 'updated_by' => $token['user_id']]);

        // Retrieve associated products
        $products = $db->table('sales_product')
            ->where('id_transaction', $transactionId)
            ->get()
            ->getResultArray();
        $data = $this->request->getJSON();
        $validation = \Config\Services::validation();
        $validation->setRules([
            'cancel_reason' => 'required',
            'barang_cacat' => 'required',
        ]);
        $cancelReason = $data->cancel_reason;
        $barangCacat = $data->barang_cacat;

        if ($barangCacat === "true") {
            foreach ($products as $product) {
                $db->table('stock')
                    ->where('id_barang', $product['kode_barang'])
                    ->where('id_toko', $transaction['id_toko'])
                    ->set('barang_cacat', 'barang_cacat + ' . $product['jumlah'], false) // Increment stock
                    ->update();
            }
        } else {
            foreach ($products as $product) {
                $db->table('stock')
                    ->where('id_barang', $product['kode_barang'])
                    ->where('id_toko', $transaction['id_toko'])
                    ->set('stock', 'stock + ' . $product['jumlah'], false) // Increment stock
                    ->update();
            }
        }

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

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

        // Commit the transaction
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
                'description' => "Update transaksi $transactionId menjadi cancel",
            ]);
            return $this->jsonResponse->oneResp('Transaction status updated to cancel', null, 200);
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

        if ((float) $amount > (float) (90 * $transaction['amount'] / 100)) {
            return $this->jsonResponse->oneResp('Jumlah Pembayaran tidak valid', null, 400);
        }

        $db->table('transaction')
            ->where('id', $transactionId)
            ->update(['status' => 'PARTIALLY_PAID', 'updated_by' => $token['user_id'], 'total_payment' => $newTotalPayment]);

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
        $db->transBegin();

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
                'total_payment' => $newTotalPembayaran
            ]);

        $dateTime = date('Y-m-d H:i:s');
        // Metadata pelunasan
        $metaData = [
            ['key' => 'paid_at', 'value' => $dateTime],
            ['key' => 'metode_pembayaran_pelunasan', 'value' => (string) $data->metode_pembayaran]
        ];
        // Insert pemasukan produk
        $cashflowIdProduk = $this->CashflowModel->insert([
            'debit' => $newTotalPayment,
            'credit' => 0,
            'noted' => "Pembayaran Produk Transaksi " . $transaction['invoice'],
            'type' => 'Transaction',
            'status' => 'SUCCESS',
            'date_time' => $dateTime,
            'id_toko' => $transaction['id_toko'],
            'metode' => $data->metode_pembayaran,
            'transaction_id' => $transactionId
        ]);

        if ($cashflowIdProduk) {
            $metaData[] = ['key' => 'cashflow_id', 'value' => $cashflowIdProduk];
        }

        // Insert pengeluaran ongkir (jika ada)
        if ($ongkir > 0) {
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
                $metaData[] = ['key' => 'cashflow_id', 'value' => $cashflowIdOngkir];
            }
        }


        foreach ($metaData as $meta) {
            $db->table('transaction_meta')->insert([
                'transaction_id' => (string) $transactionId,
                'key' => $meta['key'],
                'value' => $meta['value']
            ]);
        }

        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->jsonResponse->oneResp('Gagal memperbarui transaksi', null, 500);
        }

        $db->transCommit();
        log_aktivitas([
            'user_id' => $token['user_id'],
            'action_type' => 'UPDATE',
            'target_table' => 'transactions',
            'target_id' => $transactionId,
            'description' => "Update transaksi {$transactionId} menjadi Full Payment menggunakan metode {$data->metode_pembayaran}",
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
            $transaction = $this->transactions->find($transactionId);
            if (!$transaction) {
                throw new \Exception("Transaksi tidak ditemukan.");
            }

            $customerId = $this->getOrCreateCustomer($data->customer_name, $data->customer_phone, $data->alamat);

            // **1. Ambil Data Item Lama**
            $oldItems = $this->SalesProductModel->where('id_transaction', $transactionId)->findAll();
            $oldItemMap = [];
            foreach ($oldItems as $oldItem) {
                $oldItemMap[$oldItem['kode_barang']] = $oldItem;
            }

            // **2. Temukan item yang dihapus (tidak ada di `$data->item`)**
            $kodeBarangBaru = array_column($data->item, 'kode_barang');
            foreach ($oldItemMap as $kode_barang => $oldItem) {
                if (!in_array($kode_barang, $kodeBarangBaru)) {
                    $this->restoreStock($token['user_id'], $data->id_toko, $kode_barang, $oldItem['jumlah']);
                }
            }

            // **3. Ambil Produk yang Ada di Item Baru**
            $kodeBarangList = array_column($data->item, 'kode_barang');
            $products = $this->ProductModel->whereIn('id_barang', $kodeBarangList)->findAll();

            if (empty($products)) {
                throw new \Exception("Tidak ada produk yang ditemukan.");
            }

            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product['id_barang']] = $product;
            }

            // **4. Menghitung Total, PPN, dan Grand Total**
            [$totalAmount, $ppn_value, $grandTotal] = $this->calculateTransactionTotals($data->item, $data->discount, $data->ppn, $data->biaya_pengiriman);

            // **5. Hitung Discount Rate**
            $discount_rate = ($totalAmount > 0) ? ($data->discount / $totalAmount) : 0;

            // **6. Periksa dan Perbarui Stok**
            foreach ($data->item as $item) {
                $kode_barang = $item->kode_barang;
                $newJumlah = $item->jumlah;
                $oldJumlah = isset($oldItemMap[$kode_barang]) ? $oldItemMap[$kode_barang]['jumlah'] : 0;
                $diffJumlah = $newJumlah - $oldJumlah;

                if ($diffJumlah > 0) {
                    $this->checkAndUpdateStock($token['user_id'], $data->id_toko, $kode_barang, $diffJumlah);
                } elseif ($diffJumlah < 0) {
                    $this->restoreStock($token['user_id'], $data->id_toko, $kode_barang, abs($diffJumlah));
                }
            }

            // **7. Hapus Semua Item Lama dalam Transaksi Ini**
            $this->SalesProductModel->where('id_transaction', $transactionId)->delete();

            $salesData = [];
            foreach ($data->item as $item) {
                $product = $productMap[$item->kode_barang];

                // **Hitung Harga Modal Setelah Diskon**
                $actual_per_piece = $item->harga_jual * (1 - $discount_rate);
                $total_actual = $actual_per_piece * $item->jumlah;

                $salesData[] = [
                    'kode_barang' => $item->kode_barang,
                    'jumlah' => $item->jumlah,
                    'harga_jual' => $item->harga_jual,
                    'total' => $item->harga_jual * $item->jumlah,
                    'modal_system' => $product['harga_modal'],
                    'total_modal' => $product['harga_modal'] * $item->jumlah,
                    'actual_per_piece' => $actual_per_piece, // Harga modal setelah diskon
                    'actual_total' => $total_actual, // Total harga modal setelah diskon
                    'id_transaction' => $transactionId
                ];
            }

            $updateTransaction = [
                'amount' => $grandTotal,
                'po' => $data->po,
                'id_toko' => $data->id_toko,
                "updated_by" => $token['user_id'],
                'date_time' => date('Y-m-d H:i:s'),
            ];

            $metaData = [
                'ppn' => $data->ppn,
                'ppn_value' => $ppn_value,
                'totalAmount' => $totalAmount,
                'discount' => $data->discount,
                'discount_rate' => $discount_rate, // Simpan discount rate untuk referensi
                'jatuh_tempo' => $data->jatuh_tempo,
                'source' => $data->source,
                'alamat' => $data->alamat,
                'pengiriman' => $data->pengiriman,
                'biaya_pengiriman' => $data->biaya_pengiriman,
                'customerId' => $customerId,
                'customer_name' => $data->customer_name
            ];

            // **8. Tambahkan logika status transaksi**
            if ($transaction['status'] === 'PAID') {
                if ($grandTotal > $transaction['amount']) {
                    $updateTransaction['status'] = 'PARTIALLY_PAID';
                } elseif ($grandTotal < $transaction['amount']) {
                    $updateTransaction['status'] = 'NEED_REFUNDED';
                    $metaData['refunded_amount'] = $transaction['total_payment'] - $grandTotal;
                    $metaData['complaint'] = true;
                }
            }

            if (!$this->transactions->update($transactionId, $updateTransaction)) {
                throw new \Exception("Gagal memperbarui transaksi.");
            }

            $this->saveTransactionMeta($transactionId, $metaData);

            if (!$this->SalesProductModel->insertBatch($salesData)) {
                throw new \Exception("Gagal memperbarui data penjualan.");
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \Exception("Terjadi kesalahan saat memperbarui transaksi.");
            }
            $logDescription = $this->generateTransactionLog(
                $token['user_id'],
                $transaction,
                $updateTransaction,
                $oldItemMap,
                $data->item
            );

            log_aktivitas([
                'user_id' => $token['user_id'],
                'action_type' => 'UPDATE',
                'target_table' => 'transactions',
                'target_id' => $transactionId,
                'description' => $logDescription,
            ]);

            return $this->jsonResponse->oneResp('Transaksi berhasil diperbarui', $transactionId, 200);
        } catch (\Exception $e) {
            $db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
    private function restoreStock($user_id, $idToko, $kodeBarang, $jumlah)
    {
        $stok = $this->stockModel
            ->select('stock.id, stock.stock, product.dropship, product.id as product_id')
            ->join('product', 'product.id_barang = stock.id_barang')
            ->where('stock.id_toko', $idToko)
            ->where('stock.id_barang', $kodeBarang)
            ->first();

        if (!$stok) {
            throw new \Exception("Stok untuk produk {$kodeBarang} tidak ditemukan di toko {$idToko}.");
        }

        $isDropship = isset($stok['dropship']) && (int) $stok['dropship'] === 1;

        $result = false;
        if (!$isDropship) {
            $result = $this->stockModel
                ->where('id', $stok['id'])
                ->set('stock', 'stock + ' . (int) $jumlah, false)
                ->update();
        }

        if ($result) {
            log_aktivitas([
                'user_id' => $user_id,
                'action_type' => 'UPDATE',
                'target_table' => 'product',
                'target_id' => $stok['product_id'],
                'description' => "Mengembalikan stok produk {$kodeBarang} pada toko {$idToko} sebanyak {$jumlah}.",
                'detail' => [
                    'stok_sebelum' => (int) $stok['stock'],
                    'jumlah_ditambah' => (int) $jumlah,
                    'stok_setelah' => (int) $stok['stock'] + $jumlah,
                ]
            ]);
        }
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

        // Jika status baru adalah IN_DELIVERY, lakukan pengecekan biaya pengiriman dan insert ke cashflow jika perlu
        if ($newStatus === 'IN_DELIVERY') {
            $biayaPengirimanMeta = $db->table('transaction_meta')
                ->where('transaction_id', $transactionId)
                ->where('key', 'biaya_pengiriman')
                ->get()
                ->getRowArray();

            if ($biayaPengirimanMeta && is_numeric($biayaPengirimanMeta['value']) && (float) $biayaPengirimanMeta['value'] > 0) {
                // Pastikan metode pembayaran ada di input, jika tidak bisa set default atau handle error
                $metodePembayaran = isset($data->metode_pembayaran) ? $data->metode_pembayaran : 'unknown';

                $db->table('cashflow')->insert([
                    'debit' => 0,
                    'credit' => (float) $biayaPengirimanMeta['value'],
                    'noted' => "Biaya Pengiriman Transaksi " . $transaction['invoice'],
                    'type' => 'Transaction',
                    'status' => 'SUCCESS',
                    'date_time' => date('Y-m-d H:i:s'),
                    'id_toko' => $transaction['id_toko'],
                    'metode' => $metodePembayaran
                ]);
            }
        }

        // Update status transaksi
        $builder->where('id', $transactionId)->update(['status' => $newStatus, 'updated_by' => $token['user_id'],]);

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
