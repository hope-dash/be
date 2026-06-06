<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCommissionToSalesProduct extends Migration
{
    public function up()
    {
        $this->forge->addColumn('sales_product', [
            'komisi_persen' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => '0.00',
                'after'      => 'teknisi_id'
            ],
            'komisi_nominal' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => '0.00',
                'after'      => 'komisi_persen'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('sales_product', ['komisi_persen', 'komisi_nominal']);
    }
}
