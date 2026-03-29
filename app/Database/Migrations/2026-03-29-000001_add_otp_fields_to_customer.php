<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddOtpFieldsToCustomer extends Migration
{
    public function up()
    {
        $this->forge->addColumn('customer', [
            'otp_code' => [
                'type' => 'VARCHAR',
                'constraint' => 6,
                'null' => true,
                'after' => 'email_verification_token',
            ],
            'otp_expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'otp_code',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('customer', ['otp_code', 'otp_expires_at']);
    }
}
