<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\TokoModel;
use App\Models\StockModel;
use App\Models\ProductModel;
use App\Models\ImageModel;
use App\Controllers\TiktokController;
use App\Libraries\TiktokService;

class SyncBekasiToTiktok extends BaseCommand
{
    protected $group       = 'TikTok';
    protected $name        = 'tiktok:sync-bekasi';
    protected $description = 'Sync all products with stock > 0 for id_toko = 1 and images > 0 to TikTok Shop';

    public function run(array $params)
    {
        $idToko = isset($params[0]) ? (int) $params[0] : (int) (CLI::getOption('toko_id') ?: 1);
        CLI::write("Starting TikTok sync process for id_toko = {$idToko}...", "yellow");

        $tokoModel    = new TokoModel();
        $stockModel   = new StockModel();
        $productModel = new ProductModel();
        $imageModel   = new ImageModel();

        $toko = $tokoModel->find($idToko);
        $tokoName = $toko['toko_name'] ?? "Toko ID {$idToko}";
        CLI::write("Target Store: {$tokoName} (ID: {$idToko})", "cyan");

        // 1. Query stock records for id_toko = 1 with stock > 0
        $stockRecords = $stockModel->where('id_toko', $idToko)
            ->where('stock >', 0)
            ->findAll();

        if (empty($stockRecords)) {
            CLI::write("No products found in store ID {$idToko} with stock > 0.", "red");
            return;
        }

        CLI::write("Found " . count($stockRecords) . " product stock records with stock > 0 in store ID {$idToko}.", "cyan");

        $tiktokController = new TiktokController();
        $tiktokService    = new TiktokService();

        $successCount = 0;
        $failCount    = 0;
        $skippedCount = 0;

        foreach ($stockRecords as $stockRow) {
            $idBarang = $stockRow['id_barang'];

            // Find main product record
            $product = $productModel->where('id_barang', $idBarang)->first();
            if (!$product) {
                CLI::write("Product not found for id_barang: {$idBarang}", "yellow");
                $skippedCount++;
                continue;
            }

            $idProduct  = $product['id'];
            $namaBarang = $product['nama_barang'];

            // Check if product has at least 1 image in table image
            $imageCount = $imageModel->where('type', 'product')
                ->where('kode', $idProduct)
                ->countAllResults();

            if ($imageCount <= 0) {
                CLI::write("Skipping {$namaBarang} (ID: {$idProduct}): Image count is 0.", "yellow");
                $skippedCount++;
                continue;
            }

            CLI::write("Processing product: {$namaBarang} (ID: {$idProduct}, Stock: {$stockRow['stock']}, Images: {$imageCount})...", "white");

            // Check if already uploaded to TikTok
            if (!empty($stockRow['tiktok_product_id'])) {
                CLI::write("-> Already on TikTok ({$stockRow['tiktok_product_id']}). Syncing stock...", "cyan");
                $syncRes = $tiktokService->syncProductStock((int) $idProduct, (int) $idToko);

                if (isset($syncRes['success']) && $syncRes['success']) {
                    CLI::write("-> Stock sync SUCCESS for {$namaBarang}.", "green");
                    $successCount++;
                } else {
                    $msg = $syncRes['message'] ?? 'Stock sync failed';
                    CLI::write("-> Stock sync FAILED for {$namaBarang}: {$msg}", "red");
                    $failCount++;
                }
            } else {
                CLI::write("-> Not on TikTok yet. Uploading product...", "cyan");
                $uploadRes = $tiktokController->uploadProductToTiktok($idProduct, $idToko);

                if (isset($uploadRes['success']) && $uploadRes['success']) {
                    CLI::write("-> Upload SUCCESS for {$namaBarang} (TikTok Product ID: {$uploadRes['tiktok_product_id']}).", "green");
                    $successCount++;
                } else {
                    $msg = $uploadRes['message'] ?? 'Upload failed';
                    CLI::write("-> Upload FAILED for {$namaBarang}: {$msg}", "red");
                    $failCount++;
                }
            }
        }

        CLI::write("\nSync finished! Success: {$successCount}, Failed: {$failCount}, Skipped: {$skippedCount}", "yellow");
    }
}
