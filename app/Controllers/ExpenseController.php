<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\JsonResponse;

class ExpenseController extends ResourceController
{
    protected $journalModel;
    protected $journalItemModel;
    protected $accountModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->accountModel = new AccountModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // LIST OPTIONS (Expense Accounts)
    public function accounts()
    {
        $accounts = $this->accountModel->where('type', 'EXPENSE')->findAll();
        return $this->jsonResponse->oneResp('Expense Accounts', $accounts, 200);
    }

    // CREATE EXPENSE
    public function create()
    {
        $data = $this->request->getJSON();
        $user = $this->request->user['user_id'] ?? 0;

        // Validation
        if (empty($data->account_code) || empty($data->amount) || empty($data->payment_method)) {
            return $this->jsonResponse->error('Account Code, Amount, and Payment Method Required', 400);
        }

        $expenseAccount = $this->accountModel->where('code', $data->account_code)->first();
        if (!$expenseAccount || $expenseAccount['type'] !== 'EXPENSE') {
            return $this->jsonResponse->error('Invalid Expense Account', 400);
        }
        
        $cashAccountCode = ($data->payment_method === 'BANK') ? '1002' : '1001';
        $cashAccount = $this->accountModel->where('code', $cashAccountCode)->first();

        $this->db->transStart();

        try {
            // Create Journal Header
            $refId = date('YmdHis');
            $journalData = [
                'id_toko' => $data->id_toko ?? null,
                'reference_type' => 'EXPENSE',
                'reference_id' => $refId,
                'reference_no' => "EXP-{$refId}",
                'date' => $data->date ?? date('Y-m-d'),
                'description' => $data->description ?? "Expense {$expenseAccount['name']}",
                'total_debit' => $data->amount,
                'total_credit' => $data->amount,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $this->journalModel->insert($journalData);
            $journalId = $this->journalModel->getInsertID();

            // Journal Lines
            // 1. Dr Expense
            $this->journalItemModel->insert([
                'journal_id' => $journalId,
                'account_id' => $expenseAccount['id'],
                'debit' => $data->amount,
                'credit' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // 2. Cr Cash/Bank
            $this->journalItemModel->insert([
                'journal_id' => $journalId,
                'account_id' => $cashAccount['id'],
                'debit' => 0,
                'credit' => $data->amount,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                 return $this->jsonResponse->error('Failed to record expense', 500);
            }

            return $this->jsonResponse->oneResp('Expense recorded successfully', ['journal_id' => $journalId], 201);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
