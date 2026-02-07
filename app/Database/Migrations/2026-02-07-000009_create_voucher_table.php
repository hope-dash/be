<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVoucherTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'unique' => true,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'discount_type' => [
                'type' => 'ENUM',
                'constraint' => ['FIXED', 'PERCENTAGE'],
                'default' => 'FIXED',
            ],
            'discount_value' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'default' => 0,
            ],
            'min_purchase' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'null' => true,
                'comment' => 'Minimum purchase amount to use voucher',
            ],
            'max_discount' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'null' => true,
                'comment' => 'Maximum discount amount (for percentage type)',
            ],
            'usage_limit' => [
                'type' => 'INT',
                'null' => true,
                'comment' => 'Total usage limit (null = unlimited)',
            ],
            'usage_count' => [
                'type' => 'INT',
                'default' => 0,
                'comment' => 'Current usage count',
            ],
            'valid_from' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'valid_until' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'is_active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'created_by' => [
                'type' => 'INT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('voucher');
    }

    public function down()
    {
        $this->forge->dropTable('voucher');
    }
}
