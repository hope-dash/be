<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Controllers\TiktokController;
use Config\Database;

class FixTiktokInvoicesCommand extends BaseCommand
{
    protected $group = 'TikTok';
    protected $name = 'tiktok:fix-invoices';
    protected $description = 'Inspect and fix all TikTok/Tokopedia invoices and their journals';
    protected $usage = 'tiktok:fix-invoices [--dry-run] [--id=ID] [--limit=N]';
    protected $arguments = [];
    protected $options = [
        '--dry-run' => 'Inspect only without applying fixes',
        '--id' => 'Fix specific transaction ID',
        '--limit' => 'Limit number of transactions to inspect/fix',
    ];

    public function run(array $params)
    {
        $db = Database::connect();
        $isDryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $targetId = CLI::getOption('id');
        $limit = (int)(CLI::getOption('limit') ?? 0);

        CLI::write("=== Verifying All TikTok Orders Against Real TikTok Finance API ===", "yellow");
        $controller = new TiktokController();

        $completedOrders = $db->table('transaction')
            ->select('id, invoice, status, actual_total, id_toko')
            ->where('LENGTH(invoice) >= 15')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        foreach ($completedOrders as $t) {
            $tId = $t['id'];
            $inv = $t['invoice'];
            $actual = (float)$t['actual_total'];
            $toko = $t['id_toko'];

            // 1. Get TikTok API Order Details
            $apiOrderResp = $controller->makeTiktokRequest($toko, 'GET', '/order/202507/orders', ['ids' => $inv, 'version' => '202507'], null);
            $apiOrder = $apiOrderResp['data']['orders'][0] ?? null;
            $apiStatus = $apiOrder['status'] ?? 'N/A';

            // 2. Get TikTok Finance Breakdown
            $financeData = $controller->fetchOrderFinanceBreakdown($toko, $inv);

            // 3. Get Current Settlement Journal from DB
            $settleJournal = $db->table('journals')
                ->where('reference_no', $inv)
                ->where('reference_type', 'SETTLEMENT')
                ->get()->getRowArray();

            $dbNet = null;
            $dbFee = null;
            $dbBankAcc = null;
            if ($settleJournal) {
                $jItems = $db->table('journal_items')->where('journal_id', $settleJournal['id'])->get()->getResultArray();
                foreach ($jItems as $ji) {
                    if ((float)$ji['debit'] > 0) {
                        // Check if Bank
                        $acc = $db->table('accounts')->where('id', $ji['account_id'])->get()->getRowArray();
                        if ($acc && $acc['base_code'] === '1002') {
                            $dbNet = (float)$ji['debit'];
                            $dbBankAcc = $acc['code'];
                        } elseif ($acc && $acc['base_code'] === '1001') {
                            $dbNet = (float)$ji['debit'];
                            $dbBankAcc = "CASH: " . $acc['code'];
                        } elseif ($acc && $acc['base_code'] === '5005') {
                            $dbFee = (float)$ji['debit'];
                        }
                    }
                }
            }

            $apiNet = $financeData['net_settlement'] ?? null;
            $apiFee = ($financeData['commission_fee'] ?? 0) + ($financeData['transaction_fee'] ?? 0);

            CLI::write(sprintf(
                "Trx #%d | Inv: %s | DB Status: %s | API Status: %s | Total: %s",
                $tId,
                $inv,
                $t['status'],
                $apiStatus,
                number_format($actual, 0, ',', '.')
            ), "white");

            CLI::write(sprintf(
                "   API Finance -> Net: %s | Fee: %s | Settled: %s",
                $apiNet !== null ? number_format($apiNet, 0, ',', '.') : 'NULL',
                number_format($apiFee, 0, ',', '.'),
                ($financeData['statement_time'] ?? null) ? 'YES' : 'NO'
            ), "light_cyan");

            CLI::write(sprintf(
                "   DB Settle   -> Net: %s (Acc: %s) | Fee: %s | Journal: %s",
                $dbNet !== null ? number_format($dbNet, 0, ',', '.') : 'NONE',
                $dbBankAcc ?? 'N/A',
                $dbFee !== null ? number_format($dbFee, 0, ',', '.') : 'NONE',
                $settleJournal ? 'ID ' . $settleJournal['id'] : 'NONE'
            ), ($settleJournal && strpos((string)$dbBankAcc, 'CASH') === false) ? "green" : "red");

            // Check discrepancy
            if ($apiStatus === 'COMPLETED') {
                if (!$settleJournal) {
                    CLI::write("   [!] ISSUE: Order is COMPLETED on TikTok but has NO settlement journal in DB!", "light_red");
                } elseif ($apiNet !== null && abs($apiNet - ($dbNet ?? 0)) > 1) {
                    CLI::write(sprintf("   [!] ISSUE: Net settlement mismatch! DB: %s vs API: %s (Diff: %s)",
                        number_format($dbNet ?? 0, 0, ',', '.'),
                        number_format($apiNet, 0, ',', '.'),
                        number_format(($dbNet ?? 0) - $apiNet, 0, ',', '.')
                    ), "light_red");
                }
            } elseif ($apiStatus === 'DELIVERED') {
                CLI::write("   [*] Info: Order DELIVERED on TikTok, waiting for buyer confirmation / auto-complete.", "yellow");
            } elseif (strpos($apiStatus, 'CANCEL') !== false) {
                if ($t['status'] !== 'CANCEL') {
                    CLI::write("   [!] ISSUE: Order is CANCEL on TikTok but DB status is " . $t['status'], "light_red");
                }
            }
            CLI::newLine();
        }
        return;

        // Query TikTok API for all recent orders in TikTok/Tokopedia
        CLI::write("--- Querying TikTok Shop Order Search API ---", "yellow");
        $controller = new TiktokController();
        try {
            $searchResp = $controller->makeTiktokRequest(1, 'POST', '/order/202507/orders/search', ['page_size' => 50, 'version' => '202507'], []);
            $apiOrders = $searchResp['data']['orders'] ?? [];
            CLI::write("TikTok API returned " . count($apiOrders) . " orders.", "green");
            foreach ($apiOrders as $ao) {
                $oId = $ao['id'];
                $oStatus = $ao['status'];
                $paidTime = !empty($ao['paid_time']) ? date('Y-m-d H:i', $ao['paid_time']) : 'N/A';
                
                // Check if in local DB
                $local = $db->table('transaction')->where('invoice', $oId)->get()->getRowArray();
                if ($local) {
                    CLI::write("  Order {$oId} | API Status: {$oStatus} | DB Status: {$local['status']} (ID: {$local['id']}) | Paid: {$paidTime}", "light_cyan");
                } else {
                    CLI::write("  Order {$oId} | API Status: {$oStatus} | NOT IN DB! | Paid: {$paidTime}", "light_red");
                }
            }
        } catch (\Throwable $e) {
            CLI::write("Error searching TikTok orders: " . $e->getMessage(), "red");
        }

        // Check transactions that have marketplace in meta
        $marketTrx = $db->query("
            SELECT t.id, t.invoice, t.status, t.actual_total, t.id_toko, tm.key, tm.value
            FROM transaction_meta tm
            JOIN transaction t ON t.id = tm.transaction_id
            WHERE tm.key IN ('marketplace', 'platform', 'shipping_status', 'tiktok_order_id')
               OR tm.value LIKE '%TIKTOK%'
               OR tm.value LIKE '%TOKOPEDIA%'
            LIMIT 30
        ")->getResultArray();
        CLI::write("--- Sample Meta Marketplace Transactions ---", "yellow");
        foreach ($marketTrx as $mt) {
            CLI::write("  Trx ID: {$mt['id']} | Inv: {$mt['invoice']} | Status: {$mt['status']} | Total: {$mt['actual_total']} | Meta: {$mt['key']}={$mt['value']}", "light_cyan");
        }
        $transactions = $db->query("
            SELECT t.id, t.invoice, t.status, t.actual_total, t.id_toko, t.created_at
            FROM transaction t
            WHERE t.invoice LIKE '585%' 
               OR t.invoice LIKE '57%'
               OR t.invoice LIKE '58%'
               OR LENGTH(t.invoice) >= 15
            ORDER BY t.id ASC
        ")->getResultArray();
        $count = count($transactions);
        CLI::write("Found {$count} marketplace transactions to process.", "cyan");

        if (empty($transactions)) {
            return;
        }

        $controller = new TiktokController();
        $issuesFound = 0;
        $fixedCount = 0;

        foreach ($transactions as $trx) {
            $trxId = $trx['id'];
            $invoice = $trx['invoice'];
            $status = $trx['status'];
            $actualTotal = (float)$trx['actual_total'];
            $idToko = $trx['id_toko'];

            // Inspect journals
            $journals = $db->table('journals')
                ->where('reference_no', $invoice)
                ->get()->getResultArray();

            $hasSettlement = false;
            $settleCashIssue = false;
            $settleAmountIssue = false;
            $settleDetails = [];

            foreach ($journals as $j) {
                if ($j['reference_type'] === 'SETTLEMENT') {
                    $hasSettlement = true;
                    $items = $db->table('journal_items')
                        ->where('journal_id', $j['id'])
                        ->get()->getResultArray();

                    foreach ($items as $item) {
                        if ((float)$item['debit'] > 0) {
                            if (substr($item['account_id'], -1) === '1' && strlen($item['account_id']) >= 4) {
                                $settleCashIssue = true;
                            }
                            if (abs((float)$item['debit'] - $actualTotal) < 0.01 && $actualTotal > 0) {
                                $settleAmountIssue = true;
                            }
                            $settleDetails[] = "Acc: {$item['account_id']} Dr: {$item['debit']} Cr: {$item['credit']}";
                        }
                    }
                }
            }

            $hasIssue = false;
            $issueDescriptions = [];

            if ($status === 'COMPLETED') {
                if (!$hasSettlement) {
                    $hasIssue = true;
                    $issueDescriptions[] = "COMPLETED but MISSING settlement journal";
                }
                if ($settleCashIssue) {
                    $hasIssue = true;
                    $issueDescriptions[] = "Settlement journal uses CASH instead of BANK";
                }
                if ($settleAmountIssue) {
                    $hasIssue = true;
                    $issueDescriptions[] = "Settlement journal amount equals full actual_total (fee not deducted)";
                }
            }

            CLI::write(sprintf(
                "[%d] Inv: %s | Status: %s | Total: %s | Toko: %d | Journals: %d | HasSettle: %s",
                $trxId,
                $invoice,
                $status,
                number_format($actualTotal, 0, ',', '.'),
                $idToko,
                count($journals),
                $hasSettlement ? 'YES' : 'NO'
            ), $hasIssue ? "red" : "cyan");

            foreach ($journals as $j) {
                $jItems = $db->table('journal_items')->where('journal_id', $j['id'])->get()->getResultArray();
                $jDetails = [];
                foreach ($jItems as $ji) {
                    $jDetails[] = "Acc {$ji['account_id']} (Dr: {$ji['debit']}, Cr: {$ji['credit']})";
                }
                CLI::write("   [Journal {$j['id']} {$j['reference_type']}] " . implode(' | ', $jDetails), "dark_gray");
            }

            if ($hasIssue || $targetId) {
                $issuesFound++;
                foreach ($issueDescriptions as $desc) {
                    CLI::write("   -> Issue: " . $desc, "light_red");
                }

                if (!$isDryRun) {
                    CLI::write("   -> Fixing transaction #{$trxId}...", "yellow");
                    try {
                        $controller->fixTiktokInvoice($trxId);
                        $fixedCount++;
                        CLI::write("   -> FIXED successfully!", "green");

                        // Show updated settlement info
                        $newJournals = $db->table('journals')
                            ->where('reference_no', $invoice)
                            ->where('reference_type', 'SETTLEMENT')
                            ->get()->getResultArray();
                        foreach ($newJournals as $nj) {
                            $newItems = $db->table('journal_items')
                                ->where('journal_id', $nj['id'])
                                ->get()->getResultArray();
                            $itemsStr = [];
                            foreach ($newItems as $ni) {
                                $itemsStr[] = "Acc: {$ni['account_id']} (Dr: {$ni['debit']}, Cr: {$ni['credit']})";
                            }
                            CLI::write("      New Settle: " . implode(' | ', $itemsStr), "light_cyan");
                        }
                    } catch (\Throwable $e) {
                        CLI::write("   -> ERROR: " . $e->getMessage(), "red");
                    }
                }
            }
        }

        CLI::newLine();
        CLI::write("=== Summary ===", "yellow");
        CLI::write("Total checked: {$count}", "white");
        CLI::write("Issues detected: {$issuesFound}", $issuesFound > 0 ? "light_red" : "green");
        if (!$isDryRun) {
            CLI::write("Successfully fixed: {$fixedCount}", "green");
        } else {
            CLI::write("Dry run mode: No changes made. Run without --dry-run to apply fixes.", "yellow");
        }
    }
}
