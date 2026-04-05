<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFirebaseIdToCustomer extends Migration
{
    public function up()
    {
        $fields = [
            'firebase_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'password',
            ],
        ];
        $this->forge->addColumn('customer', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('customer', 'firebase_id');
    }
}
