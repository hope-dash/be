<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdatePembelianStatusEnum extends Migration
{
    public function up()
    {
        // 1. Update old status 'REVIEW' to 'NEED_REVIEW' to match new flow
        $this->db->query("UPDATE pembelian SET status = 'NEED_REVIEW' WHERE status = 'REVIEW'");

        // 2. Modify column to new Enum values
        // Note: We use a raw query because Forge modifyColumn with ENUM can be tricky or driver specific
        $this->db->query("ALTER TABLE pembelian MODIFY COLUMN status ENUM('NEED_REVIEW','APPROVED','SUCCESS','CANCEL') NOT NULL DEFAULT 'NEED_REVIEW'");
    }

    public function down()
    {
        // Revert mapping: NEED_REVIEW -> REVIEW (lossy if we distinguish them, but okay for rollback)
        $this->db->query("UPDATE pembelian SET status = 'REVIEW' WHERE status = 'NEED_REVIEW'");

        // APPROVED -> REVIEW? Or stay? Migration down usually reverts structure.
        // We'll map APPROVED -> REVIEW as well to fit old schema.
        $this->db->query("UPDATE pembelian SET status = 'REVIEW' WHERE status = 'APPROVED'");

        $this->db->query("ALTER TABLE pembelian MODIFY COLUMN status ENUM('REVIEW','SUCCESS','CANCEL') NOT NULL DEFAULT 'REVIEW'");
    }
}
