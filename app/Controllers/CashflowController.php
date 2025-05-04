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

    public function listCashflow()
    {
        $sortBy = $this->request->getGet('sortBy') ?? 'id';
        $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $page = (int) $this->request->getGet('page') ?: 1;

        $status = $this->request->getGet('status') ?: '';
        $type = $this->request->getGet('type') ?: '';
        $transaction = $this->request->getGet('transaction') ?: '';
        $id_toko = $this->request->getGet('id_toko') ?: '';
        $start_date = $this->request->getGet('date_start') ?: ''; // Tambah start_date
        $end_date = $this->request->getGet('date_end') ?: ''; // Tambah end_date
        $role = $this->request->getGet('role');

        $offset = ($page - 1) * $limit;
        $builder = $this->model;
        $builder = $builder->join('toko', 'toko.id = cashflow.id_toko', 'left')
            ->select('cashflow.*, toko.toko_name')
            ->orderBy('cashflow.date_time', 'DESC');

        if (!empty($transaction)) {
            if ($transaction == "credit") {
                $builder = $builder->where('credit !=', 0);
            } else if ($transaction == "debit") {
                $builder = $builder->where('debit !=', 0);
            }
        }

        if (is_string($role)) {
            $role = array_map('intval', explode(',', $role));
        }

        if (!empty($role) && !$id_toko) {
            $builder->whereIn('id_toko', $role);
        }

        if (!empty($type)) {
            $types = explode(',', $type);
            $builder = $builder->whereIn('type', array_map('trim', $types));
        }
        if (!empty($status)) {
            $builder = $builder->like('status', (string) $status, 'both');
        }

        if (!empty($id_toko)) {
            $builder = $builder->like('id_toko', (string) $id_toko, 'both');
        }

        if (!empty($start_date) && !empty($end_date)) {
            $builder = $builder->where('date_time >=', $start_date)
                ->where('date_time <=', $end_date);
        } elseif (!empty($start_date)) {
            $builder = $builder->where('date_time >=', $start_date);
        } elseif (!empty($end_date)) {
            $builder = $builder->where('date_time <=', $end_date);
        }

        // Perbaiki `countAllResults(false)`
        $total_data = $builder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        $result = $builder->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);

    }

}
