<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAlamatToCustomer extends Migration
{
    public function up()
    {
        $this->forge->addColumn('customer', [
            'alamat' => [
                'type'       => 'TEXT',
                'null'       => true,
                'after'      => 'no_hp_customer' // taruh setelah kolom no_hp_customer
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('customer', 'alamat');
    }
}
