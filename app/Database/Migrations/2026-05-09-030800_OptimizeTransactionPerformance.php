<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class OptimizeTransactionPerformance extends Migration
{
    public function up()
    {
        // 1. Add composite index for common filtering & sorting in TransactionControllerV2::getTransactionsByStatus
        // The query is: WHERE id_toko = ? AND status = ? ORDER BY created_at DESC
        $this->db->query("ALTER TABLE `transaction` ADD INDEX `idx_toko_status_created` (`id_toko`, `status`, `created_at`) ");

        // 2. Add individual index for created_at if it doesn't exist
        $this->db->query("ALTER TABLE `transaction` ADD INDEX `idx_created_at` (`created_at`) ");

        // 3. Ensure transaction_id in meta is indexed (left part of existing composite idx_meta_transaction_key is transaction_id)
        // If it's already indexed via (transaction_id, key), that's fine for simple lookups by transaction_id.
    }

    public function down()
    {
        $this->db->query("ALTER TABLE `transaction` DROP INDEX `idx_toko_status_created` ");
        $this->db->query("ALTER TABLE `transaction` DROP INDEX `idx_created_at` ");
    }
}
