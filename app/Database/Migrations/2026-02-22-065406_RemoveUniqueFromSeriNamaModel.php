<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RemoveUniqueFromSeriNamaModel extends Migration
{
    public function up()
    {
        // Remove unique index from 'seri' column in 'seri' table
        // We drop the index first. The index name is usually the column name if it was created with ->unique() in forge
        $this->db->query("ALTER TABLE seri DROP INDEX seri");

        // Ensure nama_model in model_barang is also not unique if it somehow became unique
        // Based on migration it wasn't, but we check to be safe if you want to be thorough.
        // For now let's focus on 'seri' which is the one causing the error.
    }

    public function down()
    {
        // Re-add the unique index if rolled back
        $this->db->query("ALTER TABLE seri ADD UNIQUE (seri)");
    }
}
