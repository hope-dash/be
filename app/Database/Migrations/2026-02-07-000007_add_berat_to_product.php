<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBeratToProduct extends Migration
{
    public function up()
    {
        $fields = [
            'berat' => [
                'type' => 'INT',
                'null' => true,
                'default' => 0,
                'comment' => 'Weight in grams',
                'after' => 'notes',
            ],
        ];
        $this->forge->addColumn('product', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('product', 'berat');
    }
}
