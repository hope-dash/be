<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class MigrateStockToBelanja extends BaseCommand
{
    protected $group = 'Migration';
    protected $name = 'migrate:stock-belanja';
    protected $description = 'Super Optimized stock migration with explicit chunking and counting';

    public function run(array $params)
    {
        $dbOld = Database::connect('old');
        $dbNew = Database::connect('default');

        CLI::write("Calculating stock levels from OLD DATABASE...", 'yellow');

        $sql = "
            SELECT 
                p.id_barang, 
                p.nama_barang, 
                p.harga_modal, 
                p.harga_jual,
                COALESCE(s.total_normal, 0) as stock_normal,
                COALESCE(s.total_cacat, 0) as stock_cacat,
                COALESCE(ps.total_pending, 0) as stock_pending
            FROM product p
            LEFT JOIN (
                SELECT id_barang, SUM(stock) as total_normal, SUM(barang_cacat) as total_cacat
                FROM stock
                GROUP BY id_barang
            ) s ON s.id_barang = p.id_barang
            LEFT JOIN (
                SELECT sp.kode_barang, SUM(sp.jumlah) as total_pending
                FROM sales_product sp
                JOIN transaction t ON t.id = sp.id_transaction
                WHERE t.status IN ('WAITING_PAYMENT', 'REFUNDED')
                GROUP BY sp.kode_barang
            ) ps ON ps.kode_barang = p.id_barang
            WHERE p.deleted_at IS NULL
        ";

        try {
            $results = $dbOld->query($sql)->getResultArray();
        }
        catch (\Exception $e) {
            CLI::error("Error querying OLD DB: " . $e->getMessage());
            return;
        }

        CLI::write("Found " . count($results) . " records from OLD DB.", 'cyan');

        $details = [];
        $grandTotal = 0;
        $idTokoTarget = 3;

        foreach ($results as $row) {
            $totalStock = (int)$row['stock_normal'] + (int)$row['stock_cacat'] + (int)$row['stock_pending'];

            if ($totalStock > 0) {
                $hargaModal = round((float)$row['harga_modal']);
                $totalHarga = $totalStock * $hargaModal;

                $details[] = [
                    'tenant_id' => 1,
                    'kode_barang' => $row['id_barang'],
                    'jumlah' => $totalStock,
                    'harga_satuan' => $hargaModal,
                    'harga_jual' => (float)$row['harga_jual'],
                    'ongkir' => 0,
                    'total_harga' => $totalHarga,
                ];

                $grandTotal += $totalHarga;
            }
        }

        if (empty($details)) {
            CLI::error("No stock found in OLD DB to migrate.");
            return;
        }

        CLI::write("Migrating " . count($details) . " items into NEW DB...", 'yellow');

        $dbNew->transStart();

        $pembelianData = [
            'tenant_id' => 1,
            'tanggal_belanja' => date('Y-m-d'),
            'supplier_id' => null,
            'id_toko' => $idTokoTarget,
            'total_belanja' => $grandTotal,
            'catatan' => 'Migrasi stock awal master (SUCCESS: Verified Chunking)',
            'status' => 'NEED_REVIEW',
            'created_by' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $dbNew->table('pembelian')->insert($pembelianData);
        $pembelianId = $dbNew->insertID();

        foreach ($details as &$d) {
            $d['pembelian_id'] = $pembelianId;
        }

        CLI::write("Inserting data in chunks...", 'yellow');

        // Manual Chunking for better tracking
        $chunks = array_chunk($details, 200);
        $totalDet = 0;
        foreach ($chunks as $chunk) {
            $totalDet += $dbNew->table('pembelian_detail')->ignore(true)->insertBatch($chunk);
        }
        CLI::write("Total pembelian_detail inserted: $totalDet", 'cyan');

        $dbNew->transComplete();

        if ($dbNew->transStatus() === false) {
            CLI::error("Migration failed or rolled back.");
        }
        else {
            CLI::write("Migration completed successfully!", 'green');
            CLI::write("New Purchase ID: #$pembelianId", 'cyan');
        }
    }
}