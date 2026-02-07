<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAuthFieldsToCustomer extends Migration
{
    public function up()
    {
        $fields = [
            'email_verified_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'email',
            ],
            'email_verification_token' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'email_verified_at',
            ],
        ];
        $this->forge->addColumn('customer', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('customer', [
            'email_verified_at',
            'email_verification_token',
        ]);
    }
}
