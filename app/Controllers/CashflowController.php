<?php

namespace App\Controllers;

use App\Models\TransactionModel;
use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;

class CashflowController extends ResourceController
{
    protected $modelName = 'App\Models\TransactionModel';
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
            'status' => 'required|in_list[success,pending,waiting_payment,failed,canceled,refunded]',
            'type' => 'required|in_list[credit,debit]',
            'id_toko' => 'required|integer',
        ]);

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
            'status' => 'required|in_list[success,pending,waiting_payment,failed,canceled,refunded]',
            'type' => 'required|in_list[credit,debit]',
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

        $filters = $this->request->getGet();
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 10;
        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;
        $cashflows = $this->model->getCashflow($filters, $limit, $offset);
        $total_data = $this->model->countCashflow($filters);
        $total_page = ceil($total_data / $limit);

        return $this->jsonResponse->multiResp('Cashflow retrieved successfully', $cashflows, $total_data, $total_page);
    }

}
