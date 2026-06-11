<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCustomerPoints extends Migration
{
    public function up()
    {
        // 1. Add base account 2003
        $existsBase = $this->db->table('accounts')
            ->where('code', '2003')
            ->where('id_toko', null)
            ->countAllResults();

        if ($existsBase == 0) {
            $this->db->table('accounts')->insert([
                'code' => '2003',
                'base_code' => '2003',
                'name' => 'Customer Points',
                'type' => 'LIABILITY',
                'normal_balance' => 'CREDIT',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // 2. Generate store-specific accounts for 2003 for each toko
        $tokoList = $this->db->table('toko')->get()->getResultArray();
        foreach ($tokoList as $toko) {
            $newCode = '20' . $toko['id'] . '3';
            $newName = 'Customer Points ' . $toko['toko_name'];

            $exists = $this->db->table('accounts')
                ->where('code', $newCode)
                ->countAllResults();

            if ($exists == 0) {
                $this->db->table('accounts')->insert([
                    'id_toko' => $toko['id'],
                    'base_code' => '2003',
                    'code' => $newCode,
                    'name' => $newName,
                    'type' => 'LIABILITY',
                    'normal_balance' => 'CREDIT',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }

        // 3. Add points_balance to customer table
        if (!$this->db->fieldExists('points_balance', 'customer')) {
            $fields = [
                'points_balance' => [
                    'type' => 'DECIMAL',
                    'constraint' => '15,2',
                    'default' => 0.00,
                    'after' => 'deleted_at'
                ]
            ];
            $this->forge->addColumn('customer', $fields);
        }

        // 4. Create customer_point_history table
        if (!$this->db->tableExists('customer_point_history')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'tenant_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'customer_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'transaction_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'points_change' => [
                    'type' => 'DECIMAL',
                    'constraint' => '15,2',
                ],
                'balance_after' => [
                    'type' => 'DECIMAL',
                    'constraint' => '15,2',
                ],
                'type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 50, // 'EARNED', 'REDEEMED', 'ADJUSTMENT'
                ],
                'description' => [
                    'type' => 'TEXT',
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
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('customer_id');
            $this->forge->addKey('transaction_id');
            $this->forge->createTable('customer_point_history');

            // Add tenant_id index for scoping
            $this->db->query("CREATE INDEX idx_customer_point_history_tenant_id ON customer_point_history(tenant_id)");
        }
    }

    public function down()
    {
        if ($this->db->tableExists('customer_point_history')) {
            $this->forge->dropTable('customer_point_history');
        }

        if ($this->db->fieldExists('points_balance', 'customer')) {
            $this->forge->dropColumn('customer', 'points_balance');
        }

        // Clean up accounts
        $this->db->table('accounts')->where('base_code', '2003')->delete();
    }
}
