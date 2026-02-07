<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPermissionsToUsers extends Migration
{
    public function up()
    {
        $fields = [
            'permissions' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'access',
            ],
        ];
        $this->forge->addColumn('users', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'permissions');
    }
}
