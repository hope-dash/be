<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JournalModel;
use App\Models\JournalItemModel;
use App\Models\AccountModel;
use App\Models\JsonResponse;

class FinanceController extends ResourceController
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

    // Transfer Funds (Antar Bank/Toko)
    public function transfer()
    {
        $data = $this->request->getJSON();
        $user = $this->request->user['user_id'] ?? 0;

        // Data: from_account_code, to_account_code, amount, date, description, id_toko_source, id_toko_dest
        // Wait, id_toko usually implies where the journal belongs. 
        // If transfer from Toko A to Toko B:
        // Journal 1 (Toko A): Dr Suspense/Transfer Out, Cr Cash Toko A
        // Journal 2 (Toko B): Dr Cash Toko B, Cr Suspense/Transfer In
        // OR Single Journal if consolidated DB?
        // Since we have `id_toko` on Journal Header, we can't easily have ONE journal for TWO tokos.
        // We need 2 Journals.

        $amount = $data->amount;
        if ($amount <= 0)
            return $this->jsonResponse->error("Amount must be positive", 400);

        $fromToko = $data->source_toko_id;
        $toToko = $data->target_toko_id;

        $this->db->transStart();

        try {

            // 1. Credit Source (Money Leaving Source Toko)
            // Use '1005' or similar as Clearing Account if needed, or direct transfer logic if simpler.
            // Let's use '1001' (Cash) or '1002' (Bank) based on input.
            // Assumption: User provides account codes.
            // If Source is Toko A, we create journal in Toko A.

            $j1 = $this->createJournal('TRANSFER_OUT', "TRF-" . date('ymdHis'), "Transfer Out to Toko #$toToko", $data->date ?? date('Y-m-d'), $fromToko);
            // Cr Cash (Source)
            // Dr Clearing/Transfer Account? Or directly Dr Equity?
            // "Disetor ke bank utama".
            // If Bank Utama is Central (Toko 0 or NULL), then it is transfer.
            // Let's use a "Suspense/Clearing" account '1005' for checks.
            // Or if we treat it as internal transfer:

            // Simpler approach for single database:
            // Side A (Source): Cr Bank A, Dr Internal Transfer (Equity/Liability or Asset)
            // Side B (Dest): Dr Bank B, Cr Internal Transfer

            // We need an "Internal Transfer" account. Let's assume '1005' is Funds in Transit (Asset).

            // Side A: Decrease Asset (Bank), Increase Asset (Transit) -> Net Asset same? No.
            // If Money leaves A, A's Asset decreases.
            // If we want to track it on A's books, Dr Owner Withdraw? Or Dr Funds Transfer Out.

            // User requirement: "setor ke bank utama".
            // Let's implement as:
            // 1. Source Journal: Cr SourceAccount, Dr '1005' (Funds in Transit).
            $this->addJournalItem($j1, $data->source_account_code, 0, $amount, $fromToko); // Credit
            $this->addJournalItem($j1, '1005', $amount, 0, $fromToko); // Debit Clearing

            // 2. Dest Journal: Dr DestAccount, Cr '1005' (Funds in Transit).
            $j2 = $this->createJournal('TRANSFER_IN', "TRF-" . date('ymdHis'), "Transfer In from Toko #$fromToko", $data->date ?? date('Y-m-d'), $toToko);
            $this->addJournalItem($j2, $data->target_account_code, $amount, 0, $toToko); // Debit
            $this->addJournalItem($j2, '1005', 0, $amount, $toToko); // Credit Clearing

            // If account 1005 doesn't exist, we should seed it.

            $this->db->transComplete();
            return $this->jsonResponse->oneResp('Transfer successful', ['journal_out' => $j1, 'journal_in' => $j2], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Profit Distribution (Dividend / Prive)
    public function distributeProfit()
    {
        $data = $this->request->getJSON();
        // amount, id_toko, account_code (usually Bank), date

        $this->db->transStart();
        try {
            // Dr Retained Earnings (3001) or Dividend (3002)
            // Cr Bank

            // 50% to Capital (No journal needed, it stays in Equity/Retained Earnings).
            // 25% to Owner (Cash Out).

            $withdrawAmount = $data->amount; // Only the 25% or whatever amount they withdraw

            $jid = $this->createJournal('DIVIDEND', "DIV-" . date('ymdHis'), "Profit Distribution (Withdrawal)", $data->date ?? date('Y-m-d'), $data->id_toko);

            $this->addJournalItem($jid, '3001', $withdrawAmount, 0, $data->id_toko); // Dr Equity (Reduces Equity)
            $this->addJournalItem($jid, $data->account_code, 0, $withdrawAmount, $data->id_toko); // Cr Bank

            $this->db->transComplete();
            return $this->jsonResponse->oneResp('Profit distribution recorded', ['journal_id' => $jid], 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    private function createJournal($refType, $refId, $desc, $date, $tokoId)
    {
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

    private function addJournalItem($journalId, $accountCode, $debit, $credit, $tokoId)
    {
        $account = $this->accountModel->getByBaseCode($accountCode, $tokoId);
        if (!$account) {
            // Fallback for direct code
            $account = $this->accountModel->where('code', $accountCode)->first();

            if (!$account && $accountCode == '1005') {
                $this->accountModel->insert(['code' => $accountCode, 'name' => 'Funds In Transit', 'type' => 'ASSET', 'normal_balance' => 'DEBIT']);
                $account = $this->accountModel->where('code', '1005')->first();
            } else if (!$account) {
                throw new \Exception("Account $accountCode not found for this store");
            }
        }
        $this->journalItemModel->insert([
            'journal_id' => $journalId,
            'account_id' => $account['id'],
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

}
