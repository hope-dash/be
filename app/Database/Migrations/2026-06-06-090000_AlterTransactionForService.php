<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterTransactionForService extends Migration
{
    public function up()
    {
        // 1. Add columns to sales_product
        $this->forge->addColumn('sales_product', [
            'is_service' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'actual_total'
            ],
            'id_jasa' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'is_service'
            ],
            'teknisi_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'id_jasa'
            ]
        ]);

        // 2. Add columns to transaction
        $this->forge->addColumn('transaction', [
            'is_service' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'po'
            ]
        ]);

        // 3. Create teknisi_komisi table
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'id_toko' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'transaction_id' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'sales_product_id' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'jasa_service_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'teknisi_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'komisi_persen' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
            ],
            'harga_jasa' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
            ],
            'komisi_nominal' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ]
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('tenant_id');
        $this->forge->addKey('id_toko');
        $this->forge->addKey('transaction_id');
        $this->forge->addKey('teknisi_id');
        $this->forge->createTable('teknisi_komisi');

        // 4. Seed Accounting Accounts
        $db = \Config\Database::connect();
        $newBaseAccounts = [
            ['code' => '4004', 'base_code' => '4004', 'name' => 'Service Revenue', 'type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['code' => '5002', 'base_code' => '5002', 'name' => 'Cost of Service', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
        ];

        foreach ($newBaseAccounts as $acc) {
            $exists = $db->table('accounts')->where('code', $acc['code'])->where('id_toko', null)->countAllResults();
            if ($exists == 0) {
                $db->table('accounts')->insert($acc);
            }
        }

        $tokoList = $db->table('toko')->get()->getResultArray();
        foreach ($tokoList as $toko) {
            foreach ($newBaseAccounts as $acc) {
                $baseCode = $acc['base_code'];
                $newCode = substr($baseCode, 0, 2) . $toko['id'] . substr($baseCode, 3);
                $newName = $acc['name'] . ' ' . $toko['toko_name'];

                $exists = $db->table('accounts')->where('code', $newCode)->where('id_toko', $toko['id'])->countAllResults();
                if ($exists == 0) {
                    $db->table('accounts')->insert([
                        'tenant_id' => $toko['tenant_id'],
                        'id_toko'   => $toko['id'],
                        'base_code' => $baseCode,
                        'code'      => $newCode,
                        'name'      => $newName,
                        'type'      => $acc['type'],
                        'normal_balance' => $acc['normal_balance'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
        }
    }

    public function down()
    {
        // Drop columns from sales_product
        $this->forge->dropColumn('sales_product', ['is_service', 'id_jasa', 'teknisi_id']);

        // Drop columns from transaction
        $this->forge->dropColumn('transaction', ['is_service']);

        // Drop teknisi_komisi table
        $this->forge->dropTable('teknisi_komisi');

        // Delete accounting accounts
        $db = \Config\Database::connect();
        $db->table('accounts')->whereIn('base_code', ['4004', '5002'])->delete();
    }
}
