<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\CustomerModel;
use App\Models\JsonResponse;
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

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->transactions = new TransactionModel();
        $this->transactionMeta = new TransactionMetaModel();
        $this->customer = new CustomerModel();
        $this->SalesProductModel = new SalesProductModel();
        $this->ProductModel = new ProductModel();
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
            $db->transStart(); // Start database transaction

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

            // Retrieve all product codes in one query
            $kodeBarangList = array_column($data->item, 'kode_barang');
            $products = $this->ProductModel->whereIn('id_barang', $kodeBarangList)->findAll();
            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product['id_barang']] = $product;
            }

            // Calculate total transaction based on items
            $salesData = [];
            $totalAmount = 0;

            foreach ($data->item as $item) {
                if (!isset($productMap[$item->kode_barang])) {
                    throw new \Exception("Product {$item->kode_barang} not found.");
                }

                $product = $productMap[$item->kode_barang];
                $jumlah = $item->jumlah;
                $harga_modal = $product['harga_modal'];
                $harga_jual = $product['harga_jual'];
                $total = $harga_jual * $jumlah;
                $total_modal = $harga_modal * $jumlah;
                $margin = $total - $total_modal;
                $totalAmount += $total;

                $salesData[] = [
                    'kode_barang' => $item->kode_barang,
                    'jumlah' => $jumlah,
                    'harga_system' => $harga_modal,
                    'harga_jual' => $harga_jual,
                    'total' => $total,
                    'modal_system' => $harga_modal,
                    'total_modal' => $total_modal,
                    'margin' => $margin
                ];
            }

            // Save transaction
            $transactionData = [
                'debit' => $totalAmount,
                'notes' => "Penjualan",
                'status' => $data->status ?? 'WAITING_PAYMENT',
                'type' => "Penjualan",
                'id_toko' => $data->id_toko,
                'date_time' => date('Y-m-d H:i:s')
            ];

            if (!$this->transactions->insert($transactionData)) {
                throw new \Exception("Failed to save transaction.");
            }

            $insertID = $this->transactions->insertID();
            $invoice = "INV/" . date('y/m/d') . '/' . $insertID;

            // Save transaction metadata
            $metaData = [
                ['transaction_id' => $insertID, 'key' => 'invoice', 'value' => $invoice]
            ];
            if (empty($customerId)) {
                $metaData[] = ['transaction_id' => $insertID, 'key' => 'customer_name', 'value' => $data->customer_name];
            } else {
                $metaData[] = ['transaction_id' => $insertID, 'key' => 'customer_id', 'value' => $customerId];
            }

            if (!$this->transactionMeta->insertBatch($metaData)) {
                throw new \Exception("Failed to save transaction metadata.");
            }

            // Add id_transaction to each item in salesData
            foreach ($salesData as &$item) {
                $item['id_transaction'] = $insertID;
            }
            unset($item); // Clear reference

            // Save sales product in batch
            if (!$this->SalesProductModel->insertBatch($salesData)) {
                throw new \Exception("Failed to save sales data.");
            }

            $db->transComplete(); // Commit transaction if no errors

            if ($db->transStatus() === false) {
                throw new \Exception("An error occurred while saving the transaction.");
            }

            return $this->jsonResponse->oneResp('Transaction successfully processed', $invoice, 201);
        } catch (\Exception $e) {
            $db->transRollback(); // Rollback transaction if there is an error
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    public function getListTransaction()
    {
        $db = \Config\Database::connect();
        $builder = $db->table('transaction_meta tm')
            ->select("
                tm.transaction_id,
                tm.value AS invoice_number,
                t.amount,
                t.notes,
                t.status,
                t.type,
                t.id_toko,
                t.date_time,
                COALESCE(c.nama_customer, tm_name.value) AS customer_name,
                c.no_hp_customer AS customer_phone
            ")
            ->join('transaction t', 'tm.transaction_id = t.id')
            ->join('transaction_meta tm_cust', 'tm.transaction_id = tm_cust.transaction_id AND tm_cust.key = "customer_id"', 'left')
            ->join('customer c', 'tm_cust.value = c.id', 'left')
            ->join('transaction_meta tm_name', 'tm.transaction_id = tm_name.transaction_id AND tm_name.key = "customer_name"', 'left')
            ->where('tm.key', 'invoice');

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
            $builder->where("t.date_time BETWEEN '$date_start' AND '$date_end'");
        }

        // **SEARCH (customer_name, customer_phone, invoice_number)**
        if ($search) {
            $builder->groupStart()
                ->like('c.nama_customer', $search)
                ->orLike('c.no_hp_customer', $search)
                ->orLike('tm_name.value', $search)
                ->orLike('tm.value', $search)
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
            '',
            array_values($result),
            $total_data,
            $total_page,
            $page,
            $limit,
            200
        );
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
        try {
            // Query untuk menghitung total debit dan kredit
            $query = $this->db->table('transaction')
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
            $query = $this->db->table('transaction')
                ->select('type, SUM(credit) AS total_credit')
                ->where('date_time >=', $date_start)
                ->where('date_time <=', $date_end)
                ->where('credit >', 0)
                ->groupBy('type');


            if ($id_toko) {
                $query->where('id_toko', $id_toko);
            }
            $results = $query->get()->getResult();

            $totalCredit = array_sum(array_column($results, 'total_credit'));

            $allocation = [];
            foreach ($results as $row) {
                $percentage = ($totalCredit > 0) ? ($row->total_credit / $totalCredit) * 100 : 0;
                $allocation[$row->type] = round($percentage, 2);
            }

            return $this->jsonResponse->oneResp("Data berhasil diambil", $allocation, 200);

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

    public function topSoldProducts($limit = 10)
    {
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $id_toko = $this->request->getGet('id_toko');

        try {
            // Query untuk menghitung jumlah penjualan per barang
            $query = $this->db->table('sales_product')
                ->select('kode_barang, SUM(jumlah) AS total_sold')
                ->join('transaction', 'sales_product.id_transaction = transaction.id')
                ->where('transaction.date_time >=', $date_start)
                ->where('transaction.date_time <=', $date_end)
                ->groupBy('kode_barang')
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
            // Query untuk menghitung revenue, profit, dan pengeluaran berdasarkan tanggal
            $query = $this->db->table('transaction')
                ->select('DATE(transaction.date_time) AS tanggal, 
                      SUM(sales_product.total) AS revenue, 
                      SUM(sales_product.margin) AS profit, 
                      SUM(transaction.credit) AS pengeluaran')
                ->join('sales_product', 'transaction.id = sales_product.id_transaction')
                ->where('transaction.status', 'SUCCESS')
                ->where('transaction.date_time >=', $date_start) // Filter berdasarkan rentang tanggal
                ->where('transaction.date_time <=', $date_end)
                ->groupBy('tanggal') // Grouping berdasarkan tanggal
                ->orderBy('tanggal', 'ASC'); // Urutkan berdasarkan tanggal

            if ($id_toko) {
                $query->where('transaction.id_toko', $id_toko);
            }
            $results = $query->get()->getResult();


            return $this->jsonResponse->oneResp("Data berhasil diambil", $results, 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }



}
