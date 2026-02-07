<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\JsonResponse;

class AccountingReportController extends ResourceController
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

    // 1. GET JOURNAL (Detailed Entries)
    public function journal()
    {
        $startDate = $this->request->getGet('start_date') ?? date('Y-m-d');
        $endDate = $this->request->getGet('end_date') ?? date('Y-m-d');
        $tokoId = $this->request->getGet('id_toko');

        $builder = $this->journalModel
            ->select('journals.*, journal_items.debit, journal_items.credit, accounts.code, accounts.name as account_name')
            ->join('journal_items', 'journal_items.journal_id = journals.id')
            ->join('accounts', 'accounts.id = journal_items.account_id')
            ->where('journals.date >=', $startDate)
            ->where('journals.date <=', $endDate);
        
        if ($tokoId) {
            $builder->where('journals.id_toko', $tokoId);
        }

        $journal = $builder->orderBy('journals.date', 'ASC')
            ->orderBy('journals.id', 'ASC')
            ->findAll();
        
        return $this->jsonResponse->oneResp('Journal Report', $journal, 200);
    }

    // 2. GET LEDGER (Account Balances per Period)
    public function ledger() 
    {
        $startDate = $this->request->getGet('start_date') ?? date('Y-m-01');
        $endDate = $this->request->getGet('end_date') ?? date('Y-m-t');
        $tokoId = $this->request->getGet('id_toko');

        $accounts = $this->accountModel->findAll();
        $ledger = [];

        foreach ($accounts as $acc) {
            // Opening Balance
            $openingDebitBuilder = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date <', $startDate);
            if ($tokoId) $openingDebitBuilder->where('journals.id_toko', $tokoId);
            $openingDebit = $openingDebitBuilder->selectSum('debit')->get()->getRow()->debit ?? 0;
            
            $openingCreditBuilder = $this->db->table('journal_items')
                 ->join('journals', 'journals.id = journal_items.journal_id')
                 ->where('journal_items.account_id', $acc['id'])
                 ->where('journals.date <', $startDate);
            if ($tokoId) $openingCreditBuilder->where('journals.id_toko', $tokoId);
            $openingCredit = $openingCreditBuilder->selectSum('credit')->get()->getRow()->credit ?? 0;

            $openingBalanceRaw = $openingDebit - $openingCredit;

            // Period Movement
            $periodDebitBuilder = $this->db->table('journal_items')
                 ->join('journals', 'journals.id = journal_items.journal_id')
                 ->where('journal_items.account_id', $acc['id'])
                 ->where('journals.date >=', $startDate)
                 ->where('journals.date <=', $endDate);
            if ($tokoId) $periodDebitBuilder->where('journals.id_toko', $tokoId);
            $periodDebit = $periodDebitBuilder->selectSum('debit')->get()->getRow()->debit ?? 0;
            
            $periodCreditBuilder = $this->db->table('journal_items')
                 ->join('journals', 'journals.id = journal_items.journal_id')
                 ->where('journal_items.account_id', $acc['id'])
                 ->where('journals.date >=', $startDate)
                 ->where('journals.date <=', $endDate);
            if ($tokoId) $periodCreditBuilder->where('journals.id_toko', $tokoId);
            $periodCredit = $periodCreditBuilder->selectSum('credit')->get()->getRow()->credit ?? 0;

            $closingBalanceRaw = $openingBalanceRaw + ($periodDebit - $periodCredit);

            $ledger[] = [
                'account_code' => $acc['code'],
                'account_name' => $acc['name'],
                'type' => $acc['type'],
                'opening_balance' => $openingBalanceRaw,
                'debit' => $periodDebit,
                'credit' => $periodCredit,
                'closing_balance' => $closingBalanceRaw
            ];
        }

        return $this->jsonResponse->oneResp('General Ledger Summary' . ($tokoId ? " Toko #$tokoId" : ""), $ledger, 200);
    }
    
    // 3. INCOME STATEMENT (Laba Rugi)
    public function incomeStatement()
    {
        $startDate = $this->request->getGet('start_date') ?? date('Y-m-01');
        $endDate = $this->request->getGet('end_date') ?? date('Y-m-t');
        $tokoId = $this->request->getGet('id_toko');
        
        $revenues = $this->getAccountGroupBalance('REVENUE', $startDate, $endDate, $tokoId);
        $expenses = $this->getAccountGroupBalance('EXPENSE', $startDate, $endDate, $tokoId);
        
        $netIncome = $revenues['total'] - $expenses['total'];
        
        return $this->jsonResponse->oneResp('Income Statement', [
            'period' => "$startDate to $endDate",
            'revenues' => $revenues,
            'expenses' => $expenses,
            'net_income' => $netIncome
        ], 200);
    }

    // 4. BALANCE SHEET (Neraca)
    public function balanceSheet()
    {
        $endDate = $this->request->getGet('end_date') ?? date('Y-m-t');
        $tokoId = $this->request->getGet('id_toko');

        // Note: For Balance Sheet, we take ALL TIME balance up to EndDate.
        // So StartDate is implied as beginning of time.
        // However, Current Earnings (Net Income) needs to be calculated for the Open Period (since last closing).
        // If we assume closing is done monthly, Retained Earnings (3001) has historical.
        // And we just add unclosed Revenue - Expense to Equity.
        
        // Simpler: Just calculate Assets, Liabilities, Equity (All Time).
        // EQUITY includes 3001.
        // AND we must add (Revenue - Expense) of current OPEN period to Equity side as "Current Profit".
        // OR simply: Asset - Liability = Equity.
        
        $startDate = '2000-01-01'; // Beginning

        $assets = $this->getAccountGroupBalance('ASSET', $startDate, $endDate, $tokoId);
        $liabilities = $this->getAccountGroupBalance('LIABILITY', $startDate, $endDate, $tokoId);
        $equity = $this->getAccountGroupBalance('EQUITY', $startDate, $endDate, $tokoId);
        
        // Calculate Unclosed Net Income (Revenue - Expense) for ALL time (assuming closing moves it to 3001)
        // Actually, if we include CLOSING entries in logic, Revenue accounts become 0 after closing.
        // So Revenue Balance = Current Unclosed Revenue.
        // Expense Balance = Current Unclosed Expense.
        // So Net Income = Revenue Balance - Expense Balance.
        
        // But getAccountGroupBalance by default excludes CLOSING for REV/EXP?
        // Let's make it configurable OR default behavior.
        // IF we want "Current Retained Earnings", we need the balance of Rev/Exp.
        // And they should naturally reflect unclosed amounts if Closing Entries zero them out.
        // Wait, if I exclude closing entries in getAccountGroupBalance for REV/EXP, then I get ALL TIME Revenue.
        // That is WRONG for Balance Sheet calculation if I want "Current Earnings".
        // I want "Revenue since last close".
        // BUT if I INCLUDE closing entries, then Revenue = 0 (for closed periods) + Current Revenue.
        // So Balance of Revenue Account WITH closing entries = Current Period Revenue.
        // CORRECT.
        
        // So for Balance Sheet, we need Recalculation with Closing Entries INCLUDED for Rev/Exp?
        // Actually, let's look at `getAccountGroupBalance` implementation below.
        
        // Let's separate logic.
        // Assets, Liabilities, Equity: Include All Journals (Closing entries affect Equity 3001).
        // Rev/Exp: If we want "Current Year Earnings", we normally sum them. 
        // If we just want to balance the sheet: Asset = Liab + Equity + (Rev - Exp).
        
        // Let's implement `getAccountGroupBalance` to INCLUDE everything by default.
        // And `incomeStatement` can filter if needed?
        // No, Income Statement usually EXCLUDES closing entries to show performance?
        // If I include closing entries in Income Statement for a closed month, Revenue is 0.
        // So Income Statement MUST EXCLUDE Closing entries.
        
        // Balance Sheet MUST INCLUDE Closing entries (so 3001 is correct).
        // And for Current Earnings, it is residual of Rev/Exp accounts (which are 0 for closed, + current for open).
        // So Balance Sheet also needs to Include Closing entries for Rev/Exp to get residual.
        
        // SO: We add a flag `excludeClosing` to helper.
        
        $assets = $this->getAccountGroupBalance('ASSET', $startDate, $endDate, $tokoId, false);
        $liabilities = $this->getAccountGroupBalance('LIABILITY', $startDate, $endDate, $tokoId, false);
        $equity = $this->getAccountGroupBalance('EQUITY', $startDate, $endDate, $tokoId, false);
        
        $currentRev = $this->getAccountGroupBalance('REVENUE', $startDate, $endDate, $tokoId, false);
        $currentExp = $this->getAccountGroupBalance('EXPENSE', $startDate, $endDate, $tokoId, false);
        $currentEarnings = $currentRev['total'] - $currentExp['total'];
        
        $totalEquity = $equity['total'] + $currentEarnings;
        
        return $this->jsonResponse->oneResp('Balance Sheet', [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_earnings' => $currentEarnings, // Laba Berjalan
            'total_equity' => $totalEquity,
            'total_liabilities_and_equity' => $liabilities['total'] + $totalEquity
        ], 200);
    }

    private function getAccountGroupBalance($type, $startDate, $endDate, $tokoId = null, $excludeClosing = true)
    {
        $accounts = $this->accountModel->where('type', $type)->findAll();
        $list = [];
        $totalGroup = 0;

        foreach ($accounts as $acc) {
            // Debit
            $builderDeb = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date >=', $startDate)
                ->where('journals.date <=', $endDate);
                
            if ($tokoId) $builderDeb->where('journals.id_toko', $tokoId);
            if ($excludeClosing) $builderDeb->where('journals.reference_type !=', 'CLOSING');
            
            $debit = $builderDeb->selectSum('debit')->get()->getRow()->debit ?? 0;

            // Credit
            $builderCred = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date >=', $startDate)
                ->where('journals.date <=', $endDate);
                
            if ($tokoId) $builderCred->where('journals.id_toko', $tokoId);
            if ($excludeClosing) $builderCred->where('journals.reference_type !=', 'CLOSING');

            $credit = $builderCred->selectSum('credit')->get()->getRow()->credit ?? 0;

            if (in_array($type, ['REVENUE', 'EQUITY', 'LIABILITY'])) {
                 $bal = $credit - $debit;
            } else {
                 $bal = $debit - $credit;
            }
            
            if ($bal != 0) {
                $list[] = [
                    'code' => $acc['code'],
                    'name' => $acc['name'],
                    'balance' => $bal
                ];
                $totalGroup += $bal;
            }
        }
        
        return ['details' => $list, 'total' => $totalGroup];
    }
}
