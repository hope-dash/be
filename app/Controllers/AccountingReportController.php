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
        $this->db = \Config\Database::connect();
    }

    // 0. GET ALL ACCOUNTS (Dropdown)
    public function getAccounts()
    {
        $type = $this->request->getGet('type'); // Optional: REVENUE, EXPENSE, ASSET, LIABILITY, EQUITY
        $idToko = $this->request->getGet('id_toko');

        $builder = $this->accountModel;

        if ($type) {
            $builder->where('type', $type);
        }

        $isUtama = false;
        if ($idToko) {
            $toko = $this->db->table('toko')->where('id', $idToko)->get()->getRow();
            if ($toko && strtoupper($toko->type ?? '') === 'UTAMA') {
                $isUtama = true;
            }
        }

        if ($isUtama) {
            // Show all accounts for UTAMA
            $builder->where('id_toko !=', null);
        } else if ($idToko) {
            $builder->where('id_toko', $idToko);
        } else {
            $builder->where('id_toko !=', null);
        }

        $accounts = $builder->orderBy('code', 'ASC')->findAll();

        return $this->jsonResponse->oneResp('List Accounts', $accounts, 200);
    }
    public function journal()
    {
        $startDate = $this->request->getGet('start_date') ?? date('Y-m-d');
        $endDate = $this->request->getGet('end_date') ?? date('Y-m-d');
        $tokoId = $this->request->getGet('id_toko');

        $builder = $this->db->table('journals j')
            ->select('j.id as journal_id, j.date, j.reference_no, j.description, ji.debit, ji.credit, a.code as account_code, a.name as account_name')
            ->join('journal_items ji', 'ji.journal_id = j.id')
            ->join('accounts a', 'a.id = ji.account_id')
            ->where('j.date >=', $startDate)
            ->where('j.date <=', $endDate);

        $isUtama = false;
        if ($tokoId) {
            $toko = $this->db->table('toko')->where('id', $tokoId)->get()->getRow();
            if ($toko && strtoupper($toko->type ?? '') === 'UTAMA') {
                $isUtama = true;
            }
        }

        if ($tokoId && !$isUtama) {
            $builder->where('j.id_toko', $tokoId);
        }

        $results = $builder->orderBy('j.date', 'ASC')
            ->orderBy('j.id', 'ASC')
            ->get()->getResultArray();

        $journals = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($results as $row) {
            $jid = $row['journal_id'];
            if (!isset($journals[$jid])) {
                $journals[$jid] = [
                    'id' => $jid,
                    'date' => $row['date'],
                    'reference_no' => $row['reference_no'] ?? '',
                    'description' => $row['description'],
                    'items' => []
                ];
            }
            $journals[$jid]['items'][] = [
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'debit' => (float) $row['debit'],
                'credit' => (float) $row['credit']
            ];
            $totalDebit += (float) $row['debit'];
            $totalCredit += (float) $row['credit'];
        }

        return $this->jsonResponse->oneResp('Journal Report', [
            'journals' => array_values($journals),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit
        ], 200);
    }

    // 2. GET LEDGER (Account Balances per Period)
    public function ledger()
    {
        $startDate = $this->request->getGet('start_date') ?? date('Y-m-01');
        $endDate = $this->request->getGet('end_date') ?? date('Y-m-t');
        $tokoId = $this->request->getGet('id_toko');

        $isUtama = false;
        if ($tokoId) {
            $toko = $this->db->table('toko')->where('id', $tokoId)->get()->getRow();
            if ($toko && strtoupper($toko->type ?? '') === 'UTAMA') {
                $isUtama = true;
            }
        }

        $accountsBuilder = $this->accountModel;
        if ($isUtama) {
            $accountsBuilder->where('id_toko !=', null);
        } else if ($tokoId) {
            $accountsBuilder->where('id_toko', $tokoId);
        } else {
            // Consolidation: use templates or all? 
            // User said "laporany jurnal juga akan jadi semua journal x 3"
            // So if no tokoId, maybe show ALL accounts? 
            // But usually grand total report should group by base_code.
            // For now, let's allow showing all if no tokoId to satisfy "journal x 3".
        }
        $accounts = $accountsBuilder->orderBy('code', 'ASC')->findAll();
        $ledger = [];

        foreach ($accounts as $acc) {
            // Opening Balance
            $openingDebitBuilder = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date <', $startDate);
            if ($tokoId && !$isUtama)
                $openingDebitBuilder->where('journals.id_toko', $tokoId);
            $openingDebit = $openingDebitBuilder->selectSum('debit')->get()->getRow()->debit ?? 0;

            $openingCreditBuilder = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date <', $startDate);
            if ($tokoId && !$isUtama)
                $openingCreditBuilder->where('journals.id_toko', $tokoId);
            $openingCredit = $openingCreditBuilder->selectSum('credit')->get()->getRow()->credit ?? 0;

            $openingBalanceRaw = $openingDebit - $openingCredit;

            // Period Movement
            $periodDebitBuilder = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date >=', $startDate)
                ->where('journals.date <=', $endDate);
            if ($tokoId && !$isUtama)
                $periodDebitBuilder->where('journals.id_toko', $tokoId);
            $periodDebit = $periodDebitBuilder->selectSum('debit')->get()->getRow()->debit ?? 0;

            $periodCreditBuilder = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date >=', $startDate)
                ->where('journals.date <=', $endDate);
            if ($tokoId && !$isUtama)
                $periodCreditBuilder->where('journals.id_toko', $tokoId);
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

    // 2.5 GET LEDGER DETAIL (Single Account)
    public function ledgerDetail()
    {
        $accountId = $this->request->getGet('account_id');
        $startDate = $this->request->getGet('start_date') ?? date('Y-m-01');
        $endDate = $this->request->getGet('end_date') ?? date('Y-m-t');
        $tokoId = $this->request->getGet('id_toko');

        if (!$accountId) {
            return $this->jsonResponse->error('Account ID is required', 400);
        }

        $account = $this->accountModel->find($accountId);
        if (!$account) {
            return $this->jsonResponse->error('Account not found', 404);
        }

        // 1. Calculate Opening Balance
        $builderOpen = $this->db->table('journal_items ji')
            ->join('journals j', 'j.id = ji.journal_id')
            ->where('ji.account_id', $accountId)
            ->where('j.date <', $startDate);

        $isUtama = false;
        if ($tokoId) {
            $toko = $this->db->table('toko')->where('id', $tokoId)->get()->getRow();
            if ($toko && strtoupper($toko->type ?? '') === 'UTAMA') {
                $isUtama = true;
            }
        }

        if ($tokoId && !$isUtama)
            $builderOpen->where('j.id_toko', $tokoId);

        $openResult = $builderOpen->selectSum('ji.debit', 'total_debit')
            ->selectSum('ji.credit', 'total_credit')
            ->get()->getRow();

        $openDebit = (float) ($openResult->total_debit ?? 0);
        $openCredit = (float) ($openResult->total_credit ?? 0);

        // Determine Normal Balance direction
        // ASSET, EXPENSE: Debit is positive
        // LIABILITY, EQUITY, REVENUE: Credit is positive
        $isNormalDebit = in_array($account['type'], ['ASSET', 'EXPENSE']);

        if ($isNormalDebit) {
            $openingBalance = $openDebit - $openCredit;
        } else {
            $openingBalance = $openCredit - $openDebit;
        }

        // 2. Get Transactions
        $builderTrans = $this->db->table('journal_items ji')
            ->select('j.date, j.reference_no, j.description, ji.debit, ji.credit')
            ->join('journals j', 'j.id = ji.journal_id')
            ->where('ji.account_id', $accountId)
            ->where('j.date >=', $startDate)
            ->where('j.date <=', $endDate);

        if ($tokoId && !$isUtama)
            $builderTrans->where('j.id_toko', $tokoId);

        $transactions = $builderTrans->orderBy('j.date', 'ASC')
            ->orderBy('j.id', 'ASC')
            ->get()->getResultArray();

        // 3. Process Running Balance
        $resultData = [];
        $currentBalance = $openingBalance;
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($transactions as $row) {
            $d = (float) $row['debit'];
            $c = (float) $row['credit'];

            if ($isNormalDebit) {
                $currentBalance += ($d - $c);
            } else {
                $currentBalance += ($c - $d);
            }

            $resultData[] = [
                'date' => $row['date'],
                'reference_no' => $row['reference_no'],
                'description' => $row['description'],
                'debit' => $d,
                'credit' => $c,
                'balance' => $currentBalance
            ];

            $totalDebit += $d;
            $totalCredit += $c;
        }

        return $this->jsonResponse->oneResp('Ledger Detail', [
            'account' => $account,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'opening_balance' => $openingBalance,
            'transactions' => $resultData,
            'closing_balance' => $currentBalance,
            'total_mutation' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit
            ]
        ], 200);
    }

    // 3. INCOME STATEMENT (Laba Rugi)
    public function incomeStatement()
    {
        $startDate = $this->request->getGet('start_date') ?? date('Y-m-01');
        $endDate = $this->request->getGet('end_date') ?? date('Y-m-t');
        $tokoId = $this->request->getGet('id_toko');

        // --- Current Period Data ---
        $revenues = $this->getAccountGroupBalance('REVENUE', $startDate, $endDate, $tokoId);
        $expenses = $this->getAccountGroupBalance('EXPENSE', $startDate, $endDate, $tokoId);

        $totalRevenue = $revenues['total'];
        $totalExpense = $expenses['total'];
        $netIncome = $totalRevenue - $totalExpense;

        // --- Calculate COGS & OPEX ---
        // Assuming COGS accounts start with '5' and OPEX with '6'
        $cogs = 0;
        $opex = 0;

        foreach ($expenses['details'] as $exp) {
            if (strpos($exp['code'], '5') === 0) {
                $cogs += $exp['balance'];
            } else {
                $opex += $exp['balance'];
            }
        }

        $grossProfit = $totalRevenue - $cogs;

        // --- Metrics (Summary Highlights) ---
        $grossMargin = ($totalRevenue > 0) ? ($grossProfit / $totalRevenue) * 100 : 0;
        $netProfitMargin = ($totalRevenue > 0) ? ($netIncome / $totalRevenue) * 100 : 0;
        $expenseRatio = ($totalRevenue > 0) ? ($totalExpense / $totalRevenue) * 100 : 0; // Total Expense Ratio
        // Or Opex Ratio: ($opex / $totalRevenue) * 100

        // --- Previous Period Comparison ---
        $startObj = new \DateTime($startDate);
        $endObj = new \DateTime($endDate);
        $interval = $startObj->diff($endObj);
        $days = $interval->days + 1;

        $prevEndObj = (clone $startObj)->modify('-1 day');
        $prevStartObj = (clone $prevEndObj)->modify("-" . ($days - 1) . " days");

        $prevStartDate = $prevStartObj->format('Y-m-d');
        $prevEndDate = $prevEndObj->format('Y-m-d');

        $prevRevenues = $this->getAccountGroupBalance('REVENUE', $prevStartDate, $prevEndDate, $tokoId);
        $prevExpenses = $this->getAccountGroupBalance('EXPENSE', $prevStartDate, $prevEndDate, $tokoId);
        $prevNetIncome = $prevRevenues['total'] - $prevExpenses['total'];

        // Growth Calculation
        $growth = 0;
        if ($prevNetIncome != 0) {
            $growth = (($netIncome - $prevNetIncome) / abs($prevNetIncome)) * 100;
        } else if ($netIncome > 0) {
            $growth = 100; // From 0 to Positive
        }

        // Performance Text
        $performanceTitle = ($growth >= 0) ? "Performa Positif" : "Performa Menurun";
        $trend = ($growth >= 0) ? "meningkat" : "menurun";
        $absGrowth = number_format(abs($growth), 1);

        // Expense Analysis Text
        $prevTotalExpense = $prevExpenses['total'];
        $expGrowth = ($prevTotalExpense > 0) ? (($totalExpense - $prevTotalExpense) / $prevTotalExpense) * 100 : 0;
        $expText = ($expGrowth <= 5) ? "Pengeluaran operasional terkendali." : "Pengeluaran operasional meningkat " . number_format($expGrowth, 1) . "%.";

        $description = "Laba bersih periode ini $trend $absGrowth% dibandingkan periode sebelumnya. $expText";

        return $this->jsonResponse->oneResp('Income Statement', [
            'period' => "$startDate to $endDate",
            'prev_period' => "$prevStartDate to $prevEndDate",
            'summary_highlights' => [
                'gross_margin' => round($grossMargin, 1),
                'net_profit_margin' => round($netProfitMargin, 1),
                'expense_ratio' => round($expenseRatio, 1)
            ],
            'performance' => [
                'title' => $performanceTitle,
                'description' => $description,
                'is_positive' => ($growth >= 0)
            ],
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
        $isUtama = false;
        if ($tokoId) {
            $toko = $this->db->table('toko')->where('id', $tokoId)->get()->getRow();
            if ($toko && strtoupper($toko->type ?? '') === 'UTAMA') {
                $isUtama = true;
            }
        }

        $accountsBuilder = $this->accountModel->where('type', $type);
        if ($isUtama) {
            $accountsBuilder->where('id_toko !=', null);
        } else if ($tokoId) {
            $accountsBuilder->where('id_toko', $tokoId);
        } else {
            // Show all for consolidation if no tokoId
        }
        $accounts = $accountsBuilder->orderBy('code', 'ASC')->findAll();
        $list = [];
        $totalGroup = 0;

        foreach ($accounts as $acc) {
            // Debit
            $builderDeb = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date >=', $startDate)
                ->where('journals.date <=', $endDate);

            if ($tokoId && !$isUtama)
                $builderDeb->where('journals.id_toko', $tokoId);
            if ($excludeClosing)
                $builderDeb->where('journals.reference_type !=', 'CLOSING');

            $debit = $builderDeb->selectSum('debit')->get()->getRow()->debit ?? 0;

            // Credit
            $builderCred = $this->db->table('journal_items')
                ->join('journals', 'journals.id = journal_items.journal_id')
                ->where('journal_items.account_id', $acc['id'])
                ->where('journals.date >=', $startDate)
                ->where('journals.date <=', $endDate);

            if ($tokoId && !$isUtama)
                $builderCred->where('journals.id_toko', $tokoId);
            if ($excludeClosing)
                $builderCred->where('journals.reference_type !=', 'CLOSING');

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
