<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\JsonResponse;

class ClosingControllerV2 extends ResourceController
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

    // PREVIEW CLOSING (Lihat angka sebelum eksekusi)
    public function preview()
    {
        $month = $this->request->getGet('month') ?? date('m');
        $year = $this->request->getGet('year') ?? date('Y');
        $tokoId = $this->request->getGet('id_toko');

        $startDate = "$year-$month-01";
        $endDate = date("Y-m-t", strtotime($startDate));

        // Calculate Revenue & Expense
        $pnl = $this->calculatePnL($startDate, $endDate, $tokoId);
        
        return $this->jsonResponse->oneResp("Preview Closing $month/$year" . ($tokoId ? " Toko #$tokoId" : ""), $pnl, 200);
    }

    // PROCESS CLOSING
    public function process()
    {
        $data = $this->request->getJSON();
        $month = $data->month ?? date('m');
        $year = $data->year ?? date('Y');
        $tokoId = $data->id_toko ?? null;
        
        if (empty($tokoId)) {
            // Ideally we should require toko_id for precise closing
             return $this->jsonResponse->error("Toko ID is required for closing", 400); 
        }

        $startDate = "$year-$month-01";
        $endDate = date("Y-m-t", strtotime($startDate));

        // Check if already closed? (Optional, check if closing journal exists)
        $existing = $this->journalModel
            ->where('reference_type', 'CLOSING')
            ->where('date', $endDate)
            ->where('id_toko', $tokoId)
            ->first();
        
        if ($existing) {
            return $this->jsonResponse->error("Period $month/$year already closed for Toko #$tokoId (Journal ID: {$existing['id']})", 400);
        }

        $this->db->transStart();

        try {
            $pnl = $this->calculatePnL($startDate, $endDate, $tokoId);
            $netIncome = $pnl['net_income'];
            
            // Create Closing Journal
            // Date: Last day of month
            $journalId = $this->createJournal('CLOSING', "CL-$year$month-$tokoId", "Closing Entry $month/$year Toko #$tokoId", $endDate, "Closing Period $month/$year", $tokoId);

            // 1. Close Revenues (Debit REVENUE accounts)
            foreach ($pnl['revenues'] as $rev) {
                if ($rev['balance'] != 0) {
                    $this->addJournalItem($journalId, $rev['account_id'], abs($rev['balance']), 0);
                }
            }

            // 2. Close Expenses (Credit EXPENSE accounts)
            foreach ($pnl['expenses'] as $exp) {
                if ($exp['balance'] != 0) {
                    $this->addJournalItem($journalId, $exp['account_id'], 0, abs($exp['balance']));
                }
            }

            // 3. Post to Retained Earnings / Equity (3001)
            $equityAccount = $this->accountModel->where('code', '3001')->first(); // Owner Equity
            if (!$equityAccount) throw new \Exception("Equity Account 3001 not found");
            
            if ($netIncome > 0) {
                $this->addJournalItem($journalId, $equityAccount['id'], 0, $netIncome);
            } elseif ($netIncome < 0) {
                $this->addJournalItem($journalId, $equityAccount['id'], abs($netIncome), 0);
            }

            $this->db->transComplete();
            
            return $this->jsonResponse->oneResp("Closing successful for Toko #$tokoId", ['journal_id' => $journalId, 'net_income' => $netIncome], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    private function calculatePnL($startDate, $endDate, $tokoId = null)
    {
        // Get all Revenue Accounts Balance
        $revenues = $this->getAccountBalances('REVENUE', $startDate, $endDate, $tokoId);
        // Get all Expense Accounts Balance
        $expenses = $this->getAccountBalances('EXPENSE', $startDate, $endDate, $tokoId);

        $totalRevenue = array_sum(array_column($revenues, 'balance'));
        $totalExpense = array_sum(array_column($expenses, 'balance'));

        return [
            'revenues' => $revenues,
            'expenses' => $expenses,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_income' => $totalRevenue - $totalExpense
        ];
    }

    private function getAccountBalances($type, $startDate, $endDate, $tokoId = null)
    {
        $accounts = $this->accountModel->where('type', $type)->findAll();
        $results = [];

        foreach ($accounts as $acc) {
            $builderDebit = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date >=', $startDate)
                ->where('journals.date <=', $endDate)
                ->where('journals.reference_type !=', 'CLOSING');
            
            if ($tokoId) {
                $builderDebit->where('journals.id_toko', $tokoId);
            }
            $debit = $builderDebit->selectSum('debit')->get()->getRow()->debit ?? 0;
            
            $builderCredit = $this->db->table('journal_items')
                 ->join('journals', 'journals.id = journal_items.journal_id')
                 ->where('journal_items.account_id', $acc['id'])
                 ->where('journals.date >=', $startDate)
                 ->where('journals.date <=', $endDate)
                 ->where('journals.reference_type !=', 'CLOSING');

            if ($tokoId) {
                $builderCredit->where('journals.id_toko', $tokoId);
            }
            $credit = $builderCredit->selectSum('credit')->get()->getRow()->credit ?? 0;

            if ($type == 'REVENUE' || $type == 'EQUITY' || $type == 'LIABILITY') {
                $balance = $credit - $debit;
            } else {
                $balance = $debit - $credit;
            }

            $results[] = [
                'account_id' => $acc['id'],
                'code' => $acc['code'],
                'name' => $acc['name'],
                'balance' => $balance
            ];
        }
        return $results;
    }

    private function createJournal($refType, $refId, $refNo, $date, $desc, $tokoId = null) {
        $this->journalModel->insert([
            'id_toko' => $tokoId,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'date' => $date,
            'description' => $desc,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        return $this->journalModel->getInsertID();
    }

    private function addJournalItem($journalId, $accountId, $debit, $credit) {
        $this->journalItemModel->insert([
            'journal_id' => $journalId,
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}
