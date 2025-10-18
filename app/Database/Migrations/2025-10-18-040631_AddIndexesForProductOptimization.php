<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexesForProductOptimization extends Migration
{
    public function up()
    {
        // Helper: cek apakah index sudah ada
        $this->ensureIndex('product', 'idx_product_id_barang', ['id_barang']);
        $this->ensureIndex('product', 'idx_product_id_model', ['id_model_barang']);
        $this->ensureIndex('product', 'idx_product_id_seri', ['id_seri_barang']);

        $this->ensureIndex('stock', 'idx_stock_id_barang', ['id_barang']);
        $this->ensureIndex('stock', 'idx_stock_id_toko', ['id_toko']);

        $this->ensureIndex('sales_product', 'idx_sales_product_kode', ['kode_barang']);
        $this->ensureIndex('sales_product', 'idx_sales_product_transaction', ['id_transaction']);

        $this->ensureIndex('transaction', 'idx_transaction_status', ['status']);

        $this->ensureIndex('image', 'idx_image_type_kode', ['type', 'kode']);

        $this->ensureIndex('suplier', 'idx_suplier_id', ['id']);
    }

    public function down()
    {
        $this->db->query("DROP INDEX IF EXISTS idx_product_id_barang ON product");
        $this->db->query("DROP INDEX IF EXISTS idx_product_id_model ON product");
        $this->db->query("DROP INDEX IF EXISTS idx_product_id_seri ON product");

        $this->db->query("DROP INDEX IF EXISTS idx_stock_id_barang ON stock");
        $this->db->query("DROP INDEX IF EXISTS idx_stock_id_toko ON stock");

        $this->db->query("DROP INDEX IF EXISTS idx_sales_product_kode ON sales_product");
        $this->db->query("DROP INDEX IF EXISTS idx_sales_product_transaction ON sales_product");

        $this->db->query("DROP INDEX IF EXISTS idx_transaction_status ON transaction");

        $this->db->query("DROP INDEX IF EXISTS idx_image_type_kode ON image");

        $this->db->query("DROP INDEX IF EXISTS idx_suplier_id ON suplier");
    }

    /**
     * Membuat index hanya jika belum ada
     */
    private function ensureIndex(string $table, string $indexName, array $columns)
    {
        // Cek apakah index sudah ada
        $exists = $this->db->query(
            "SHOW INDEX FROM {$table} WHERE Key_name = '{$indexName}'"
        )->getNumRows() > 0;

        if (!$exists) {
            $columnsStr = implode(', ', $columns);
            $this->db->query("CREATE INDEX {$indexName} ON {$table} ({$columnsStr})");
        }
    }
}