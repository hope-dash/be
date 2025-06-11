<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddClosingTypeToSalesProduct extends Migration
{
    public function up()
    {
        // Jika kolom sudah ada dan ingin diubah tipe dan nilainya, gunakan modifyColumn
        $fields = [
            'closing' => [
                'type'       => 'ENUM',
                'constraint' => ['VALID', 'BROKEN', ''], // Pilihan enum, '' untuk nilai kosong
                'null'       => true,
                'default'    => null,
                'after'      => 'actual_total',
            ],
        ];
        $this->forge->modifyColumn('sales_product', $fields);
    }

    public function down()
    {
        // Kembalikan ke tipe BOOLEAN jika rollback
        $fields = [
            'closing' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
                'after'   => 'actual_total',
            ],
        ];
        $this->forge->modifyColumn('sales_product', $fields);
    }
}
