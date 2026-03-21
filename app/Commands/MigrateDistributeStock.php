<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class MigrateDistributeStock extends BaseCommand
{
    protected $group = 'Migration';
    protected $name = 'migrate:distribute-stock';
    protected $description = 'Finalize Master Stock and Distribute to Stores with Journals';

    public function run(array $params)
    {
        $dbOld = Database::connect('old');
        $dbNew = Database::connect('default');

        CLI::write("Stage 1: Calculation & Cleanup...", 'yellow');
        
        // Fetch distribution from OLD DB
        $sqlStock = "SELECT id_barang, id_toko, SUM(stock) as normal, SUM(barang_cacat) as cacat FROM stock GROUP BY id_barang, id_toko";
        $sqlPending = "SELECT sp.kode_barang as id_barang, t.id_toko, SUM(sp.jumlah) as pending FROM sales_product sp JOIN transaction t ON t.id = sp.id_transaction WHERE t.status = 'WAITING_PAYMENT' GROUP BY sp.kode_barang, t.id_toko";
        $sqlProducts = "SELECT id_barang, harga_modal, harga_jual FROM product WHERE deleted_at IS NULL";

        $oldStockDist = $dbOld->query($sqlStock)->getResultArray();
        $oldPendingDist = $dbOld->query($sqlPending)->getResultArray();
        $products = $dbOld->query($sqlProducts)->getResultArray();

        $distMap = [];
        $productMaster = [];

        foreach ($oldStockDist as $row) {
            $qty = (int)$row['normal'] + (int)$row['cacat'];
            if ($qty > 0) $distMap[$row['id_barang']][$row['id_toko']] = ($distMap[$row['id_barang']][$row['id_toko']] ?? 0) + $qty;
        }
        foreach ($oldPendingDist as $row) {
            $qty = (int)$row['pending'];
            if ($qty > 0) $distMap[$row['id_barang']][$row['id_toko']] = ($distMap[$row['id_barang']][$row['id_toko']] ?? 0) + $qty;
        }
        foreach ($products as $p) {
            $productMaster[$p['id_barang']] = ['harga_modal' => (float)$p['harga_modal'], 'harga_jual' => (float)$p['harga_jual']];
        }

        // Clean up current stock/ledgers/journals to avoid double-ups
        CLI::write("Cleaning Store 1, 2, 3 stock and journals...", 'red');
        $dbNew->query('SET FOREIGN_KEY_CHECKS=0');
        $dbNew->query('DELETE FROM journal_items');
        $dbNew->query('DELETE FROM journals WHERE tenant_id = 1');
        $dbNew->query('DELETE FROM stock_ledgers WHERE tenant_id = 1');
        $dbNew->query('DELETE FROM stock WHERE tenant_id = 1');
        $dbNew->query('SET FOREIGN_KEY_CHECKS=1');

        $idTokoMaster = 3;
        $now = date('Y-m-d H:i:s');
        $date = date('Y-m-d');

        CLI::write("Stage 2: Processing Distribution & Journals...", 'yellow');
        
        $stocks = [];
        $ledgers = [];
        $journals = [];
        $journal_items = [];

        // 1. Fetch the Purchase record #396 or similar
        $pembelian = $dbNew->table('pembelian')->where('id_toko', $idTokoMaster)->orderBy('id', 'DESC')->get()->getRowArray();
        $pembelianId = $pembelian ? $pembelian['id'] : 0;
        
        if ($pembelianId) {
            $dbNew->table('pembelian')->where('id', $pembelianId)->update(['status' => 'SUCCESS']);
        }

        foreach ($distMap as $kode => $stores) {
            $totalQtyProd = array_sum($stores);
            $pInfo = $productMaster[$kode] ?? ['harga_modal' => 0, 'harga_jual' => 0];
            $itemModal = $pInfo['harga_modal'];

            // Initial Master Entry (PURCHASE)
            $stocks[$kode][$idTokoMaster] = $totalQtyProd;
            $ledgers[] = [
                'tenant_id' => 1, 'id_barang' => $kode, 'id_toko' => $idTokoMaster,
                'qty' => $totalQtyProd, 'balance' => $totalQtyProd,
                'reference_type' => 'PURCHASE', 'reference_id' => $pembelianId,
                'description' => "Migrasi: Stok Awal Master", 'created_at' => $now
            ];

            // Distribution
            $runningBalanceMaster = $totalQtyProd;
            foreach ($stores as $tokoId => $qty) {
                if ($tokoId == $idTokoMaster) continue;

                $refId = "TRF-MIG-" . date('ymd') . "-" . substr(md5($kode . $tokoId), 0, 8);
                $itemValue = $itemModal * $qty;

                $runningBalanceMaster -= $qty;
                $stocks[$kode][$tokoId] = $qty;

                // Ledger Out Master
                $ledgers[] = [
                    'tenant_id' => 1, 'id_barang' => $kode, 'id_toko' => $idTokoMaster,
                    'qty' => -$qty, 'balance' => $runningBalanceMaster,
                    'reference_type' => 'TRANSFER_OUT', 'reference_id' => $refId,
                    'description' => "Migrasi: Transfer ke Toko $tokoId", 'created_at' => $now
                ];

                // Ledger In Target
                $ledgers[] = [
                    'tenant_id' => 1, 'id_barang' => $kode, 'id_toko' => $tokoId,
                    'qty' => $qty, 'balance' => $qty,
                    'reference_type' => 'TRANSFER_IN', 'reference_id' => $refId,
                    'description' => "Migrasi: Terima dari Master", 'created_at' => $now
                ];

                // Journals (Follow InventoryController Logic)
                $journals[] = [
                    'tenant_id' => 1, 'id_toko' => $idTokoMaster, 'reference_type' => 'TRANSFER_OUT',
                    'reference_id' => $refId, 'reference_no' => $refId, 'date' => $date,
                    'description' => "Inventory Out ($kode) -> $tokoId", 'created_at' => $now,
                    '_items' => [
                        ['code' => '10' . $tokoId . '4', 'db' => $itemValue, 'cr' => 0],
                        ['code' => '10' . $idTokoMaster . '4', 'db' => 0, 'cr' => $itemValue]
                    ]
                ];
                $journals[] = [
                    'tenant_id' => 1, 'id_toko' => $tokoId, 'reference_type' => 'TRANSFER_IN',
                    'reference_id' => $refId, 'reference_no' => $refId, 'date' => $date,
                    'description' => "Inventory In ($kode) <- Master", 'created_at' => $now,
                    '_items' => [
                        ['code' => '10' . $tokoId . '4', 'db' => $itemValue, 'cr' => 0],
                        ['code' => '30' . $idTokoMaster . '1', 'db' => 0, 'cr' => $itemValue]
                    ]
                ];
            }
            $stocks[$kode][$idTokoMaster] = $runningBalanceMaster;
        }

        CLI::write("Stage 3: Inserting Collections...", 'yellow');
        $dbNew->transStart();

        // 1. Stocks
        $stockInserts = [];
        foreach ($stocks as $kode => $stores) {
            foreach ($stores as $tid => $qty) {
                $stockInserts[] = ['tenant_id' => 1, 'id_barang' => $kode, 'id_toko' => $tid, 'stock' => $qty, 'barang_cacat' => 0];
            }
        }
        $this->batchInsert($dbNew, 'stock', $stockInserts);

        // 2. Ledgers
        $this->batchInsert($dbNew, 'stock_ledgers', $ledgers, 300);

        // 3. Journals
        $accRows = $dbNew->table('accounts')->get()->getResultArray();
        $accMap = []; foreach ($accRows as $r) $accMap[$r['code']] = $r['id'];

        foreach ($journals as $j) {
            $_items = $j['_items'];
            unset($j['_items']);
            $dbNew->table('journals')->insert($j);
            $jid = $dbNew->insertID();
            foreach ($_items as $item) {
                if (isset($accMap[$item['code']])) {
                    $journal_items[] = [
                        'journal_id' => $jid, 'account_id' => $accMap[$item['code']],
                        'debit' => $item['db'], 'credit' => $item['cr'], 'created_at' => $now
                    ];
                }
            }
        }
        $this->batchInsert($dbNew, 'journal_items', $journal_items, 500);

        $dbNew->transComplete();
        CLI::write("Distribution Complete!", 'green');
    }

    private function batchInsert($db, $table, $data, $size = 200) {
        if (empty($data)) return;
        $chunks = array_chunk($data, $size);
        foreach ($chunks as $chunk) $db->table($table)->insertBatch($chunk);
    }
}
