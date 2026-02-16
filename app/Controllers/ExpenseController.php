<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\ExpenseModel;
use App\Models\JsonResponse;

class ExpenseController extends ResourceController
{
    protected $journalModel;
    protected $journalItemModel;
    protected $accountModel;
    protected $expenseModel;
    protected $jsonResponse;
    protected $db;

    public function __construct()
    {
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->accountModel = new AccountModel();
        $this->expenseModel = new ExpenseModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
    }

    // LIST OPTIONS (Expense Accounts)
    public function accounts()
    {
        $accounts = $this->accountModel->where('type', 'EXPENSE')->findAll();
        return $this->jsonResponse->oneResp('Expense Accounts', $accounts, 200);
    }

    // GET LIST EXPENSES with Filters, Search, and Pagination
    public function getList()
    {
        $page = $this->request->getGet('page') ?? 1;
        $perPage = $this->request->getGet('per_page') ?? 20;
        $search = $this->request->getGet('search') ?? '';
        $startDate = $this->request->getGet('start_date') ?? null;
        $endDate = $this->request->getGet('end_date') ?? null;
        $idToko = $this->request->getGet('id_toko') ?? null;
        $paymentMethod = $this->request->getGet('payment_method') ?? null;
        $sortBy = $this->request->getGet('sort_by') ?? 'date';
        $sortOrder = $this->request->getGet('sort_order') ?? 'DESC';

        // Build query
        $builder = $this->db->table('expenses e');
        $builder->select('e.*, a.code as account_code, a.name as account_name, a.type as account_type');
        $builder->join('accounts a', 'e.account_id = a.id', 'left');
        $builder->where('e.deleted_at', null);

        // Apply filters
        if ($idToko) {
            $builder->where('e.id_toko', $idToko);
        }

        if ($paymentMethod) {
            $builder->where('e.payment_method', $paymentMethod);
        }

        if ($startDate) {
            $builder->where('e.date >=', $startDate);
        }

        if ($endDate) {
            $builder->where('e.date <=', $endDate);
        }

        // Search
        if ($search) {
            $builder->groupStart();
            $builder->like('e.description', $search);
            $builder->orLike('a.name', $search);
            $builder->orLike('a.code', $search);
            $builder->groupEnd();
        }

        // Count total before pagination
        $total = $builder->countAllResults(false);

        // Apply sorting and pagination
        $builder->orderBy("e.{$sortBy}", $sortOrder);
        $builder->limit($perPage, ($page - 1) * $perPage);

        $expenses = $builder->get()->getResultArray();

        // Calculate pagination metadata
        $totalPages = ceil($total / $perPage);

        return $this->jsonResponse->oneResp('Expenses retrieved successfully', [
            'data' => $expenses,
            'pagination' => [
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages
            ]
        ], 200);
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

        // Get toko-specific cash/bank account
        if (empty($data->id_toko)) {
            return $this->jsonResponse->error('id_toko is required', 400);
        }

        $toko = $this->db->table('toko')->where('id', $data->id_toko)->get()->getRowArray();
        if (!$toko) {
            return $this->jsonResponse->error('Toko not found', 404);
        }

        // Use toko's bank or cash account based on payment method
        $cashAccountId = ($data->payment_method === 'BANK')
            ? $toko['bank_account_id']
            : $toko['cash_account_id'];

        if (!$cashAccountId) {
            return $this->jsonResponse->error('Toko does not have ' . $data->payment_method . ' account configured', 400);
        }

        $cashAccount = $this->accountModel->find($cashAccountId);

        $this->db->transStart();

        try {
            // 1. Insert into Expense Table
            $expenseData = [
                'id_toko' => $data->id_toko ?? null,
                'account_id' => $expenseAccount['id'],
                'amount' => $data->amount,
                'payment_method' => $data->payment_method,
                'date' => $data->date ?? date('Y-m-d'),
                'description' => $data->description ?? "Expense {$expenseAccount['name']}",
                'attachment' => $data->attachment ?? null, // Link to uploaded file
            ];

            $this->expenseModel->insert($expenseData);
            $expenseId = $this->expenseModel->getInsertID();

            // 2. Create Journal Header
            $journalData = [
                'id_toko' => $data->id_toko ?? null,
                'reference_type' => 'EXPENSE',
                'reference_id' => (string) $expenseId,
                'reference_no' => "EXP-" . date('ymd') . "-" . str_pad($expenseId, 4, '0', STR_PAD_LEFT),
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
            // 3. Dr Expense
            $this->journalItemModel->insert([
                'journal_id' => $journalId,
                'account_id' => $expenseAccount['id'],
                'debit' => $data->amount,
                'credit' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // 4. Cr Cash/Bank
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

            return $this->jsonResponse->oneResp('Expense recorded successfully', [
                'expense_id' => $expenseId,
                'journal_id' => $journalId
            ], 201);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
