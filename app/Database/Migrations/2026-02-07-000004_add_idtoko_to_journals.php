<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIdTokoToJournals extends Migration
{
    public function up()
    {
        // Add id_toko to journals table
        if (!$this->db->fieldExists('id_toko', 'journals')) {
             $this->forge->addColumn('journals', [
                 'id_toko' => [
                     'type' => 'INT',
                     'constraint' => 11,
                     'null' => true, // Nullable for Head Office or consolidated entries if any
                     'after' => 'id'
                 ]
             ]);
             // Add index for performance
             $this->db->query('ALTER TABLE journals ADD INDEX (id_toko)');
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('id_toko', 'journals')) {
            $this->forge->dropColumn('journals', 'id_toko');
        }
    }
}
