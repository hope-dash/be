<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdateDiscountTypeEnumInTransaction extends Migration
{
    public function up()
    {
        // To change ENUM, we can use modifyColumn but some DBs are tricky with ENUM.
        // For MySQL, we can just redefine it.
        $fields = [
            'discount_type' => [
                'type'       => 'ENUM',
                'constraint' => ['PERCENTAGE', 'FIXED'],
                'null'       => true,
            ],
        ];
        $this->forge->modifyColumn('transaction', $fields);
    }

    public function down()
    {
        $fields = [
            'discount_type' => [
                'type'       => 'ENUM',
                'constraint' => ['PERCENT', 'FIXED'],
                'null'       => true,
            ],
        ];
        $this->forge->modifyColumn('transaction', $fields);
    }
}
