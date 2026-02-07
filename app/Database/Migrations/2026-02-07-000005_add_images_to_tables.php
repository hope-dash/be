<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddImagesToTables extends Migration
{
    public function up()
    {
        // Add image to transaction_payments
        if (!$this->db->fieldExists('image_url', 'transaction_payments')) {
             $this->forge->addColumn('transaction_payments', [
                 'image_url' => [
                     'type' => 'TEXT',
                     'null' => true,
                     'after' => 'note'
                 ]
             ]);
        }

        // Add image to pembelian
        if (!$this->db->fieldExists('bukti_foto', 'pembelian')) {
             $this->forge->addColumn('pembelian', [
                 'bukti_foto' => [
                     'type' => 'TEXT',
                     'null' => true,
                     'after' => 'catatan'
                 ]
             ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('image_url', 'transaction_payments')) {
            $this->forge->dropColumn('transaction_payments', 'image_url');
        }
        if ($this->db->fieldExists('bukti_foto', 'pembelian')) {
            $this->forge->dropColumn('pembelian', 'bukti_foto');
        }
    }
}
