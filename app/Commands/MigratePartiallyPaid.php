<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class MigratePartiallyPaid extends BaseCommand
{
    protected $group = 'Migration';
    protected $name = 'migrate:partially-paid';
    protected $description = 'Migrate PARTIALLY_PAID transactions from old DB with DP payment logic';

    public function run(array $params)
    {
        $dbOld = Database::connect('old');
        $dbNew = Database::connect('default');

        // Pre-load account map
        $accRows = $dbNew->table('accounts')->where('tenant_id', 1)->get()->getResultArray();
        $accMap = [];
        foreach ($accRows as $r) {
            $accMap[$r['code']] = $r['id'];
        }

        CLI::write("Fetching PARTIALLY_PAID transactions from old DB...", 'yellow');

        $oldTransactions = $dbOld->query(
            "SELECT * FROM `transaction` WHERE status = 'PARTIALLY_PAID' ORDER BY id ASC"
        )->getResultArray();

        CLI::write("Found " . count($oldTransactions) . " transactions.", 'cyan');

        $now = date('Y-m-d H:i:s');

        foreach ($oldTransactions as $row) {
            $oldId = $row['id'];
            CLI::write("Processing ID: {$oldId} | {$row['invoice']}", 'cyan');

            // 1. Fetch Items
            $items = $dbOld->query("SELECT * FROM sales_product WHERE id_transaction = ?", [$oldId])->getResultArray();

            // 2. Fetch Meta
            $metaRows = $dbOld->query("SELECT * FROM transaction_meta WHERE transaction_id = ?", [$oldId])->getResultArray();
            $metaData = [];
            foreach ($metaRows as $m) {
                $metaData[$m['key']] = $m['value'];
            }

            // 3. Extract DP info from meta
            $totalDp = round((float)($metaData['total_dp'] ?? 0));
            $dpPaidAt = $metaData['partialy_paid_at'] ?? $row['date_time'] ?? $now;
            $metodeDp = $metaData['metode_pembayaran_dp'] ?? 'Cash';
            // Transfer = BANK (10x2), Cash = CASH (10x1)
            $paymentMethod = (strtolower($metodeDp) === 'transfer') ? 'TRANSFER' : 'CASH';

            // 4. Insert Transaction as PARTIALLY_PAID
            $newTr = [
                'tenant_id' => 1,
                'id_toko' => $row['id_toko'],
                'amount' => round((float)$row['amount']),
                'actual_total' => round((float)$row['actual_total']),
                'total_payment' => $totalDp, // DP amount as total_payment
                'total_modal' => round((float)($row['total_modal'] ?? 0)),
                'invoice' => $row['invoice'],
                'po' => $row['PO'] ?? 0,
                'status' => 'PARTIALLY_PAID',
                'delivery_status' => $row['delivery_status'] ?? 'NOT_READY',
                'discount_type' => 'FIXED',
                'discount_amount' => 0,
                'date_time' => $row['date_time'],
                'created_by' => $row['created_by'] ?? 1,
                'created_at' => $row['created_at'] ?? $row['date_time'] ?? $now,
            ];

            $dbNew->transStart();
            $dbNew->table('transaction')->insert($newTr);
            $newId = $dbNew->insertID();

            // 5. Insert Meta
            foreach ($metaData as $k => $v) {
                $dbNew->table('transaction_meta')->insert([
                    'transaction_id' => $newId,
                    'key' => $k,
                    'value' => (string)$v
                ]);
            }

            // 6. Process Items + deduct stock
            $cogsTotal = 0;
            foreach ($items as $item) {
                $sku = $item['kode_barang'];
                $qty = (int)$item['jumlah'];
                $valTotalModal = round((float)$item['total_modal']);
                $cogsTotal += $valTotalModal;

                $dbNew->table('sales_product')->insert([
                    'id_transaction' => $newId,
                    'tenant_id' => 1,
                    'kode_barang' => $sku,
                    'jumlah' => $qty,
                    'harga_system' => round((float)$item['harga_system']),
                    'harga_jual' => round((float)$item['harga_jual']),
                    'total' => round((float)$item['total']),
                    'modal_system' => round((float)$item['modal_system']),
                    'total_modal' => $valTotalModal,
                    'actual_per_piece' => round((float)$item['actual_per_piece']),
                    'actual_total' => round((float)$item['actual_total']),
                    'discount_type' => 'FIXED',
                    'discount_amount' => 0,
                ]);

                // Deduct stock (distribute-stock included pending in branch stock)
                $dbNew->query(
                    "UPDATE stock SET stock = stock - ? WHERE id_barang = ? AND id_toko = ? AND tenant_id = 1",
                    [$qty, $sku, $row['id_toko']]
                );

                // Stock Ledger
                $dbNew->table('stock_ledgers')->insert([
                    'tenant_id' => 1, 'id_barang' => $sku, 'id_toko' => $row['id_toko'],
                    'qty' => -$qty, 'balance' => 0,
                    'reference_type' => 'TRANSACTION', 'reference_id' => $newId,
                    'description' => "Migrasi: Penjualan PP - {$newTr['invoice']}",
                    'created_at' => $row['date_time']
                ]);
            }

            // 7. Sales & COGS Journals (same as WAITING_PAYMENT)
            $this->createSalesJournals($dbNew, $newId, $newTr, $row['id_toko'], $cogsTotal, $metaData, $accMap);

            // 8. DP Payment: insert payment record + journal
            if ($totalDp > 0) {
                $dbNew->table('transaction_payments')->insert([
                    'transaction_id' => $newId,
                    'amount' => $totalDp,
                    'payment_method' => $paymentMethod,
                    'status' => 'VERIFIED',
                    'paid_at' => $dpPaidAt,
                    'note' => "Migrasi DP dari old DB",
                    'created_at' => $dpPaidAt,
                ]);

                // Payment Journal: Dr Cash/Bank, Cr AR
                $storeId = $row['id_toko'];
                $accountCode = ($paymentMethod === 'CASH') ? '10' . $storeId . '1' : '10' . $storeId . '2';
                $dpDate = date('Y-m-d', strtotime($dpPaidAt));

                $jid = $this->insertJ($dbNew, 'PAYMENT', $newId, $newTr['invoice'], $dpDate, "DP Payment {$newTr['invoice']}", $storeId);
                $this->insertJIfast($dbNew, $jid, $accountCode, $totalDp, 0, $accMap); // Dr Cash/Bank
                $this->insertJIfast($dbNew, $jid, '10' . $storeId . '3', 0, $totalDp, $accMap); // Cr AR

                CLI::write("  💰 DP: Rp " . number_format($totalDp) . " via $paymentMethod @ $dpPaidAt", 'green');
            }

            $dbNew->transComplete();
        }

        CLI::write("Migration Complete! Processed: " . count($oldTransactions), 'green');
    }

    private function createSalesJournals($db, $trxId, $data, $storeId, $cogsTotal, $meta, $accMap)
    {
        $date = date('Y-m-d', strtotime($data['date_time']));
        $invoice = $data['invoice'];
        $grandTotal = (float)$data['actual_total'];
        $grossAmount = (float)$data['amount'];
        $ppnValue = (float)($meta['ppn_value'] ?? 0);
        $totalDiscount = $grossAmount - ($grandTotal - $ppnValue - (float)($meta['biaya_pengiriman'] ?? 0));

        // Sales Journal
        $jid = $this->insertJ($db, 'SALES', $trxId, $invoice, $date, "Invoice #$invoice", $storeId);
        $this->insertJIfast($db, $jid, '10' . $storeId . '3', $grandTotal, 0, $accMap); // AR
        if ($totalDiscount > 0)
            $this->insertJIfast($db, $jid, '40' . $storeId . '2', $totalDiscount, 0, $accMap); // Discount
        $this->insertJIfast($db, $jid, '40' . $storeId . '1', 0, $grossAmount, $accMap); // Sales
        if ($ppnValue > 0)
            $this->insertJIfast($db, $jid, '20' . $storeId . '5', 0, $ppnValue, $accMap); // Tax

        $shipping = (float)($meta['biaya_pengiriman'] ?? 0);
        if ($shipping > 0) {
            if (($meta['free_ongkir'] ?? '0') === '1') {
                $this->insertJIfast($db, $jid, '50' . $storeId . '6', $shipping, 0, $accMap);
                $this->insertJIfast($db, $jid, '20' . $storeId . '1', 0, $shipping, $accMap);
            }
            else {
                $this->insertJIfast($db, $jid, '40' . $storeId . '1', 0, $shipping, $accMap);
            }
        }

        // COGS Journal
        if ($cogsTotal > 0) {
            $cjid = $this->insertJ($db, 'COGS', $trxId, $invoice, $date, "COGS Invoice $invoice", $storeId);
            $this->insertJIfast($db, $cjid, '50' . $storeId . '1', $cogsTotal, 0, $accMap); // COGS
            $this->insertJIfast($db, $cjid, '10' . $storeId . '4', 0, $cogsTotal, $accMap); // Inventory
        }
    }

    private function insertJ($db, $type, $refId, $refNo, $date, $desc, $sid)
    {
        $db->table('journals')->insert([
            'tenant_id' => 1, 'id_toko' => $sid, 'reference_type' => $type,
            'reference_id' => $refId, 'reference_no' => $refNo, 'date' => $date,
            'description' => $desc, 'created_at' => date('Y-m-d H:i:s')
        ]);
        return $db->insertID();
    }

    // Fast version using pre-loaded accMap instead of querying per item
    private function insertJIfast($db, $jid, $accCode, $dbVal, $crVal, $accMap)
    {
        if (isset($accMap[$accCode])) {
            $db->table('journal_items')->insert([
                'journal_id' => $jid, 'account_id' => $accMap[$accCode],
                'debit' => $dbVal, 'credit' => $crVal, 'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
