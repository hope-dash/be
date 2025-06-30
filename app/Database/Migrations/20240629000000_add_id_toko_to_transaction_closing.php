<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIdTokoToTransactionClosing extends Migration
{
    public function up()
    {
        $this->forge->addColumn('transaction_closing', [
            'id_toko' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
                'after' => 'transaction_id'
            ]
        ]);

        // Add foreign key if needed
        $this->db->query("ALTER TABLE transaction_closing ADD CONSTRAINT fk_transaction_closing_toko 
                          FOREIGN KEY (id_toko) REFERENCES toko(id) ON DELETE SET NULL");
    }

    public function down()
    {
        $this->forge->dropColumn('transaction_closing', 'id_toko');
    }
}