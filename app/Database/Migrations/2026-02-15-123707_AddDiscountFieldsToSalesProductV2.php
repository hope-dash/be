<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDiscountFieldsToSalesProductV2 extends Migration
{
    public function up()
    {
        $fields = [
            'discount_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'actual_total'
            ],
            'discount_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
                'after'      => 'discount_type'
            ],
        ];
        $this->forge->addColumn('sales_product', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('sales_product', ['discount_type', 'discount_amount']);
    }
}
