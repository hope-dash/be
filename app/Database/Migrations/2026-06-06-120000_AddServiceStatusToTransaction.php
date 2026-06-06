<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddServiceStatusToTransaction extends Migration
{
    public function up()
    {
        $this->forge->addColumn('transaction', [
            'service_status' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
                'after'      => 'is_service'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('transaction', 'service_status');
    }
}
