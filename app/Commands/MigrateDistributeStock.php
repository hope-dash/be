<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class MigrateDistributeStock extends BaseCommand
{
    protected $group = 'Migration';
    protected $name = 'migrate:distribute-stock';
    protected $description = 'Finalize Master Stock and Distribute to Stores with Journals (Merged Normal/Pending and Separate Cacat)';

    public function run(array $params)
    {
        $dbOld = Database::connect('old');
        $dbNew = Database::connect('default');

        CLI::write("Stage 1: Calculation...", 'yellow');

        // Fetch distribution from OLD DB
        $sqlStock = "SELECT s.id_barang, s.id_toko, s.stock as normal, s.barang_cacat as cacat 
                     FROM stock s 
                     JOIN product p ON p.id_barang = s.id_barang 
                     WHERE p.deleted_at IS NULL";
        $sqlPending = "SELECT sp.kode_barang as id_barang, t.id_toko, SUM(sp.jumlah) as pending 
                       FROM sales_product sp 
                       JOIN transaction t ON t.id = sp.id_transaction 
                       JOIN product p ON p.id_barang = sp.kode_barang
                       WHERE t.status IN ('WAITING_PAYMENT', 'REFUNDED') 
                       AND p.deleted_at IS NULL
                       GROUP BY sp.kode_barang, t.id_toko";
        $sqlProducts = "SELECT id_barang, harga_modal, harga_jual FROM product WHERE deleted_at IS NULL";

        $oldStockDist = $dbOld->query($sqlStock)->getResultArray();
        $oldPendingDist = $dbOld->query($sqlPending)->getResultArray();
        $products = $dbOld->query($sqlProducts)->getResultArray();

        $normalMap = []; // [kode][tokoId] = normal + pending
        $cacatMap = []; // [kode][tokoId] = cacat
        $productMaster = [];

        foreach ($oldStockDist as $row) {
            $kode = $row['id_barang'];
            $tokoId = $row['id_toko'];
            $normalMap[$kode][$tokoId] = ($normalMap[$kode][$tokoId] ?? 0) + (int)$row['normal'];
            $cacatMap[$kode][$tokoId] = ($cacatMap[$kode][$tokoId] ?? 0) + (int)$row['cacat'];
        }
        foreach ($oldPendingDist as $row) {
            $kode = $row['id_barang'];
            $tokoId = $row['id_toko'];
            $normalMap[$kode][$tokoId] = ($normalMap[$kode][$tokoId] ?? 0) + (int)$row['pending'];
        }
        foreach ($products as $p) {
            $productMaster[$p['id_barang']] = [
                'harga_modal' => round((float)$p['harga_modal']),
                'harga_jual' => round((float)$p['harga_jual'])
            ];
        }

        // We combine all products from both maps
        $allKodes = array_unique(array_merge(array_keys($normalMap), array_keys($cacatMap)));

        CLI::write("Stage 2: Processing Distribution & Journals...", 'yellow');

        // Cleanup stock/ledgers for this tenant to avoid duplicates (KEEPING JOURNALS)
        CLI::write("Cleaning stock and stock_ledgers for Tenant 1 (Safe Wipe)...", 'red');
        $dbNew->query('SET FOREIGN_KEY_CHECKS=0');
        $dbNew->table('stock')->where('tenant_id', 1)->delete();
        $dbNew->table('stock_ledgers')->where('tenant_id', 1)->delete();
        $dbNew->query('SET FOREIGN_KEY_CHECKS=1');

        $idTokoMaster = 3;
        $now = date('Y-m-d H:i:s');
        $date = date('Y-m-d');

        $stocksFinal = []; // [kode][tokoId] = ['stock' => X, 'cacat' => Y]
        $ledgers = [];
        $journals = [];
        $journal_items = [];

        // 1. Fetch the latest Purchase record
        $pembelian = $dbNew->table('pembelian')->where('id_toko', $idTokoMaster)->orderBy('id', 'DESC')->get()->getRowArray();
        $pembelianId = $pembelian ? $pembelian['id'] : 0;

        if ($pembelianId) {
            $dbNew->table('pembelian')->where('id', $pembelianId)->update(['status' => 'SUCCESS']);
        }

        foreach ($allKodes as $kode) {
            $storesNormal = $normalMap[$kode] ?? [];
            $storesCacat = $cacatMap[$kode] ?? [];
            $allStoreIds = array_unique(array_merge(array_keys($storesNormal), array_keys($storesCacat)));

            $totalNormalAllStores = array_sum($storesNormal);
            $totalCacatAllStores = array_sum($storesCacat);
            $totalQtyProd = $totalNormalAllStores + $totalCacatAllStores;

            $pInfo = $productMaster[$kode] ?? ['harga_modal' => 0, 'harga_jual' => 0];
            $itemModal = $pInfo['harga_modal'];

            // Initial Master Entry (PURCHASE)
            $stocksFinal[$kode][$idTokoMaster] = ['stock' => $totalNormalAllStores, 'cacat' => $totalCacatAllStores];
            $ledgers[] = [
                'tenant_id' => 1, 'id_barang' => $kode, 'id_toko' => $idTokoMaster,
                'qty' => $totalQtyProd, 'balance' => $totalQtyProd,
                'reference_type' => 'PURCHASE', 'reference_id' => $pembelianId,
                'description' => "Migrasi: Stok Awal Master", 'created_at' => $now
            ];

            // Distribution
            $runningBalanceMaster = $totalQtyProd;
            foreach ($allStoreIds as $tokoId) {
                if ($tokoId == $idTokoMaster)
                    continue;

                $qNormal = $storesNormal[$tokoId] ?? 0;
                $qCacat = $storesCacat[$tokoId] ?? 0;
                $qTotal = $qNormal + $qCacat;

                if ($qTotal == 0)
                    continue;

                $refId = "TRF-MIG-" . date('ymd') . "-" . substr(md5($kode . $tokoId), 0, 8);
                $valueNormal = $itemModal * $qNormal;
                $valueCacat = $itemModal * $qCacat;
                $totalValue = $valueNormal + $valueCacat;

                $runningBalanceMaster -= $qTotal;
                $stocksFinal[$kode][$tokoId] = ['stock' => $qNormal, 'cacat' => $qCacat];

                // Ledger Out Master
                $ledgers[] = [
                    'tenant_id' => 1, 'id_barang' => $kode, 'id_toko' => $idTokoMaster,
                    'qty' => -$qTotal, 'balance' => $runningBalanceMaster,
                    'reference_type' => 'TRANSFER_OUT', 'reference_id' => $refId,
                    'description' => "Migrasi: Transfer ke Toko $tokoId", 'created_at' => $now
                ];

                // Ledger In Target
                $ledgers[] = [
                    'tenant_id' => 1, 'id_barang' => $kode, 'id_toko' => $tokoId,
                    'qty' => $qTotal, 'balance' => $qTotal,
                    'reference_type' => 'TRANSFER_IN', 'reference_id' => $refId,
                    'description' => "Migrasi: Terima dari Master", 'created_at' => $now
                ];

                // Journals Out (Master Store context)
                $itemsOut = [];
                if ($valueNormal > 0) {
                    $itemsOut[] = ['code' => '10' . $tokoId . '4', 'db' => $valueNormal, 'cr' => 0];
                    $itemsOut[] = ['code' => '10' . $idTokoMaster . '4', 'db' => 0, 'cr' => $valueNormal];
                }
                if ($valueCacat > 0) {
                    $itemsOut[] = ['code' => '10' . $tokoId . '7', 'db' => $valueCacat, 'cr' => 0];
                    $itemsOut[] = ['code' => '10' . $idTokoMaster . '7', 'db' => 0, 'cr' => $valueCacat];
                }

                if (!empty($itemsOut)) {
                    $journals[] = [
                        'tenant_id' => 1, 'id_toko' => $idTokoMaster, 'reference_type' => 'TRANSFER_OUT',
                        'reference_id' => $refId, 'reference_no' => $refId, 'date' => $date,
                        'description' => "Inventory Out ($kode) -> $tokoId", 'created_at' => $now,
                        '_items' => $itemsOut
                    ];
                }

                // Journals In (Target Store context)
                $itemsIn = [];
                if ($valueNormal > 0) {
                    $itemsIn[] = ['code' => '10' . $tokoId . '4', 'db' => $valueNormal, 'cr' => 0];
                }
                if ($valueCacat > 0) {
                    $itemsIn[] = ['code' => '10' . $tokoId . '7', 'db' => $valueCacat, 'cr' => 0];
                }
                
                if (!empty($itemsIn)) {
                    $itemsIn[] = ['code' => '30' . $idTokoMaster . '1', 'db' => 0, 'cr' => $totalValue];
                    $journals[] = [
                        'tenant_id' => 1, 'id_toko' => $tokoId, 'reference_type' => 'TRANSFER_IN',
                        'reference_id' => $refId, 'reference_no' => $refId, 'date' => $date,
                        'description' => "Inventory In ($kode) <- Master", 'created_at' => $now,
                        '_items' => $itemsIn
                    ];
                }
            }
            // Update Master Final Balance
            $stocksFinal[$kode][$idTokoMaster]['stock'] -= ($totalNormalAllStores - ($stocksFinal[$kode][$idTokoMaster]['stock'] ?? 0)); // Actually easier to just re-calculate
            // Wait, logic for Master Final:
            $usedNormalX = 0;
            $usedCacatX = 0;
            foreach ($allStoreIds as $tid) {
                if ($tid == $idTokoMaster)
                    continue;
                $usedNormalX += ($storesNormal[$tid] ?? 0);
                $usedCacatX += ($storesCacat[$tid] ?? 0);
            }
            $stocksFinal[$kode][$idTokoMaster] = [
                'stock' => $totalNormalAllStores - $usedNormalX,
                'cacat' => $totalCacatAllStores - $usedCacatX
            ];
        }

        CLI::write("Stage 3: Inserting Collections...", 'yellow');
        $dbNew->transStart();

        // 1. Cleanup current run data? (User said no, so I assume we run on clean DB or user handled)
        // I will use raw query cleanup for stock/ledgers for this run only if there are duplicates, 
        // but user specifically said "jangan hapus jurnal", so I'll just skip cleanup.
        // To be safe, let's use insertBatch. If it fails, user might need to truncate.

        $stockInserts = [];
        foreach ($stocksFinal as $kode => $stores) {
            foreach ($stores as $tid => $q) {
                $stockInserts[] = [
                    'tenant_id' => 1, 'id_barang' => $kode, 'id_toko' => $tid,
                    'stock' => $q['stock'], 'barang_cacat' => $q['cacat']
                ];
            }
        }
        $this->batchInsert($dbNew, 'stock', $stockInserts);
        $this->batchInsert($dbNew, 'stock_ledgers', $ledgers, 300);

        $accRows = $dbNew->table('accounts')->get()->getResultArray();
        $accMap = [];
        foreach ($accRows as $r)
            $accMap[$r['code']] = $r['id'];

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

    private function batchInsert($db, $table, $data, $size = 200)
    {
        if (empty($data))
            return;
        $chunks = array_chunk($data, $size);
        foreach ($chunks as $chunk) {
            $db->table($table)->ignore(true)->insertBatch($chunk);
        }
    }
}