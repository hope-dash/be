<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UbahCustomerTambahTypeUsername extends Migration
{
    public function up()
    {
        $this->forge->addColumn('customer', [
            'username' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'no_hp_customer'
            ],
            'type' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'username'
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('customer', ['username', 'type']);
    }
}
