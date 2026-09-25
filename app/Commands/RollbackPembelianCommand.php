<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Controllers\PembelianControllerV2;
use Config\Database;

class RollbackPembelianCommand extends BaseCommand
{
    protected $group = 'Pembelian';
    protected $name = 'pembelian:rollback';
    protected $description = 'Rollback executed purchase and optionally transfer/re-execute to target store';
    protected $usage = 'pembelian:rollback <pembelian_id> [--target-toko=ID] [--reason=TEXT] [--dry-run]';
    protected $arguments = [
        'pembelian_id' => 'The ID of the pembelian to rollback',
    ];
    protected $options = [
        '--target-toko' => 'Target toko ID to transfer and re-execute (e.g. 3)',
        '--reason' => 'Reason for rollback (default: Salah input toko)',
        '--dry-run' => 'Inspect only without executing rollback',
    ];

    public function run(array $params)
    {
        $db = Database::connect();
        $pembelianId = $params[0] ?? CLI::getSegment(2);
        if (!$pembelianId) {
            CLI::error("ID Pembelian wajib diisi. Contoh: php spark pembelian:rollback 151 --target-toko=3");
            return;
        }

        $targetToko = CLI::getOption('target-toko');
        $reason = CLI::getOption('reason') ?? 'Salah input toko';
        $isDryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');

        CLI::write("=== Checking Pembelian ID #{$pembelianId} ===", "yellow");

        $pembelian = $db->table('pembelian')->where('id', $pembelianId)->get()->getRowArray();
        if (!$pembelian) {
            CLI::error("Pembelian #{$pembelianId} tidak ditemukan di database!");
            return;
        }

        CLI::write("Status: {$pembelian['status']}", "cyan");
        CLI::write("Toko Saat Ini: ID #{$pembelian['id_toko']}", "cyan");
        CLI::write("Total Belanja: Rp " . number_format($pembelian['total_belanja'], 0, ',', '.'), "cyan");
        CLI::write("Tanggal: {$pembelian['tanggal_belanja']}", "cyan");

        if ($pembelian['status'] !== 'SUCCESS') {
            CLI::error("Hanya pembelian dengan status SUCCESS yang dapat di-rollback. Status saat ini: {$pembelian['status']}");
            return;
        }

        $details = $db->table('pembelian_detail')->where('pembelian_id', $pembelianId)->get()->getResultArray();
        CLI::write("Items (" . count($details) . " produk):", "yellow");
        foreach ($details as $d) {
            $st = $db->table('stock')
                ->where('id_barang', $d['kode_barang'])
                ->where('id_toko', $pembelian['id_toko'])
                ->get()->getRowArray();
            $currStock = $st ? (int)$st['stock'] : 0;
            $newStock = $currStock - (int)$d['jumlah'];
            CLI::write("  - [{$d['kode_barang']}] Beli: {$d['jumlah']} pcs | Stok Toko #{$pembelian['id_toko']}: {$currStock} -> {$newStock}", "light_gray");
        }

        if ($targetToko) {
            CLI::write("Target Pengalihan: Toko ID #{$targetToko}", "green");
        }

        if ($isDryRun) {
            CLI::write("\n[DRY RUN] Tidak ada perubahan data yang disimpan.", "yellow");
            return;
        }

        CLI::write("\nMelakukan Rollback Pembelian #{$pembelianId}...", "yellow");

        // Execute via controller logic
        $controller = new PembelianControllerV2();
        
        // Mock request context
        $_POST['target_id_toko'] = $targetToko;
        $_POST['reason'] = $reason;

        // Call method directly
        // We set request user context to system/cli
        $request = service('request');
        $request->user = ['user_id' => 1, 'username' => 'CLI Admin'];

        // Perform rollback
        $result = $controller->rollback($pembelianId, $targetToko ? (int)$targetToko : null, $reason);
        $body = json_decode($result->getBody(), true);

        if ($result->getStatusCode() === 200) {
            CLI::write("\nBERHASIL!", "green");
            CLI::write($body['message'] ?? 'Pembelian berhasil di-rollback', "green");
            if (!empty($body['data']['rollback_summary'])) {
                CLI::write("Ringkasan Pengurangan Stok di Toko #{$pembelian['id_toko']}:", "yellow");
                foreach ($body['data']['rollback_summary'] as $rs) {
                    CLI::write("  - {$rs['kode_barang']}: dikurangi {$rs['qty_dikurangi']} pcs (Stok: {$rs['stok_lama']} -> {$rs['stok_baru']})", "light_cyan");
                }
            }
            if (!empty($body['data']['transferred_to_target'])) {
                $tgt = $body['data']['transferred_to_target'];
                CLI::write("Ringkasan Penambahan Stok di Toko Target #{$tgt['target_id_toko']}:", "yellow");
                foreach ($tgt['items'] as $ti) {
                    CLI::write("  - {$ti['kode_barang']}: bertambah +{$ti['qty_bertambah']} pcs (Stok: {$ti['stok_lama']} -> {$ti['stok_baru']})", "green");
                }
            }
        } else {
            CLI::error("GAGAL: " . ($body['message'] ?? 'Terjadi kesalahan'));
        }
    }
}
