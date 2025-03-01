<?php

namespace App\Controllers;

use App\Controllers\BaseController;
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

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
        $this->transactions = new TransactionModel();
        $this->transactionMeta = new TransactionMetaModel();
    }
    public function creteTransaction()
    {
        $data = $this->request->getJSON();

        $validation = \Config\Services::validation();
        $validation->setRules([
            'amount' => 'required',
            'status' => 'in_list[SUCCESS,WAITING_PAYMENT,FAILED,CANCEL,REFUNDED]',
            'type' => 'required|in_list[DEBIT,CREDIT]',
            'id_toko' => 'required',
            'nama_cust' => 'required',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $tsData = [
            'amount' => $data->amount,
            'status' => !empty($data->status) ? $data->status : 'WAITING_PAYMENT',
            'type' => $data->type,
            'id_toko' => $data->id_toko,
            'date_time' => date('Y-m-d H:i:s')
        ];


        $query = $this->transactions->insert($tsData);

        if ($query) {
            $insertID = $this->transactions->insertID();
            $invoice = "INV/" . date('y-m-d') .'/'.$insertID;
            $metaTransaction[] = [
                'transaction_id' => $insertID,
                'key' => "invoice",
                'value' => $invoice,
            ];

            $metaTransaction[] = [
                'transaction_id' => $insertID,
                'key' => "nama_cust",
                'value' => $data->nama_cust,
            ];

            $this->transactionMeta->insertBatch($metaTransaction);


            return $this->jsonResponse->oneResp('', 200);
        }

    }
}
