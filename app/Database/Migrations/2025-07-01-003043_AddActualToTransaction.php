<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddActualToTransaction extends Migration
{
    public function up()
    {
        $this->forge->addColumn('transaction', [
            'actual_total' => [
                'type' => 'DOUBLE',
                'constraint' => '20,2',
                'null' => true,
                'after' => 'amount', // sesuaikan posisi jika perlu
            ],
            'total_modal' => [
                'type' => 'DOUBLE',
                'constraint' => '20,2',
                'null' => true,
                'after' => 'actual_total',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('transaction', ['actual_total', 'total_modal']);
    }
}
