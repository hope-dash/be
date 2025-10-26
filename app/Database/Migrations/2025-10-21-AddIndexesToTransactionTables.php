<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexesToTransactionTables extends Migration
{
    public function up()
    {
        // Index untuk tabel transaction
        $this->db->query("
            ALTER TABLE `transaction`
                ADD INDEX `idx_transaction_status` (`status`),
                ADD INDEX `idx_transaction_toko` (`id_toko`),
                ADD INDEX `idx_transaction_datetime` (`date_time`),
                ADD INDEX `idx_transaction_total` (`total_payment`),
                ADD INDEX `idx_transaction_invoice` (`invoice`);
        ");

        // Index untuk tabel transaction_meta
        $this->db->query("
            ALTER TABLE `transaction_meta`
                ADD INDEX `idx_meta_transaction_key` (`transaction_id`, `key`),
                ADD INDEX `idx_meta_value` (`value`);
        ");

        // Index untuk tabel customer
        $this->db->query("
            ALTER TABLE `customer`
                ADD INDEX `idx_customer_name` (`nama_customer`),
                ADD INDEX `idx_customer_phone` (`no_hp_customer`);
        ");

        // Index untuk tabel toko
        $this->db->query("
            ALTER TABLE `toko`
                ADD INDEX `idx_toko_name` (`toko_name`);
        ");
    }

    public function down()
    {
        // Drop index saat rollback
        $this->db->query("
            ALTER TABLE `transaction`
                DROP INDEX `idx_transaction_status`,
                DROP INDEX `idx_transaction_toko`,
                DROP INDEX `idx_transaction_datetime`,
                DROP INDEX `idx_transaction_total`,
                DROP INDEX `idx_transaction_invoice`;
        ");

        $this->db->query("
            ALTER TABLE `transaction_meta`
                DROP INDEX `idx_meta_transaction_key`,
                DROP INDEX `idx_meta_value`;
        ");

        $this->db->query("
            ALTER TABLE `customer`
                DROP INDEX `idx_customer_name`,
                DROP INDEX `idx_customer_phone`;
        ");

        $this->db->query("
            ALTER TABLE `toko`
                DROP INDEX `idx_toko_name`;
        ");
    }
}
