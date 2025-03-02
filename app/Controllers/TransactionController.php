<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\CustomerModel;
use App\Models\JsonResponse;
use App\Models\TransactionMetaModel;
use App\Models\TransactionModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTime;

class TransactionController extends BaseController
{
    protected $transactions;
    protected $jsonResponse;
    protected $transactionMeta;
    protected $customer;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->transactions = new TransactionModel();
        $this->transactionMeta = new TransactionMetaModel();
        $this->customer = new CustomerModel();
    }
    public function createTransaction()
    {
        $data = $this->request->getJSON();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'amount' => 'required',
            'status' => 'in_list[SUCCESS,WAITING_PAYMENT,FAILED,CANCEL,REFUNDED]',
            'type' => 'required|in_list[DEBIT,CREDIT]',
            'id_toko' => 'required',
            'nohp' => 'numeric',
            'customer_name' => 'required',
            'nama_cust' => 'required',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        try {
            $customerId = null;

            if (!empty($data->nohp)) {
                $customer = $this->customer->where('no_hp_customer', $data->nohp)->first();
                if (!$customer) {
                    $this->customer->insert(['customer_name' => $data->customer_name, 'customer_phone' => $data->nohp]);
                    $customerId = $this->customer->insertID();
                } else {
                    $customerId = $customer['id'];
                }
            }

            $tsData = [
                'amount' => $data->amount,
                'customer_id' => $customerId,
                'customer_name' => $data->customer_name,
                'status' => $data->status ?? 'WAITING_PAYMENT',
                'type' => $data->type,
                'id_toko' => $data->id_toko,
                'date_time' => date('Y-m-d H:i:s')
            ];

            if ($this->transactions->insert($tsData)) {
                $insertID = $this->transactions->insertID();
                $invoice = "INV/" . date('y-m-d') . '/' . $insertID;

                $this->transactionMeta->insertBatch([
                    ['transaction_id' => $insertID, 'key' => 'invoice', 'value' => $invoice],
                    ['transaction_id' => $insertID, 'key' => 'nama_cust', 'value' => $data->nama_cust]
                ]);
            }

            return $this->jsonResponse->oneResp('', 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage());
        }
    }

}
