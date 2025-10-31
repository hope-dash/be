<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDropshipSuplierToSalesProduct extends Migration
{
    public function up()
    {
        $fields = [
            'dropship_suplier' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'closing',
            ],
        ];

        $this->forge->addColumn('sales_product', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('sales_product', 'dropship_suplier');
    }
}
