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
            $builder->where("t.date_time BETWEEN '{$date_start}' AND '{$date_end}'");
        } elseif ($date_start) {
            $builder->where("t.date_time >= '{$date_start}'");
        } elseif ($date_end) {
            $builder->where("t.date_time <= '{$date_end}'");
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
            // Start building the query
            $query = $this->db->table('transaction')
                ->select('
                SUM(sales_product.actual_total) AS total_revenue,
                SUM(sales_product.total_modal) AS total_modal,
                SUM(sales_product.actual_total) - SUM(sales_product.total_modal) AS total_profit
            ')
                ->join('sales_product', 'sales_product.id_transaction = transaction.id', 'inner')
                ->whereIn('transaction.status', ['SUCCESS', 'PAID', 'PACKING', 'IN_DELIVERY', 'REFUNDED'])
                ->where('transaction.date_time >=', $date_start)
                ->where('transaction.date_time <=', $date_end);


            if (is_string($role)) {
                $role = array_map('intval', explode(',', $role));
            }

            if (!empty($role) && !$id_toko) {
                $query->whereIn('transaction.id_toko', $role);
            }

            // Add store filter if provided
            if ($id_toko) {
                $query->where('transaction.id_toko', $id_toko);
            }

            // Execute the query
            $result = $query->get()->getRow();

            // Check if result is found
            if ($result) {

                return $this->jsonResponse->oneResp("Data berhasil diambil", $result, 200);
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
        $role = $this->request->getGet('role');

        try {
            // Query untuk menghitung total debit dan kredit
            $query = $this->db->table('cashflow')
                ->select('SUM(debit) AS total_debit, SUM(credit) AS total_credit');

            // Filter berdasarkan tanggal jika diberikan
            if (!empty($date_start) && !empty($date_end)) {
                $query->where('date_time >=', $date_start)
                    ->where('date_time <=', $date_end);
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
        $role = $this->request->getGet('role');

        try {
            $query = $this->db->table('cashflow')
                ->select('type, SUM(credit) AS total_credit')
                ->where('date_time >=', $date_start)
                ->where('date_time <=', $date_end)
                ->where('credit >', 0)
                ->groupBy('type');

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
                ->where('t.status', 'SUCCESS')
                ->where('t.date_time >=', $date_start)
                ->where('t.date_time <=', $date_end)
                ->groupBy('c.id, c.nama_customer')
                ->orderBy('total_transactions', 'DESC')
                ->limit($limit);

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
    public function topSoldProducts($limit = 5)
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $role = $this->request->getGet('role');

        // Ambil ID toko dari header 'role' (misalnya: 0,1,2)
        try {
            $query = $this->db->table('sales_product')
                ->select('sales_product.kode_barang, product.nama_barang, model_barang.nama_model, 
                    COALESCE(seri.seri, "Tidak Ada Seri") AS seri, 
                    SUM(sales_product.jumlah) AS total_sold,  
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
                ->where('transaction.date_time >=', $date_start)
                ->where('transaction.date_time <=', $date_end)
                ->whereIn('transaction.status', ['SUCCESS', 'PAID', 'RETUR', 'PARTIALY_PAID'])
                ->groupBy(['sales_product.kode_barang', 'product.nama_barang', 'model_barang.nama_model', 'seri.seri'])
                ->orderBy('total_sold', 'DESC')
                ->limit($limit);


            if (is_string($role)) {
                $role = array_map('intval', explode(',', $role));
            }

            if (!empty($role)) {
                $query->whereIn('transaction.id_toko', $role);
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
        $role = $this->request->getGet('role');

        try {
            $query = $this->db->table('transaction')
                ->select("DATE(date_time) AS tanggal, 
                      COUNT(id) AS sales, 
                      SUM(CASE WHEN status IN ('SUCCESS', 'RETUR', 'PAID') THEN total_payment ELSE 0 END) AS revenue")
                ->where('date_time >=', $date_start)
                ->where('date_time <=', $date_end)
                ->groupBy('tanggal')
                ->orderBy('tanggal', 'ASC');


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
        $newTotalPembayaran = $newTotalPayment + $transaction['total_payment'];
        $ongkirMeta = $db->table('transaction_meta')
            ->select('value')
            ->where('transaction_id', $transactionId)
            ->where('key', 'biaya_pengiriman')
            ->get()
            ->getRowArray();

        $ongkir = isset($ongkirMeta['value']) ? (float) $ongkirMeta['value'] : 0;
        $netIncome = $newTotalPayment - $ongkir;

        // Update status transaksi
        $db->table('transaction')
            ->where('id', $transactionId)
            ->update([
                'status' => 'PAID',
                'updated_by' => $token['user_id'],
                'total_payment' => $newTotalPembayaran
            ]);

        $dateTime = date('Y-m-d H:i:s');

        // Pemasukan dari produk
        $db->table('cashflow')->insert([
            'debit' => $netIncome,
            'credit' => 0,
            'noted' => "Pembayaran Produk Transaksi " . $transaction['invoice'],
            'type' => 'Transaction',
            'status' => 'SUCCESS',
            'date_time' => $dateTime,
            'id_toko' => $transaction['id_toko'],
            'metode' => $data->metode_pembayaran,
            'transaction_id' => $transactionId
        ]);

        // Pengeluaran ongkos kirim (jika ada)
        if ($ongkir > 0) {
            $db->table('cashflow')->insert([
                'debit' => 0,
                'credit' => $ongkir,
                'noted' => "Pengeluaran Ongkir Transaksi " . $transaction['invoice'],
                'type' => 'Ongkir',
                'status' => 'SUCCESS',
                'date_time' => $dateTime,
                'id_toko' => $transaction['id_toko'],
                'metode' => $data->metode_pembayaran,
                'transaction_id' => $transactionId
            ]);
        }

        // Metadata pelunasan
        $metaData = [
            ['key' => 'paid_at', 'value' => $dateTime],
            ['key' => 'metode_pembayaran_pelunasan', 'value' => (string) $data->metode_pembayaran]
        ];

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
                $stock = $db->table('stock')
                    ->where('id_barang', $kode_barang)
                    ->where('id_toko', $id_toko)
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
                $metaData = [
                    'transaction_id' => $transactionId,
                    'key' => 'refunded_amount',
                    'value' => $totalRefundAmount
                ];

                if (!$db->table('transaction_meta')->insert($metaData)) {
                    throw new \RuntimeException('Failed to insert refund metadata');
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

    public function listSalesProductWithTransaction()
    {
        $sortBy = $this->request->getGet('sortBy') ?? 'sp.id';
        $sortMethod = strtolower($this->request->getGet('sortMethod') ?? 'asc');
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $page = (int) $this->request->getGet('page') ?: 1;
        $offset = ($page - 1) * $limit;

        $id_toko = $this->request->getGet('id_toko');
        $start_date = $this->request->getGet('date_start');
        $end_date = $this->request->getGet('date_end');
        $role = $this->request->getGet('role');
        $search = $this->request->getGet('search');

        if (is_string($role)) {
            $role = array_map('intval', explode(',', $role));
        }

        // Builder utama untuk list dan sum
        $baseBuilder = $this->db->table('sales_product sp')
            ->join('transaction t', 'sp.id_transaction = t.id', 'left')
            ->join('product p', 'sp.kode_barang = p.id_barang', 'left')
            ->join('model_barang mb', 'p.id_model_barang = mb.id', 'left')
            ->join('seri s', 'p.id_seri_barang = s.id', 'left');

        // Filter toko
        if (!empty($role) && !$id_toko) {
            $baseBuilder->whereIn('t.id_toko', $role);
        }

        if (!empty($id_toko)) {
            $baseBuilder->like('t.id_toko', (string) $id_toko, 'both');
        }

        // Filter tanggal
        if (!empty($start_date)) {
            $baseBuilder->where('t.date_time >=', $start_date);
        }

        if (!empty($end_date)) {
            $baseBuilder->where('t.date_time <=', $end_date);
        }

        // Filter search
        if ($search) {
            $baseBuilder->groupStart()
                ->like('t.invoice', $search)
                ->orLike('p.nama_barang', $search)
                ->orLike('mb.nama_model', $search)
                ->orLike('s.seri', $search)
                ->groupEnd();
        }

        // Clone base builder untuk hitung total & sum
        $builder = clone $baseBuilder;
        $sumBuilder = clone $baseBuilder;

        // Count total rows
        $total_data = $builder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        // Ambil paginated result
        $result = $builder->select("
            sp.*,
            t.invoice,
            t.date_time,
            t.status,
            t.id_toko,
            p.nama_barang,
            mb.nama_model,
            s.seri,
            CONCAT(
                COALESCE(p.nama_barang, ''), ' ',
                COALESCE(mb.nama_model, ''), ' ',
                COALESCE(s.seri, '')
            ) AS nama_lengkap_barang
        ")
            ->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        // Ambil SUM
        $sum = $sumBuilder->select('
            SUM(sp.modal_system) AS total_modal_system,
            SUM(sp.actual_total) AS total_actual_total
        ')
            ->get()
            ->getRow();

        return $this->jsonResponse->multiResp(
            '',
            ['sum' => $sum, 'result' => $result],
            $total_data,
            $total_page,
            $page,
            $limit,
            200
        );
    }

}
