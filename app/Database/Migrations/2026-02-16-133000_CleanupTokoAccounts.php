<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CleanupTokoAccounts extends Migration
{
    public function up()
    {
        $fields = $this->db->getFieldNames('toko');

        if (in_array('bank_account_id', $fields)) {
            // Attempt to drop FK if it exists
            try {
                $this->forge->dropForeignKey('toko', 'toko_bank_account_fk');
            } catch (\Throwable $e) {
                // Ignore if FK doesn't exist
            }
            $this->forge->dropColumn('toko', 'bank_account_id');
        }

        if (in_array('cash_account_id', $fields)) {
            try {
                $this->forge->dropForeignKey('toko', 'toko_cash_account_fk');
            } catch (\Throwable $e) {
                // Ignore
            }
            $this->forge->dropColumn('toko', 'cash_account_id');
        }
    }

    public function down()
    {
        // No restoration needed as this is a cleanup
    }
}
