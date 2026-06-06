<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ModifySalesProductForGlobalTechnician extends Migration
{
    public function up()
    {
        // 1. Drop teknisi_id from sales_product
        $this->forge->dropColumn('sales_product', 'teknisi_id');

        // 2. Modify kode_barang to be nullable in sales_product
        $this->forge->modifyColumn('sales_product', [
            'kode_barang' => [
                'name'       => 'kode_barang',
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
            ]
        ]);
    }

    public function down()
    {
        // Add back teknisi_id to sales_product
        $this->forge->addColumn('sales_product', [
            'teknisi_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'id_jasa'
            ]
        ]);

        // Restore kode_barang to not null
        $this->forge->modifyColumn('sales_product', [
            'kode_barang' => [
                'name'       => 'kode_barang',
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => false,
            ]
        ]);
    }
}
