<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class MigrateTransactions extends BaseCommand
{
    protected $group = 'Migration';
    protected $name = 'migrate:transactions';
    protected $description = 'Migrate ONLY WAITING_PAYMENT transactions from old DB to new DB with V2 logic';

    public function run(array $params)
    {
        $dbOld = Database::connect('old');
        $dbNew = Database::connect('default');

        CLI::write("Stage 1: Cleanup Existing Tenant 1 Transactions...", 'yellow');
        $dbNew->query('SET FOREIGN_KEY_CHECKS=0');
        
        $tid = 1;
        // Targeted wipe of transaction related tables for current tenant
        $dbNew->table('transaction')->where('tenant_id', $tid)->delete();
        $dbNew->table('transaction_meta')->whereIn('transaction_id', function($q) use ($tid) {
            return $q->select('id')->from('transaction')->where('tenant_id', $tid);
        })->delete();
        $dbNew->table('sales_product')->whereIn('id_transaction', function($q) use ($tid) {
            return $q->select('id')->from('transaction')->where('tenant_id', $tid);
        })->delete();
        
        // Wipe migration-specific journals for this tenant
        $dbNew->table('journals')->where('tenant_id', $tid)->whereIn('reference_type', ['SALES', 'COGS'])->delete();
        // Missing journal items will likely be cleaned by cascade or we cleanup journal_items not linked to a journal later?
        // Actually CI4 query builder delete doesn't always cascade if using simple table calls.
        // We'll just be thorough.
        
        $dbNew->query('SET FOREIGN_KEY_CHECKS=1');

        CLI::write("Stage 2: Fetching Old WAITING_PAYMENT Transactions...", 'yellow');
        
        $sqlTr = "SELECT * FROM `transaction` WHERE status = 'WAITING_PAYMENT' ORDER BY id ASC";
        $oldTransactions = $dbOld->query($sqlTr)->getResultArray();
        
        CLI::write("Stage 3: Processing...", 'yellow');
        $now = date('Y-m-d H:i:s');
        
        foreach ($oldTransactions as $row) {
            $oldId = $row['id'];
            CLI::write("Processing Trx ID: {$oldId} | {$row['invoice']}", 'cyan');

            // 1. Fetch Items
            $sqlItems = "SELECT * FROM sales_product WHERE id_transaction = ?";
            $items = $dbOld->query($sqlItems, [$oldId])->getResultArray();

            // 2. Fetch Meta
            $sqlMeta = "SELECT * FROM transaction_meta WHERE transaction_id = ?";
            $metaRows = $dbOld->query($sqlMeta, [$oldId])->getResultArray();
            $metaData = [];
            foreach ($metaRows as $m) $metaData[$m['key']] = $m['value'];

            // 3. Insert Transaction
            $newTr = [
                'tenant_id' => 1,
                'id_toko' => $row['id_toko'],
                'amount' => (float)$row['amount'],
                'actual_total' => (float)$row['actual_total'],
                'total_payment' => (float)$row['total_payment'],
                'total_modal' => (float)($row['total_modal'] ?? 0),
                'invoice' => $row['invoice'],
                'po' => $row['PO'] ?? 0,
                'status' => 'WAITING_PAYMENT',
                'delivery_status' => $row['delivery_status'] ?? 'NOT_READY',
                'discount_type' => 'FIXED',
                'discount_amount' => 0, // Old DB doesn't have these columns
                'date_time' => $row['date_time'],
                'created_by' => $row['created_by'] ?? 1,
                'created_at' => $row['created_at'] ?? $row['date_time'] ?? $now,
            ];

            $dbNew->transStart();
            $dbNew->table('transaction')->insert($newTr);
            $newId = $dbNew->insertID();

            // 4. Insert Meta
            foreach ($metaData as $k => $v) {
                $dbNew->table('transaction_meta')->insert([
                    'transaction_id' => $newId,
                    'key' => $k,
                    'value' => (string)$v
                ]);
            }

            // 5. Process Items
            $cogsTotal = 0;
            foreach ($items as $item) {
                $sku = $item['kode_barang'];
                $qty = (int)$item['jumlah'];
                $valTotalModal = (float)$item['total_modal'];
                $cogsTotal += $valTotalModal;

                $dbNew->table('sales_product')->insert([
                    'id_transaction' => $newId,
                    'kode_barang' => $sku,
                    'jumlah' => $qty,
                    'harga_system' => (float)$item['harga_system'],
                    'harga_jual' => (float)$item['harga_jual'],
                    'total' => (float)$item['total'],
                    'modal_system' => (float)$item['modal_system'],
                    'total_modal' => $valTotalModal,
                    'actual_per_piece' => (float)$item['actual_per_piece'],
                    'actual_total' => (float)$item['actual_total'],
                    'discount_type' => 'FIXED',
                    'discount_amount' => 0,
                ]);

                // Deduction logic: We MUST deduct stock because distribute-stock included these in the 'stock' column.
                $dbNew->query("UPDATE stock SET stock = stock - ? WHERE id_barang = ? AND id_toko = ? AND tenant_id = 1", 
                              [$qty, $sku, $row['id_toko']]);

                // Stock Ledger for audit
                $dbNew->table('stock_ledgers')->insert([
                    'tenant_id' => 1, 'id_barang' => $sku, 'id_toko' => $row['id_toko'],
                    'qty' => -$qty, 'balance' => 0, 
                    'reference_type' => 'TRANSACTION', 'reference_id' => $newId,
                    'description' => "Migrasi: Penjualan WP - {$newTr['invoice']}", 
                    'created_at' => $row['date_time']
                ]);
            }

            // 6. Accounting Journals (Sales & COGS)
            $this->createJournals($dbNew, $newId, $newTr, $row['id_toko'], $cogsTotal, $metaData);

            $dbNew->transComplete();
        }

        CLI::write("Migration Complete!", 'green');
    }

    private function createJournals($db, $trxId, $data, $storeId, $cogsTotal, $meta)
    {
        $date = date('Y-m-d', strtotime($data['date_time']));
        $invoice = $data['invoice'];
        $grandTotal = (float)$data['actual_total'];
        $grossAmount = (float)$data['amount'];
        $ppnValue = (float)($meta['ppn_value'] ?? 0);
        $totalDiscount = $grossAmount - ($grandTotal - $ppnValue - (float)($meta['biaya_pengiriman'] ?? 0));

        // Sales Journal
        $jid = $this->insertJ($db, 'SALES', $trxId, $invoice, $date, "Invoice #$invoice", $storeId);
        $this->insertJI($db, $jid, '10' . $storeId . '3', $grandTotal, 0); // AR
        if ($totalDiscount > 0) $this->insertJI($db, $jid, '40' . $storeId . '2', $totalDiscount, 0); // Discount
        $this->insertJI($db, $jid, '40' . $storeId . '1', 0, $grossAmount); // Sales
        if ($ppnValue > 0) $this->insertJI($db, $jid, '20' . $storeId . '5', 0, $ppnValue); // Tax
        
        $shipping = (float)($meta['biaya_pengiriman'] ?? 0);
        if ($shipping > 0) {
            if (($meta['free_ongkir'] ?? '0') === '1') {
                $this->insertJI($db, $jid, '50' . $storeId . '6', $shipping, 0);
                $this->insertJI($db, $jid, '20' . $storeId . '1', 0, $shipping);
            } else {
                $this->insertJI($db, $jid, '40' . $storeId . '1', 0, $shipping);
            }
        }

        // COGS Journal
        if ($cogsTotal > 0) {
            $cjid = $this->insertJ($db, 'COGS', $trxId, $invoice, $date, "COGS Invoice $invoice", $storeId);
            $this->insertJI($db, $cjid, '50' . $storeId . '1', $cogsTotal, 0); // COGS
            $this->insertJI($db, $cjid, '10' . $storeId . '4', 0, $cogsTotal); // Inventory
        }
    }

    private function insertJ($db, $type, $refId, $refNo, $date, $desc, $sid) {
        $db->table('journals')->insert([
            'tenant_id' => 1, 'id_toko' => $sid, 'reference_type' => $type,
            'reference_id' => $refId, 'reference_no' => $refNo, 'date' => $date,
            'description' => $desc, 'created_at' => date('Y-m-d H:i:s')
        ]);
        return $db->insertID();
    }

    private function insertJI($db, $jid, $accCode, $dbVal, $crVal) {
        $acc = $db->table('accounts')->where('tenant_id', 1)->where('code', $accCode)->get()->getRowArray();
        if ($acc) {
            $db->table('journal_items')->insert([
                'journal_id' => $jid, 'account_id' => $acc['id'],
                'debit' => $dbVal, 'credit' => $crVal, 'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
