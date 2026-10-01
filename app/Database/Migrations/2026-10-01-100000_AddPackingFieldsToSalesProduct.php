<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPackingFieldsToSalesProduct extends Migration
{
    public function up()
    {
        $this->forge->addColumn('sales_product', [
            'packed_qty' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
                'null'       => false,
                'after'      => 'jumlah',
            ],
            'packed_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
                'after'      => 'packed_qty',
            ],
            'packed_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'packed_at',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('sales_product', ['packed_qty', 'packed_at', 'packed_by']);
    }
}
