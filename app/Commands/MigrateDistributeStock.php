<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class MigrateDistributeStock extends BaseCommand
{
    protected $group = 'Migration';
    protected $name = 'migrate:distribute-stock';
    protected $description = 'Distribute stock from Master Toko (ID 3) to branches based on OLD DB stock per product';

    public function run(array $params)
    {
        $dbOld = Database::connect('old');
        $dbNew = Database::connect('default');

        $idTokoMaster = 3;
        $tenantId = 1;
        $now = date('Y-m-d H:i:s');
        $date = date('Y-m-d');

        // ─────────────────────────────────────────────────────────
        // STAGE 1: Bulk-load everything into memory
        // ─────────────────────────────────────────────────────────
        CLI::write("Stage 1: Bulk-loading data...", 'yellow');

        // 1a. Products from NEW DB (source of truth)
        $products = $dbNew->query(
            "SELECT id_barang, harga_modal FROM product WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenantId]
        )->getResultArray();

        $productMap = [];
        foreach ($products as $p) {
            $productMap[$p['id_barang']] = round((float)$p['harga_modal']);
        }
        CLI::write("  Products: " . count($productMap), 'cyan');

        // 1b. OLD DB stock (all branches, exclude master id 3)
        $oldStocks = $dbOld->query(
            "SELECT id_barang, id_toko, stock, barang_cacat FROM stock WHERE id_toko != 3"
        )->getResultArray();

        $oldStockByKode = [];
        foreach ($oldStocks as $row) {
            $k = $row['id_barang'];
            $t = (int)$row['id_toko'];
            $oldStockByKode[$k][$t]['normal'] = ($oldStockByKode[$k][$t]['normal'] ?? 0) + (int)$row['stock'];
            $oldStockByKode[$k][$t]['cacat'] = ($oldStockByKode[$k][$t]['cacat'] ?? 0) + (int)$row['barang_cacat'];
        }
        CLI::write("  Old stock rows: " . count($oldStocks), 'cyan');

        // 1c. OLD DB WAITING_PAYMENT pending per product per branch
        $oldPending = $dbOld->query(
            "SELECT sp.kode_barang, t.id_toko, SUM(sp.jumlah) as pending
             FROM sales_product sp
             JOIN `transaction` t ON t.id = sp.id_transaction
             WHERE t.status = 'WAITING_PAYMENT'
               AND t.id_toko != 3
             GROUP BY sp.kode_barang, t.id_toko"
        )->getResultArray();

        $pendingByKode = [];
        foreach ($oldPending as $row) {
            $k = $row['kode_barang'];
            $t = (int)$row['id_toko'];
            $pendingByKode[$k][$t] = ($pendingByKode[$k][$t] ?? 0) + (int)$row['pending'];
        }
        CLI::write("  Pending rows: " . count($oldPending), 'cyan');

        // 1d. NEW DB master stocks (id_toko = 3)
        $masterStockRows = $dbNew->query(
            "SELECT id, id_barang, stock, barang_cacat FROM stock WHERE id_toko = ? AND tenant_id = ?",
        [$idTokoMaster, $tenantId]
        )->getResultArray();

        $masterStockMap = [];
        foreach ($masterStockRows as $row) {
            $masterStockMap[$row['id_barang']] = [
                'id' => $row['id'],
                'normal' => (int)$row['stock'],
                'cacat' => (int)$row['barang_cacat'],
            ];
        }
        CLI::write("  Master stock rows: " . count($masterStockMap), 'cyan');

        // 1e. NEW DB existing branch stocks (id_toko != 3)
        $branchStockRows = $dbNew->query(
            "SELECT id, id_barang, id_toko, stock, barang_cacat FROM stock WHERE id_toko != ? AND tenant_id = ?",
        [$idTokoMaster, $tenantId]
        )->getResultArray();

        $branchStockMap = [];
        foreach ($branchStockRows as $row) {
            $branchStockMap[$row['id_barang']][(int)$row['id_toko']] = [
                'id' => $row['id'],
                'normal' => (int)$row['stock'],
                'cacat' => (int)$row['barang_cacat'],
            ];
        }
        CLI::write("  Branch stock rows: " . count($branchStockRows), 'cyan');

        // 1f. Account map
        $accRows = $dbNew->table('accounts')->get()->getResultArray();
        $accMap = [];
        foreach ($accRows as $r) {
            $accMap[$r['code']] = $r['id'];
        }

        // ─────────────────────────────────────────────────────────
        // STAGE 2: Process distributions in memory
        // ─────────────────────────────────────────────────────────
        CLI::write("Stage 2: Computing distributions...", 'yellow');

        $stockUpdates = []; // [id => [stock, barang_cacat]]
        $stockInserts = [];
        $ledgerInserts = [];
        $journalQueue = []; // journals + items to insert sequentially

        $totalTransferred = 0;
        $skipped = 0;

        foreach ($productMap as $kode => $itemModal) {
            $branches = $oldStockByKode[$kode] ?? [];

            // Merge pending into branch map
            foreach (($pendingByKode[$kode] ?? []) as $tid => $pending) {
                $branches[$tid]['normal'] = ($branches[$tid]['normal'] ?? 0) + $pending;
                $branches[$tid]['cacat'] = $branches[$tid]['cacat'] ?? 0;
            }

            if (empty($branches)) {
                $skipped++;
                continue;
            }

            if (!isset($masterStockMap[$kode])) {
                $skipped++;
                continue;
            }

            $masterNormal = $masterStockMap[$kode]['normal'];
            $masterCacat = $masterStockMap[$kode]['cacat'];
            $masterId = $masterStockMap[$kode]['id'];

            foreach ($branches as $tokoId => $qty) {
                $qNormal = $qty['normal'];
                $qCacat = $qty['cacat'];
                $qTotal = $qNormal + $qCacat;

                if ($qTotal <= 0)
                    continue;

                // Cap to what master has
                if ($qTotal > ($masterNormal + $masterCacat)) {
                    $qNormal = min($qNormal, $masterNormal);
                    $qCacat = min($qCacat, $masterCacat);
                    $qTotal = $qNormal + $qCacat;
                    if ($qTotal <= 0)
                        continue;
                }

                $refId = 'TRF-MIG-' . date('ymd') . '-' . substr(md5($kode . $tokoId), 0, 8);
                $valueNormal = $itemModal * $qNormal;
                $valueCacat = $itemModal * $qCacat;
                $totalValue = $valueNormal + $valueCacat;

                // Deduct from master (in memory)
                $masterNormal -= $qNormal;
                $masterCacat -= $qCacat;

                // Queue master stock update
                $stockUpdates[$masterId] = ['stock' => $masterNormal, 'cacat' => $masterCacat];

                // Queue branch stock upsert
                if (isset($branchStockMap[$kode][$tokoId])) {
                    $bId = $branchStockMap[$kode][$tokoId]['id'];
                    $newNormal = ($stockUpdates[$bId]['stock'] ?? $branchStockMap[$kode][$tokoId]['normal']) + $qNormal;
                    $newCacat = ($stockUpdates[$bId]['cacat'] ?? $branchStockMap[$kode][$tokoId]['cacat']) + $qCacat;
                    $stockUpdates[$bId] = ['stock' => $newNormal, 'cacat' => $newCacat];
                    $branchBalance = $newNormal + $newCacat;
                }
                else {
                    // Track that we need to insert (use negative key to avoid collision)
                    $insertKey = $kode . '_' . $tokoId;
                    if (!isset($stockInserts[$insertKey])) {
                        $stockInserts[$insertKey] = [
                            'tenant_id' => $tenantId, 'id_barang' => $kode, 'id_toko' => $tokoId,
                            'stock' => 0, 'barang_cacat' => 0,
                        ];
                    }
                    $stockInserts[$insertKey]['stock'] += $qNormal;
                    $stockInserts[$insertKey]['barang_cacat'] += $qCacat;
                    $branchBalance = $stockInserts[$insertKey]['stock'] + $stockInserts[$insertKey]['barang_cacat'];
                }

                $masterBalance = $masterNormal + $masterCacat;

                // Ledgers
                $ledgerInserts[] = [
                    'tenant_id' => $tenantId, 'id_barang' => $kode, 'id_toko' => $idTokoMaster,
                    'qty' => -$qTotal, 'balance' => $masterBalance,
                    'reference_type' => 'TRANSFER_OUT', 'reference_id' => $refId,
                    'description' => "Migrasi: Transfer ke Toko $tokoId", 'created_at' => $now,
                ];
                $ledgerInserts[] = [
                    'tenant_id' => $tenantId, 'id_barang' => $kode, 'id_toko' => $tokoId,
                    'qty' => $qTotal, 'balance' => $branchBalance,
                    'reference_type' => 'TRANSFER_IN', 'reference_id' => $refId,
                    'description' => "Migrasi: Terima dari Master", 'created_at' => $now,
                ];

                // Journals (need to be inserted to get ID for items)
                if ($totalValue > 0) {
                    $outItems = [];
                    if ($valueNormal > 0) {
                        if (isset($accMap['10' . $tokoId . '4']))
                            $outItems[] = ['account_id' => $accMap['10' . $tokoId . '4'], 'debit' => $valueNormal, 'credit' => 0];
                        if (isset($accMap['10' . $idTokoMaster . '4']))
                            $outItems[] = ['account_id' => $accMap['10' . $idTokoMaster . '4'], 'debit' => 0, 'credit' => $valueNormal];
                    }
                    if ($valueCacat > 0) {
                        if (isset($accMap['10' . $tokoId . '7']))
                            $outItems[] = ['account_id' => $accMap['10' . $tokoId . '7'], 'debit' => $valueCacat, 'credit' => 0];
                        // Credit dari normal master (1034), bukan dari cacat master
                        if (isset($accMap['10' . $idTokoMaster . '4']))
                            $outItems[] = ['account_id' => $accMap['10' . $idTokoMaster . '4'], 'debit' => 0, 'credit' => $valueCacat];
                    }

                    $inItems = [];
                    if ($valueNormal > 0 && isset($accMap['10' . $tokoId . '4'])) {
                        $inItems[] = ['account_id' => $accMap['10' . $tokoId . '4'], 'debit' => $valueNormal, 'credit' => 0];
                    }
                    if ($valueCacat > 0 && isset($accMap['10' . $tokoId . '7'])) {
                        $inItems[] = ['account_id' => $accMap['10' . $tokoId . '7'], 'debit' => $valueCacat, 'credit' => 0];
                    }
                    if (!empty($inItems) && isset($accMap['30' . $idTokoMaster . '1'])) {
                        $inItems[] = ['account_id' => $accMap['30' . $idTokoMaster . '1'], 'debit' => 0, 'credit' => $totalValue];
                    }

                    $journalQueue[] = [
                        'out' => [
                            'tenant_id' => $tenantId, 'id_toko' => $idTokoMaster,
                            'reference_type' => 'TRANSFER_OUT', 'reference_id' => $refId,
                            'reference_no' => $refId, 'date' => $date,
                            'description' => "Inventory Out ($kode) -> Toko $tokoId", 'created_at' => $now,
                        ],
                        'out_items' => $outItems,
                        'in' => [
                            'tenant_id' => $tenantId, 'id_toko' => $tokoId,
                            'reference_type' => 'TRANSFER_IN', 'reference_id' => $refId,
                            'reference_no' => $refId, 'date' => $date,
                            'description' => "Inventory In ($kode) <- Master", 'created_at' => $now,
                        ],
                        'in_items' => $inItems,
                    ];
                }

                $totalTransferred++;
            }

            // Sync master balance after processing all branches for this product
            $masterStockMap[$kode]['normal'] = $masterNormal;
            $masterStockMap[$kode]['cacat'] = $masterCacat;
        }

        CLI::write("  Distributions computed: $totalTransferred, Skipped: $skipped", 'cyan');

        // ─────────────────────────────────────────────────────────
        // STAGE 3: Batch commit to DB
        // ─────────────────────────────────────────────────────────
        CLI::write("Stage 3: Writing to database...", 'yellow');

        $dbNew->transStart();

        // 3a. Stock updates (bulk via raw SQL for speed)
        foreach ($stockUpdates as $stockId => $vals) {
            $dbNew->query(
                "UPDATE stock SET stock = ?, barang_cacat = ? WHERE id = ?",
            [$vals['stock'], $vals['cacat'], $stockId]
            );
        }
        CLI::write("  Stock updated: " . count($stockUpdates) . " rows", 'cyan');

        // 3b. Stock inserts (batch)
        if (!empty($stockInserts)) {
            $chunks = array_chunk(array_values($stockInserts), 300);
            foreach ($chunks as $chunk) {
                $dbNew->table('stock')->ignore(true)->insertBatch($chunk);
            }
        }
        CLI::write("  Stock inserted: " . count($stockInserts) . " rows", 'cyan');

        // 3c. Ledgers (batch)
        if (!empty($ledgerInserts)) {
            $chunks = array_chunk($ledgerInserts, 500);
            foreach ($chunks as $chunk) {
                $dbNew->table('stock_ledgers')->ignore(true)->insertBatch($chunk);
            }
        }
        CLI::write("  Ledgers inserted: " . count($ledgerInserts) . " rows", 'cyan');

        // 3d. Journals + items (sequential insert for IDs, but batch items)
        $allJournalItems = [];
        foreach ($journalQueue as $entry) {
            // OUT journal
            if (!empty($entry['out_items'])) {
                $dbNew->table('journals')->insert($entry['out']);
                $jidOut = $dbNew->insertID();
                foreach ($entry['out_items'] as $item) {
                    $allJournalItems[] = ['journal_id' => $jidOut, 'account_id' => $item['account_id'], 'debit' => $item['debit'], 'credit' => $item['credit'], 'created_at' => $now];
                }
            }
            // IN journal
            if (!empty($entry['in_items'])) {
                $dbNew->table('journals')->insert($entry['in']);
                $jidIn = $dbNew->insertID();
                foreach ($entry['in_items'] as $item) {
                    $allJournalItems[] = ['journal_id' => $jidIn, 'account_id' => $item['account_id'], 'debit' => $item['debit'], 'credit' => $item['credit'], 'created_at' => $now];
                }
            }
        }
        // Batch insert all journal items at once
        if (!empty($allJournalItems)) {
            $chunks = array_chunk($allJournalItems, 500);
            foreach ($chunks as $chunk) {
                $dbNew->table('journal_items')->insertBatch($chunk);
            }
        }
        CLI::write("  Journals: " . count($journalQueue) . " pairs, Items: " . count($allJournalItems), 'cyan');

        $dbNew->transComplete();

        CLI::write("", 'white');
        if ($dbNew->transStatus() === false) {
            CLI::error("Transaction FAILED. Check DB errors.");
        }
        else {
            CLI::write("Distribution Complete!", 'green');
            CLI::write("  Transferred: $totalTransferred", 'green');
            CLI::write("  Skipped:     $skipped", 'yellow');
        }
    }
}