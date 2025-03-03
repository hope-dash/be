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

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->transactions = new TransactionModel();
        $this->transactionMeta = new TransactionMetaModel();
        $this->customer = new CustomerModel();
        $this->SalesProductModel = new SalesProductModel();
        $this->ProductModel = new ProductModel();
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

            // Cek atau buat customer
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
                        throw new \Exception("Gagal menyimpan data customer.");
                    }
                    $customerId = $this->customer->insertID();
                } else {
                    $customerId = $customer['id'];
                }
            }

            // Ambil semua kode_barang dalam satu query
            $kodeBarangList = array_column($data->item, 'kode_barang');
            $products = $this->ProductModel->whereIn('id_barang', $kodeBarangList)->findAll();
            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product['id_barang']] = $product;
            }

            // Hitung total transaksi berdasarkan item
            $salesData = [];
            $totalAmount = 0;

            foreach ($data->item as $item) {
                if (!isset($productMap[$item->kode_barang])) {
                    throw new \Exception("Produk {$item->kode_barang} tidak ditemukan.");
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

            // Simpan transaksi
            $transactionData = [
                'amount' => $totalAmount,
                'notes' => "Penjualan",
                'status' => $data->status ?? 'WAITING_PAYMENT',
                'type' => "debit",
                'id_toko' => $data->id_toko,
                'date_time' => date('Y-m-d H:i:s')
            ];

            if (!$this->transactions->insert($transactionData)) {
                throw new \Exception("Gagal menyimpan transaksi.");
            }

            $insertID = $this->transactions->insertID();
            $invoice = "INV/" . date('y/m/d') . '/' . $insertID;

            // Simpan metadata transaksi
            $metaData = [
                ['transaction_id' => $insertID, 'key' => 'invoice', 'value' => $invoice]
            ];
            if (empty($customerId)) {
                $metaData[] = ['transaction_id' => $insertID, 'key' => 'customer_name', 'value' => $data->customer_name];
            } else {
                $metaData[] = ['transaction_id' => $insertID, 'key' => 'customer_id', 'value' => $customerId];
            }

            if (!$this->transactionMeta->insertBatch($metaData)) {
                throw new \Exception("Gagal menyimpan metadata transaksi.");
            }

            // Tambahkan id_transaction ke setiap item salesData
            foreach ($salesData as &$item) {
                $item['id_transaction'] = $insertID;
            }
            unset($item); // Hapus referensi array

            // Simpan sales product dalam batch
            if (!$this->SalesProductModel->insertBatch($salesData)) {
                throw new \Exception("Gagal menyimpan data penjualan.");
            }

            $db->transComplete(); // Commit transaksi jika tidak ada error

            if ($db->transStatus() === false) {
                throw new \Exception("Terjadi kesalahan saat menyimpan transaksi.");
            }

            return $this->jsonResponse->oneResp('Transaksi berhasil diproses', $invoice, 201);
        } catch (\Exception $e) {
            $db->transRollback(); // Rollback transaksi jika ada error
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



}
