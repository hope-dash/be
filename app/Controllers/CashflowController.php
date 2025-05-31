<?php

namespace App\Controllers;

use App\Models\TransactionModel;
use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;

class CashflowController extends ResourceController
{
    protected $modelName = 'App\Models\CashflowModel';
    protected $format = 'json';
    protected $jsonResponse;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
    }


    // Create Cashflow
    public function create()
    {
        $data = $this->request->getJSON(true);

        $data['date_time'] = date('Y-m-d H:i:s');

        $validation = \Config\Services::validation();
        $validation->setRules([
            'amount' => 'required|decimal',
            'noted' => 'required',
            'status' => 'required|in_list[SUCCESS,CANCEL,PENDING]',
            'type' => 'required|in_list[Penjualan,Operational,Gaji,Sewa,Belanja,Transaction]',
            'transaction' => 'required|in_list[credit,debit]',
            'metode' => 'required|in_list[Transfer,Cash]',
            'id_toko' => 'required|integer',
        ]);

        if ($data['transaction'] === "credit") {
            $data['credit'] = $data['amount'];
        } else if ($data['transaction'] === "debit") {
            $data['debit'] = $data['amount'];
        }

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $this->model->insert($data);
        return $this->jsonResponse->oneResp('Cashflow created successfully', ['id' => $this->model->insertID()], 201);
    }

    // Edit Cashflow
    public function edit($id = null)
    {
        $data = $this->request->getVar();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'amount' => 'required|decimal',
            'notes' => 'required',
            'status' => 'required|in_list[success,pending,waiting_payment,failed,canceled,refunded]',
            'type' => 'required|in_list[Penjualan,Operational,Gaji,Sewa,Belanja,Transaction]',
            'transaction' => 'required|in_list[credit,debit]',
            'id_toko' => 'required|integer',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $cashflow = $this->model->find($id);
        if (!$cashflow) {
            return $this->jsonResponse->error('Data not found', 404);
        }

        $this->model->update($id, $data);
        return $this->jsonResponse->oneResp('Cashflow updated successfully', ['id' => $id], 200);
    }

    protected function buildCashflowFilterQuery(array $params, bool $withJoinToko = false)
    {
        $builder = $this->model->builder(); // Pastikan dapat Query Builder

        if ($withJoinToko) {
            $builder->join('toko', 'toko.id = cashflow.id_toko', 'left');
        }

        // Filter transaction
        if (!empty($params['transaction'])) {
            if ($params['transaction'] === 'credit') {
                $builder->where('credit !=', 0);
            } elseif ($params['transaction'] === 'debit') {
                $builder->where('debit !=', 0);
            }
        }

        // Filter role (array of toko id)
        if (!empty($params['role']) && empty($params['id_toko'])) {
            $builder->whereIn('cashflow.id_toko', $params['role']);
        }

        // Filter type
        if (!empty($params['type'])) {
            $types = array_map('trim', explode(',', $params['type']));
            $builder->whereIn('type', $types);
        }

        // Filter status
        if (!empty($params['status'])) {
            $builder->like('status', $params['status'], 'both');
        }

        // Filter id_toko
        if (!empty($params['id_toko'])) {
            $builder->where('cashflow.id_toko', $params['id_toko']);
        }

        // Filter date range
        if (!empty($params['date_start']) && !empty($params['date_end'])) {
            $start_val = $params['date_start'] . ' 00:00:00';
            $end_val = $params['date_end'] . ' 23:59:59';
            $builder->where("cashflow.date_time BETWEEN '{$start_val}' AND '{$end_val}'");
        } elseif (!empty($params['date_start'])) {
            $start_val = $params['date_start'] . ' 00:00:00';
            $builder->where('cashflow.date_time >=', $start_val);
        } elseif (!empty($params['date_end'])) {
            $end_val = $params['date_end'] . ' 23:59:59';
            $builder->where('cashflow.date_time <=', $end_val);
        }

        return $builder;
    }


    public function listCashflow()
    {
        $sortBy = $this->request->getGet('sortBy') ?? 'id';
        $sortMethod = strtolower($this->request->getGet('sortMethod') ?? 'asc');
        $limit = (int) ($this->request->getGet('limit') ?: 10);
        $page = (int) ($this->request->getGet('page') ?: 1);

        $params = [
            'transaction' => $this->request->getGet('transaction') ?? '',
            'type' => $this->request->getGet('type') ?? '',
            'status' => $this->request->getGet('status') ?? '',
            'id_toko' => $this->request->getGet('id_toko') ?? '',
            'date_start' => $this->request->getGet('date_start') ?? '',
            'date_end' => $this->request->getGet('date_end') ?? '',
            'role' => is_string($this->request->getGet('role'))
                ? array_map('intval', explode(',', $this->request->getGet('role')))
                : []
        ];

        $offset = ($page - 1) * $limit;

        // Buat query builder dengan join toko
        $builder = $this->buildCashflowFilterQuery($params, true);

        // Clone builder untuk count dan sum (tidak perlu join toko, bisa dioptimalkan)
        $builderCount = clone $builder;
        $builderSum = clone $builder;

        // Hitung total data (countAllResults(false) tanpa limit dan offset)
        $total_data = $builderCount->countAllResults(false);
        $total_page = ($limit > 0) ? ceil($total_data / $limit) : 1;

        // Ambil data result dengan limit dan offset
        $result = $builder
            ->select('cashflow.*, toko.toko_name')
            ->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        // Hitung sum debit dan credit (tanpa join toko)
        $builderSumNoJoin = $this->buildCashflowFilterQuery($params, false);
        $sum = $builderSumNoJoin
            ->select('SUM(debit) AS debit, SUM(credit) AS credit')
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

    public function calculateDebitAndCredit()
    {
        $params = [
            'date_start' => $this->request->getGet('date_start'),
            'date_end' => $this->request->getGet('date_end'),
            'id_toko' => $this->request->getGet('id_toko'),
            'type' => $this->request->getGet('type'),
            'transaction' => $this->request->getGet('transaction') ?? '',
            'role' => $this->request->getGet('role') ? array_map('intval', explode(',', $this->request->getGet('role'))) : [],
        ];

        try {
            // Ambil builder tanpa join toko karena tidak perlu untuk sum
            $builder = $this->buildCashflowFilterQuery($params, false);

            $result = $builder
                ->select('SUM(debit) AS total_debit, SUM(credit) AS total_credit')
                ->get()
                ->getRow();

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






}
