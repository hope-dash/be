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
        $data = $this->request->getPost();

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
        $data = $this->request->getRawInput();

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
}
