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

    public function createTransaction()
    {
        $data = $this->request->getJSON();

        // Validasi input
        $validation = \Config\Services::validation();
        $validation->setRules([
            'status' => 'in_list[SUCCESS,WAITING_PAYMENT,FAILED,CANCEL,REFUNDED]',
            'id_toko' => 'required|integer',
            'customer_name' => 'required|string',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        try {
            $db = \Config\Database::connect();
            $db->transStart(); // Mulai transaksi database

            // Check or create customer
            $customerId = null;
            if (!empty($data->customer_phone)) {
                $customer = $this->customer->where('no_hp_customer', $data->customer_phone)->first();
                if (!$customer) {
                    if (
                        !$this->customer->insert([
                            'nama_customer' => $data->customer_name,
                            'no_hp_customer' => $data->customer_phone
                        ])
                    ) {
                        throw new \Exception("Failed to save customer data.");
                    }
                    $customerId = $this->customer->insertID();
                } else {
                    $customerId = $customer['id'];
                }
            }

            // Ambil semua produk yang dibutuhkan dalam satu query
            $kodeBarangList = array_column($data->item, 'kode_barang');
            $products = $this->ProductModel->whereIn('id_barang', $kodeBarangList)->findAll();

            if (empty($products)) {
                throw new \Exception("No products found for the given IDs.");
            }

            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product['id_barang']] = $product;
            }

            // Hitung total transaksi dan persiapkan data penjualan
            $salesData = [];
            $totalAmount = 0;

            foreach ($data->item as $item) {
                if (!isset($productMap[$item->kode_barang])) {
                    throw new \Exception("Product {$item->kode_barang} not found.");
                }

                $product = $productMap[$item->kode_barang];
                $jumlah = $item->jumlah;
                $harga_final_satuan = $item->harga_jual;
                $harga_modal = $product['harga_modal'];
                $harga_jual = $product['harga_jual'];
                $total = $harga_final_satuan * $jumlah;
                $total_modal = $harga_modal * $jumlah;
                $margin = $total - $total_modal;
                $totalAmount += $total;

                // Kurangi stok dengan validasi
                $updateStock = $this->stockModel
                    ->where('id_toko', $data->id_toko)
                    ->where('id_barang', $item->kode_barang)
                    ->set('stock', 'stock - ' . $jumlah, false)
                    ->update();

                if (!$updateStock) {
                    throw new \Exception("Failed to update stock for product {$item->kode_barang}.");
                }

                $salesData[] = [
                    'kode_barang' => $item->kode_barang,
                    'jumlah' => $jumlah,
                    'harga_system' => $harga_jual,
                    'harga_jual' => $item->harga_jual,
                    'total' => $total,
                    'modal_system' => $harga_modal,
                    'total_modal' => $total_modal,
                    'margin' => $margin,
                ];
            }

            if (empty($data->ppn)) {
                $ppn = 0;
            } else {
                $ppn = $data->ppn * $totalAmount / 100;
            }
            // Simpan transaksi
            $transactionData = [
                'amount' => $totalAmount + $ppn,
                'status' => 'WAITING_PAYMENT',
                'id_toko' => $data->id_toko,
                'date_time' => date('Y-m-d H:i:s')
            ];

            if (!$this->transactions->insert($transactionData)) {
                throw new \Exception("Failed to save transaction.");
            }

            $insertID = $this->transactions->insertID();
            if (!$insertID) {
                throw new \Exception("Transaction ID not generated.");
            }

            $invoice = "INV/" . date('y/m/d') . '/' . $insertID;

            // Update nomor invoice di transaction
            if (!$this->transactions->update($insertID, ['invoice' => $invoice])) {
                throw new \Exception("Failed to update invoice number.");
            }

            $metaData = [
                ['transaction_id' => $insertID, 'key' => 'ppn', 'value' => $ppn],
                ['transaction_id' => $insertID, 'key' => 'grand_total', 'value' => $totalAmount],
            ];

            if (empty($customerId)) {
                $metaData[] = [
                    'transaction_id' => $insertID,
                    'key' => 'customer_name',
                    'value' => $data->customer_name
                ];
            } else {
                $metaData[] = [
                    'transaction_id' => $insertID,
                    'key' => 'customer_id',
                    'value' => $customerId
                ];
            }

            if (!$this->transactionMeta->insertBatch($metaData)) {
                throw new \Exception("Failed to save transaction metadata.");
            }

            foreach ($salesData as &$item) {
                $item['id_transaction'] = $insertID;
            }
            unset($item);


            if (!$this->SalesProductModel->insertBatch($salesData)) {
                throw new \Exception("Failed to save sales data.");
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \Exception("An error occurred while saving the transaction.");
            }

            return $this->jsonResponse->oneResp('Transaction successfully processed', $invoice, 201);
        } catch (\Exception $e) {
            $db->transRollback(); // Rollback transaksi jika ada error
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
                'sub_total' => $totalAmount,
                'ppn' => $ppn,
                'grand_total' => $totalAmount + $ppn,

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
                t.total_payment,
                t.status,
                t.id_toko,
                t.date_time,
                toko.toko_name,
                COALESCE(c.nama_customer, tm_name.value) AS customer_name,
                c.no_hp_customer AS customer_phone
            ")
            ->join('transaction_meta tm_cust', 't.id = tm_cust.transaction_id AND tm_cust.key = "customer_id"', 'left')
            ->join('customer c', 'tm_cust.value = c.id', 'left')
            ->join('transaction_meta tm_name', 't.id = tm_name.transaction_id AND tm_name.key = "customer_name"', 'left')
            ->join('toko', 't.id_toko = toko.id', 'left');

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
            $builder->where("t.date_time BETWEEN :date_start: AND :date_end:", [
                'date_start' => $date_start,
                'date_end' => $date_end
            ]);
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
        tm_grand_total.value AS grand_total,
        tm_pelunasan.value AS metode_pembayaran_pelunasan,
        tm_cancel.value AS cancel_at,
        tm_reason.value AS cancel_reason,
        tm_refunded.value AS refunded_at,
        tm_total_dp.value AS total_dp
        
    ")
            ->join('transaction_meta tm_cust', 't.id = tm_cust.transaction_id AND tm_cust.key = "customer_id"', 'left')
            ->join('customer c', 'tm_cust.value = c.id', 'left')
            ->join('transaction_meta tm_name', 't.id = tm_name.transaction_id AND tm_name.key = "customer_name"', 'left')
            ->join('toko', 't.id_toko = toko.id', 'left')
            ->join('transaction_meta tm_partial', 't.id = tm_partial.transaction_id AND tm_partial.key = "partialy_paid_at"', 'left')
            ->join('transaction_meta tm_dp', 't.id = tm_dp.transaction_id AND tm_dp.key = "metode_pembayaran_dp"', 'left')
            ->join('transaction_meta tm_paid', 't.id = tm_paid.transaction_id AND tm_paid.key = "paid_at"', 'left')
            ->join('transaction_meta tm_ppn', 't.id = tm_ppn.transaction_id AND tm_ppn.key = "ppn"', 'left') // Corrected join
            ->join('transaction_meta tm_total_dp', 't.id = tm_total_dp.transaction_id AND tm_total_dp.key = "total_dp"', 'left') // Corrected join
            ->join('transaction_meta tm_grand_total', 't.id = tm_grand_total.transaction_id AND tm_grand_total.key = "grand_total"', 'left') // Corrected join
            ->join('transaction_meta tm_pelunasan', 't.id = tm_pelunasan.transaction_id AND tm_pelunasan.key = "metode_pembayaran_pelunasan"', 'left')
            ->join('transaction_meta tm_cancel', 't.id = tm_cancel.transaction_id AND tm_cancel.key = "cancel_at"', 'left')
            ->join('transaction_meta tm_reason', 't.id = tm_reason.transaction_id AND tm_reason.key = "cancel_reason"', 'left')
            ->join('transaction_meta tm_refunded', 't.id = tm_refunded.transaction_id AND tm_refunded.key = "refunded_at"', 'left')
            ->where('t.id', $id);


        $transaction = $builder->get()->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found', null, 404);
        }

        // Fetch products for the transaction
        $productBuilder = $db->table('sales_product sp')
            ->select("
            sp.kode_barang,
            sp.jumlah,
            sp.harga_jual as harga_satuan,
            sp.total,
            sp.modal_system as harga_modal,
            sp.total_modal,
            sp.margin,
            p.nama_barang
        ")
            ->join('product p', 'sp.kode_barang = p.id_barang', 'left')
            ->where('sp.id_transaction', $id);

        $products = $productBuilder->get()->getResultArray();
        $transaction['products'] = $products;

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
                ->orderBy('date_time', 'ASC')
                ->get()
                ->getResultArray();
            $cashflowRecords = array_merge($cashflowRecords, $cashflowDetails);
        }

        $transaction['cashflow_records'] = $cashflowRecords;


        return $this->jsonResponse->oneResp('Success', $transaction, 200);
    }


    public function calculateRevenueAndProfit()
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');
        try {
            $query = $this->db->table('transaction')
                ->select('SUM(sales_product.total) AS total_revenue, SUM(sales_product.margin) AS total_profit')
                ->join('sales_product', 'transaction.id = sales_product.id_transaction')
                ->where('transaction.status', 'SUCCESS')
                ->where('transaction.date_time >=', $date_start)
                ->where('transaction.date_time <=', $date_end);

            if ($id_toko) {
                $query->where('transaction.id_toko', $id_toko);
            }

            $result = $query->get()->getRow();

            if ($result) {
                return $this->jsonResponse->oneResp("Data berhasil diambil", [
                    'total_revenue' => $result->total_revenue,
                    'total_profit' => $result->total_profit
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
                ->where('status', 'SUCCESS')
                ->where('date_time >=', $date_start)
                ->where('date_time <=', $date_end);

            if ($id_toko) {
                $query->where('id_toko', $id_toko);
            }

            if ($type) {
                $query->where('type', $type);
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
                ->select('sales_product.kode_barang, product.nama_barang, model_barang.nama_model, seri.seri, 
                      SUM(sales_product.jumlah) AS total_sold, 
                      (SELECT COALESCE(SUM(stock.stock), 0) 
                       FROM stock 
                       WHERE stock.id_barang = sales_product.kode_barang) AS total_stock') // Hitung total stok secara terpisah
                ->join('transaction', 'sales_product.id_transaction = transaction.id')
                ->join('product', 'sales_product.kode_barang = product.id_barang')
                ->join('model_barang', 'product.id_model_barang = model_barang.id')
                ->join('seri', 'product.id_seri_barang = seri.id')
                ->where('transaction.date_time >=', $date_start)
                ->where('transaction.date_time <=', $date_end)
                ->where('transaction.status !=', 'CANCEL')
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
                        SUM(CASE WHEN status = 'SUCCESS' THEN total_payment ELSE 0 END) AS revenue")
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
        $db = \Config\Database::connect();

        // Start a transaction
        $db->transBegin();

        // Retrieve the transaction
        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->where('status', 'cancel')
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found or not in cancel status', null, 404);
        }

        // Insert into cashflow
        $cashflowData = [
            'debit' => 0,
            'credit' => $transaction['total_payment'],
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
            ->update(['status' => 'REFUNDED']);

        // Update transaction_meta for refunded_at and oneResp
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
        $db = \Config\Database::connect();

        // Start a transaction
        $db->transBegin();

        // Retrieve the transaction
        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->whereIn('status', ['SUCCESS', 'WAITING_PAYMENT', 'PARTIALLY_PAID'])
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->oneResp('Transaction not found or not eligible for cancellation', null, 404);
        }

        // Update transaction status to CANCEL
        $db->table('transaction')
            ->where('id', $transactionId)
            ->update(['status' => 'CANCEL']);

        // Retrieve associated products
        $products = $db->table('sales_product')
            ->where('id_transaction', $transactionId)
            ->get()
            ->getResultArray();

        // Update stock for each product
        foreach ($products as $product) {
            $db->table('stock')
                ->where('id_barang', $product['kode_barang'])
                ->where('id_toko', $transaction['id_toko'])
                ->set('stock', 'stock + ' . $product['jumlah'], false) // Increment stock
                ->update();
        }

        $data = $this->request->getJSON();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'cancel_reason' => 'required',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $cancelReason = $data->cancel_reason;


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
            return $this->jsonResponse->oneResp('Transaction amount not valid', null, 400);
        }

        $db->table('transaction')
            ->where('id', $transactionId)
            ->update(['status' => 'PARTIALLY_PAID', 'total_payment' => $newTotalPayment]);

        $cashflowData = [
            'debit' => $amount,
            'credit' => 0,
            'noted' => "DP Transaksi " . $transaction['invoice'],
            'type' => 'Transaction',
            'status' => 'SUCCESS',
            'date_time' => date('Y-m-d H:i:s'),
            'id_toko' => $transaction['id_toko']
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
        $db = \Config\Database::connect();
        $db->transBegin();

        $transaction = $db->table('transaction')
            ->where('id', $transactionId)
            ->whereIn('status', ['WAITING_PAYMENT', 'PARTIALLY_PAID'])
            ->get()
            ->getRowArray();

        if (!$transaction) {
            return $this->jsonResponse->error('Transaction not found or not eligible for full payment', 404);
        }

        $data = $this->request->getJSON();
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

        if ((float) $newTotalPayment !== (float) $transaction['amount']) {
            return $this->jsonResponse->oneResp('Transaction amount not valid', null, 400);
        }

        $db->table('transaction')
            ->where('id', $transactionId)
            ->update(['status' => 'SUCCESS', 'total_payment' => $newTotalPayment]);

        $cashflowData = [
            'debit' => $amount,
            'credit' => 0,
            'noted' => "Pembayaran Lunas Transaksi " . $transaction['invoice'],
            'type' => 'Transaction',
            'status' => 'SUCCESS',
            'date_time' => date('Y-m-d H:i:s'),
            'id_toko' => $transaction['id_toko']
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

}
