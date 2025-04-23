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
use CodeIgniter\HTTP\ResponseInterface;
use DateTime;

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

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->transactions = new TransactionModel();
        $this->transactionMeta = new TransactionMetaModel();
        $this->customer = new CustomerModel();
        $this->SalesProductModel = new SalesProductModel();
        $this->ProductModel = new ProductModel();
        $this->stockModel = new StockModel();
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

    private function checkAndUpdateStock($id_toko, $kode_barang, $jumlah)
    {
        // Ambil data stok dari toko
        $stock = $this->stockModel
            ->where('id_toko', $id_toko)
            ->where('id_barang', $kode_barang)
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

        return $this->stockModel
            ->where('id_toko', $id_toko)
            ->where('id_barang', $kode_barang)
            ->set('stock', 'stock - ' . $jumlah, false)
            ->update();
    }



    private function getOrCreateCustomer($customer_name, $customer_phone)
    {
        if (empty($customer_phone)) {
            return null;
        }

        $customer = $this->customer->where('no_hp_customer', $customer_phone)->first();
        if (!$customer) {
            $this->customer->insert([
                'nama_customer' => $customer_name,
                'no_hp_customer' => $customer_phone
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
            $customerId = $this->getOrCreateCustomer($data->customer_name, $data->customer_phone);

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

                $this->checkAndUpdateStock($data->id_toko, $item->kode_barang, $item->jumlah);


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
        $search = $this->request->getGet('search');

        if ($status) {
            $builder->where('t.status', $status);
        }
        if ($id_toko) {
            $builder->where('t.id_toko', $id_toko);
        }

        if ($date_start && $date_end) {
            $builder->where("t.date_time BETWEEN '{$date_start}' AND '{$date_end}'");
        } elseif ($date_start) {
            $builder->where("t.date_time >= '{$date_start}'");
        } elseif ($date_end) {
            $builder->where("t.date_time <= '{$date_end}'");
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

        try {
            // Start building the query
            $query = $this->db->table('transaction')
                ->select('SUM(transaction.total_payment) AS total_revenue, 
                          SUM(transaction.total_payment) - COALESCE(SUM(sales_product.total_modal), 0) AS total_profit,
                          COALESCE(SUM(tm.value), 0) AS total_refunded')
                ->join('sales_product', 'transaction.id = sales_product.id_transaction', 'left')
                ->join('transaction_meta tm', 'transaction.id = tm.transaction_id AND tm.key = "refunded_amount"', 'left')
                ->whereIn('transaction.status', ['SUCCESS', 'RETUR'])
                ->where('transaction.date_time >=', $date_start)
                ->where('transaction.date_time <=', $date_end);

            // Add store filter if provided
            if ($id_toko) {
                $query->where('transaction.id_toko', $id_toko);
            }

            // Execute the query
            $result = $query->get()->getRow();

            // Check if result is found
            if ($result) {
                // Calculate adjusted revenue and profit
                $adjusted_revenue = $result->total_revenue - $result->total_refunded;
                $adjusted_profit = $result->total_profit - $result->total_refunded;

                return $this->jsonResponse->oneResp("Data berhasil diambil", [
                    'total_revenue' => $adjusted_revenue,
                    'total_profit' => $adjusted_profit
                ], 200);
            } else {
                return $this->jsonResponse->error("Tidak ada data untuk rentang tanggal ini", 404);
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function calculateDebitAndCredit()
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');
        $type = $this->request->getGet('type');
        $transaction = $this->request->getGet('transaction') ?: '';

        try {
            // Query untuk menghitung total debit dan kredit
            $query = $this->db->table('cashflow')
                ->select('SUM(debit) AS total_debit, SUM(credit) AS total_credit')
                ->where('status', 'SUCCESS');

            // Filter berdasarkan tanggal jika diberikan
            if (!empty($date_start) && !empty($date_end)) {
                $query->where('date_time >=', $date_start)
                    ->where('date_time <=', $date_end);
            }

            if ($id_toko) {
                $query->where('id_toko', $id_toko);
            }

            if (!empty($type)) {
                $types = explode(',', $type);
                $query->whereIn('type', array_map('trim', $types));
            }

            if (!empty($transaction)) {
                if ($transaction == "credit") {
                    $query->where('credit !=', 0);
                } else if ($transaction == "debit") {
                    $query->where('debit !=', 0);
                }
            }

            $result = $query->get()->getRow();

            if ($result) {
                return $this->jsonResponse->oneResp("Data berhasil diambil", [
                    'total_debit' => $result->total_debit,
                    'total_credit' => $result->total_credit
                ], 200);
            } else {
                return $this->jsonResponse->error("Tidak ada data untuk kriteria ini", 404);
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }


    public function calculateExpenseAllocation()
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');
        $type = $this->request->getGet('type');

        try {
            $query = $this->db->table('cashflow')
                ->select('type, SUM(credit) AS total_credit')
                ->where('date_time >=', $date_start)
                ->where('date_time <=', $date_end)
                ->where('credit >', 0)
                ->groupBy('type');

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
        try {
            $query = $this->db->table('transaction_meta')
                ->select('customer.id AS customer_id, customer.nama_customer, COUNT(transaction_meta.transaction_id) AS total_transactions')
                ->join('transaction', 'transaction.id = transaction_meta.transaction_id')
                ->join('customer', 'customer.id = transaction_meta.value')
                ->where('transaction.status', 'SUCCESS')
                ->where('transaction_meta.key', 'customer_id')
                ->groupBy('transaction_meta.value')
                ->orderBy('total_transactions', 'DESC')
                ->where('transaction.date_time >=', $date_start)
                ->where('transaction.date_time <=', $date_end)
                ->limit($limit);

            if ($id_toko) {
                $query->where('id_toko', $id_toko);
            }
            $results = $query->get()->getResult();

            if ($results) {
                return $this->jsonResponse->oneResp("Data berhasil diambil", $results, 200);
            } else {
                return $this->jsonResponse->error("Tidak ada", 404);
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function topSoldProducts($limit = 5)
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');

        try {
            $query = $this->db->table('sales_product')
                ->select('sales_product.kode_barang, product.nama_barang, model_barang.nama_model, 
                      COALESCE(seri.seri, "Tidak Ada Seri") AS seri, 
                      SUM(sales_product.jumlah) AS total_sold, 
                      (SELECT COALESCE(SUM(stock.stock), 0) 
                       FROM stock 
                       WHERE stock.id_barang = sales_product.kode_barang) AS total_stock')
                ->join('transaction', 'sales_product.id_transaction = transaction.id')
                ->join('product', 'sales_product.kode_barang = product.id_barang')
                ->join('model_barang', 'product.id_model_barang = model_barang.id')
                ->join('seri', 'product.id_seri_barang = seri.id', 'left')
                ->where('transaction.date_time >=', $date_start)
                ->where('transaction.date_time <=', $date_end)
                ->whereIn('transaction.status', ['SUCCESS', 'PAID', 'RETUR', 'PARTIALY_PAID'])
                ->groupBy(['sales_product.kode_barang', 'product.nama_barang', 'model_barang.nama_model', 'seri.seri'])
                ->orderBy('total_sold', 'DESC')
                ->limit($limit);



            if ($id_toko) {
                $query->where('transaction.id_toko', $id_toko);
            }

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

    public function getFinancialSummary()
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');

        try {
            $query = $this->db->table('transaction')
                ->select("DATE(date_time) AS tanggal, 
                      COUNT(id) AS sales, 
                      SUM(CASE WHEN status IN ('SUCCESS', 'RETUR', 'PAID') THEN total_payment ELSE 0 END) AS revenue")
                ->where('date_time >=', $date_start)
                ->where('date_time <=', $date_end)
                ->groupBy('tanggal')
                ->orderBy('tanggal', 'ASC');


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

        // Start a transaction
        $db->transBegin();

        // Retrieve the transaction
        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->whereIn('status', ['cancel', 'need_refunded'])
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found or not eligible for refund', null, 404);
        }

        // Cek apakah ada refunded_amount di transaction_meta
        $refundedAmount = $db->table('transaction_meta')
            ->where('transaction_id', $transactionId)
            ->where('key', 'refunded_amount')
            ->get()
            ->getRowArray();

        if ($refundedAmount) {
            $refundValue = (float) $refundedAmount['value']; // Gunakan nilai refunded_amount
        } else {
            $refundValue = (float) $transaction['total_payment']; // Jika tidak ada, gunakan total_payment
        }

        // Insert into cashflow
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

        // Update transaction status to refunded
        $db->table('transaction')
            ->where('id', $transactionId)
            ->update(['status' => 'REFUNDED', 'updated_by' => $token['user_id']]);

        // Update transaction_meta for refunded_at and cashflow_id
        $metaData = [
            [
                'transaction_id' => $transactionId,
                'key' => 'refunded_at',
                'value' => date('Y-m-d H:i:s')
            ],
            [
                'transaction_id' => $transactionId,
                'key' => 'cashflow_id',
                'value' => (string) $cashflowId
            ]
        ];

        // Jika refunded_amount belum ada di transaction_meta, tambahkan
        if (!$refundedAmount) {
            $metaData[] = [
                'transaction_id' => $transactionId,
                'key' => 'refunded_amount',
                'value' => (string) $refundValue
            ];
        }

        foreach ($metaData as $data) {
            $db->table('transaction_meta')->insert($data);
        }

        // Commit the transaction
        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->jsonResponse->oneResp('Failed to update transaction', null, 500);
        } else {
            $db->transCommit();
            return $this->jsonResponse->oneResp('Transaction status updated to refunded', null, 200);
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

        // Validasi input
        $validation = \Config\Services::validation();
        $validation->setRules([
            'amount' => 'required',
            'metode_pembayaran' => 'required',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $amount = $data->amount;
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
            'metode' => $data->metode_pembayaran
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
                'value' => (string) $data->metode_pembayaran
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

        $db->table('transaction')
            ->where('id', $transactionId)
            ->update(['status' => 'PAID', 'updated_by' => $token['user_id'], 'total_payment' => $newTotalPayment + $transaction['total_payment']]);

        $cashflowData = [
            'debit' => $newTotalPayment,
            'credit' => 0,
            'noted' => "Pembayaran Lunas Transaksi " . $transaction['invoice'],
            'type' => 'Transaction',
            'status' => 'SUCCESS',
            'date_time' => date('Y-m-d H:i:s'),
            'id_toko' => $transaction['id_toko'],
            'metode' => $data->metode_pembayaran
        ];

        $db->table('cashflow')->insert($cashflowData);
        $cashflowId = $db->insertID();

        $metaData = [
            [
                'transaction_id' => (string) $transactionId,
                'key' => 'paid_at',
                'value' => date('Y-m-d H:i:s')
            ],
            [
                'transaction_id' => (string) $transactionId,
                'key' => 'cashflow_id',
                'value' => (string) $cashflowId
            ],
            [
                'transaction_id' => (string) $transactionId,
                'key' => 'metode_pembayaran_pelunasan',
                'value' => (string) $data->metode_pembayaran
            ]
        ];

        foreach ($metaData as $data) {

            $data['key'] = (string) $data['key'];
            $data['value'] = (string) $data['value'];

            $db->table('transaction_meta')->insert($data);
        }

        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->jsonResponse->oneResp('Failed to update transaction', null, 500);
        } else {
            $db->transCommit();
            return $this->jsonResponse->oneResp('Transaction status updated to fully paid', null, 200);
        }
    }

    public function complainProduct($transactionId)
    {
        $token = $this->request->user;
        $db = \Config\Database::connect();

        $db->transBegin();

        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->whereIn('status', ['SUCCESS'])
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found or not eligible for complaint', null, 404);
        }

        $data = $this->request->getJSON();
        $products = $data->products;

        $totalRefundAmount = 0;

        foreach ($products as $product) {
            $kode_barang = $product->kode_barang;
            $jumlah = $product->jumlah;
            $barang_cacat = $product->barang_cacat;
            $solution = $product->solution;

            // Simpan retur
            $returData = [
                'transaction_id' => $transactionId,
                'kode_barang' => $kode_barang,
                'barang_cacat' => $barang_cacat,
                'jumlah' => $jumlah,
                'solution' => $solution,
            ];
            $db->table('retur')->insert($returData);

            // Ambil data penjualan
            $salesProduct = $db->table('sales_product')
                ->where('id_transaction', $transactionId)
                ->where('kode_barang', $kode_barang)
                ->get()
                ->getRowArray();

            if (!$salesProduct) {
                return $this->jsonResponse->oneResp('Product not found for kode_barang: ' . $kode_barang, null, 404);
            }

            // Ambil stock terkait untuk cek dropship
            $stock = $db->table('stock')
                ->where('id_toko', $transaction['id_toko'])
                ->where('id_barang', $kode_barang)
                ->get()
                ->getRowArray();

            $isDropship = isset($stock['dropship']) && (int) $stock['dropship'] === 1;

            // Hitung refund
            if ($solution === 'refund') {
                $refundAmount = $jumlah * $salesProduct['actual_per_piece'];
                $totalRefundAmount += $refundAmount;
            }

            // Tukar barang: kurangi stok
            if ($solution === 'exchange') {
                $db->table('stock')
                    ->where('id_barang', $kode_barang)
                    ->where('id_toko', $transaction['id_toko'])
                    ->set('stock', 'stock - ' . $jumlah, false)
                    ->update();
            }

            // Jika bukan dropship, proses pengembalian ke stock
            if (!$isDropship) {
                if ($barang_cacat) {
                    $db->table('stock')
                        ->where('id_barang', $kode_barang)
                        ->where('id_toko', $transaction['id_toko'])
                        ->set('barang_cacat', 'barang_cacat + ' . $jumlah, false)
                        ->update();
                } else {
                    $db->table('stock')
                        ->where('id_barang', $kode_barang)
                        ->where('id_toko', $transaction['id_toko'])
                        ->set('stock', 'stock + ' . $jumlah, false)
                        ->update();
                }
            }
        }

        // Status transaksi dan metadata
        $db->table('transaction')
            ->where('id', $transactionId)
            ->update(['status' => 'RETUR', 'updated_by' => $token['user_id']]);

        if ($totalRefundAmount > 0) {
            $metaData = [
                [
                    'transaction_id' => $transactionId,
                    'key' => 'refunded_at',
                    'value' => date('Y-m-d H:i:s')
                ],
                [
                    'transaction_id' => $transactionId,
                    'key' => 'refunded_amount',
                    'value' => $totalRefundAmount // perbaiki jadi total
                ]
            ];

            foreach ($metaData as $data) {
                $db->table('transaction_meta')->insert($data);
            }

            $db->table('transaction')
                ->where('id', $transactionId)
                ->update(['status' => 'NEED_REFUNDED', 'updated_by' => $token['user_id']]);
        }

        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->jsonResponse->oneResp('Failed to process complaint', null, 500);
        } else {
            $db->transCommit();
            return $this->jsonResponse->oneResp('Complaint processed successfully', null, 200);
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

            $customerId = $this->getOrCreateCustomer($data->customer_name, $data->customer_phone);

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
                    $this->restoreStock($data->id_toko, $kode_barang, $oldItem['jumlah']);
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
                    $this->checkAndUpdateStock($data->id_toko, $kode_barang, $diffJumlah);
                } elseif ($diffJumlah < 0) {
                    $this->restoreStock($data->id_toko, $kode_barang, abs($diffJumlah));
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

            // **8. Tambahkan logika status transaksi**
            if ($transaction['status'] === 'PAID') {
                if ($grandTotal > $transaction['amount']) {
                    $updateTransaction['status'] = 'PARTIALLY_PAID';
                } elseif ($grandTotal < $transaction['amount']) {
                    $updateTransaction['status'] = 'NEED_REFUNDED';
                }
            }

            if (!$this->transactions->update($transactionId, $updateTransaction)) {
                throw new \Exception("Gagal memperbarui transaksi.");
            }

            // **9. Simpan meta tambahan jika terjadi refund**
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

            if ($transaction['status'] === 'PAID' && $grandTotal < $transaction['total_payment']) {
                $metaData['refunded_amount'] = $transaction['total_payment'] - $grandTotal;
            }

            $this->saveTransactionMeta($transactionId, $metaData);

            if (!$this->SalesProductModel->insertBatch($salesData)) {
                throw new \Exception("Gagal memperbarui data penjualan.");
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \Exception("Terjadi kesalahan saat memperbarui transaksi.");
            }

            return $this->jsonResponse->oneResp('Transaksi berhasil diperbarui', $transactionId, 200);
        } catch (\Exception $e) {
            $db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }


    private function restoreStock($idToko, $kodeBarang, $jumlah)
    {
        $stok = $this->stockModel
            ->select('stock.id, stock.stock, product.dropship')
            ->join('product', 'product.id_barang = stock.id_barang')
            ->where('stock.id_toko', $idToko)
            ->where('stock.id_barang', $kodeBarang)
            ->first();

        if (!$stok) {
            throw new \Exception("Stok untuk produk {$kodeBarang} tidak ditemukan di toko {$idToko}.");
        }

        $isDropship = isset($stok['dropship']) && (int) $stok['dropship'] === 1;

        if (!$isDropship) {
            $this->stockModel
                ->where('id', $stok['id'])
                ->set('stock', 'stock + ' . (int) $jumlah, false)
                ->update();
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
            ->whereIn('status', ['PAID', 'PACKING', 'IN_DELIVERY'])
            ->get()->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaksi tidak ditemukan atau status bukan PAID.', null, 404);
        }

        // Update status transaksi
        $builder->where('id', $transactionId)->update(['status' => $newStatus, 'updated_by' => $token['user_id'],]);

        return $this->jsonResponse->oneResp('Status transaksi berhasil diperbarui.', [
            'transaction_id' => $transactionId,
            'new_status' => $newStatus
        ], 200);
    }


}
