<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\JsonResponse;

class JournalController extends ResourceController
{
    protected $journalModel;
    protected $journalItemModel;
    protected $accountModel;
    protected $jsonResponse;

    public function __construct()
    {
        $this->journalModel = new JournalModel();
        $this->journalItemModel = new JournalItemModel();
        $this->accountModel = new AccountModel();
        $this->jsonResponse = new JsonResponse();
    }

    /**
     * List journals (filterable by id_toko, date, reference_type)
     */
    public function index()
    {
        $id_toko = $this->request->getGet('id_toko');
        $date_start = $this->request->getGet('date_start');
        $date_end = $this->request->getGet('date_end');
        $ref_type = $this->request->getGet('reference_type');

        $builder = $this->journalModel->builder();

        if ($id_toko) {
            $builder->where('id_toko', $id_toko);
        }
        if ($date_start) {
            $builder->where('date >=', $date_start);
        }
        if ($date_end) {
            $builder->where('date <=', $date_end);
        }
        if ($ref_type) {
            $builder->where('reference_type', $ref_type);
        }

        $sortBy = $this->request->getGet('sortBy') ?: 'date';
        $sortMethod = $this->request->getGet('sortMethod') ?: 'desc';
        $limit = (int) ($this->request->getGet('limit') ?: 20);
        $page = (int) ($this->request->getGet('page') ?: 1);
        $offset = ($page - 1) * $limit;

        $totalData = $builder->countAllResults(false);
        $totalPage = ceil($totalData / $limit);

        $journals = $builder->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        // Optional: Hydrate items if needed, but for listing maybe just header info is enough
        // Or hydrate if specific flag is set
        if ($this->request->getGet('with_items') === 'true') {
            foreach ($journals as &$j) {
                $j['items'] = $this->journalItemModel
                    ->select('journal_items.*, accounts.code as account_code, accounts.name as account_name')
                    ->join('accounts', 'accounts.id = journal_items.account_id')
                    ->where('journal_id', $j['id'])
                    ->findAll();
            }
        }

        return $this->jsonResponse->multiResp('Success', $journals, $totalData, $totalPage, $page, $limit, 200);
    }

    /**
     * Get single journal detail
     */
    public function show($id = null)
    {
        $journal = $this->journalModel->find($id);
        if (!$journal) {
            return $this->jsonResponse->error('Journal not found', 404);
        }

        $items = $this->journalItemModel
            ->select('journal_items.*, accounts.code as account_code, accounts.name as account_name')
            ->join('accounts', 'accounts.id = journal_items.account_id')
            ->where('journal_id', $id)
            ->findAll();

        $journal['items'] = $items;

        return $this->jsonResponse->oneResp('Success', $journal, 200);
    }

    /**
     * Create manual journal entry
     */
    public function createManualJournal()
    {
        $data = $this->request->getJSON(true);

        // Validation
        if (empty($data['items']) || !is_array($data['items'])) {
            return $this->jsonResponse->error('Journal items are required', 400);
        }

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($data['items'] as $item) {
            $totalDebit += (float) ($item['debit'] ?? 0);
            $totalCredit += (float) ($item['credit'] ?? 0);

            if (empty($item['account_id'])) {
                return $this->jsonResponse->error('Account ID is required for each item', 400);
            }
        }

        // Check balance
        if (abs($totalDebit - $totalCredit) > 0.001) {
            return $this->jsonResponse->error("Journal is not balanced. Total Debit: $totalDebit, Total Credit: $totalCredit", 400);
        }

        if ($totalDebit <= 0) {
            return $this->jsonResponse->error("Amount must be greater than zero", 400);
        }

        $db = \Config\Database::connect();
        $db->transStart();

        try {
            // Generate Reference No
            $refNo = $data['reference_no'] ?? 'MJ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

            // Sanitize optional fields
            $idToko = !empty($data['id_toko']) ? $data['id_toko'] : null;
            $journalDate = !empty($data['date']) ? $data['date'] : date('Y-m-d');
            $referenceId = !empty($data['reference_id']) ? $data['reference_id'] : null;

            $journalData = [
                'id_toko' => $idToko,
                'reference_type' => 'MANUAL',
                'reference_id' => $referenceId,
                'reference_no' => $refNo,
                'date' => $journalDate,
                'description' => $data['description'] ?? 'Manual Adjustment',
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit
            ];

            if (!$this->journalModel->insert($journalData)) {
                $errors = $this->journalModel->errors();
                throw new \Exception('Failed to create journal header' . (!empty($errors) ? ': ' . json_encode($errors) : ''));
            }

            $journalId = $this->journalModel->getInsertID();

            foreach ($data['items'] as $item) {
                $itemData = [
                    'journal_id' => $journalId,
                    'account_id' => $item['account_id'],
                    'debit' => $item['debit'] ?? 0,
                    'credit' => $item['credit'] ?? 0,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                if (!$this->journalItemModel->insert($itemData)) {
                    $itemErrors = $this->journalItemModel->errors();
                    throw new \Exception('Failed to create journal item for account ID: ' . $item['account_id'] . (!empty($itemErrors) ? ': ' . json_encode($itemErrors) : ''));
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->jsonResponse->error('Transaction failed', 500);
            }

            return $this->jsonResponse->oneResp('Manual journal created successfully', ['id' => $journalId, 'reference_no' => $refNo], 201);

        } catch (\Exception $e) {
            $db->transRollback();
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
