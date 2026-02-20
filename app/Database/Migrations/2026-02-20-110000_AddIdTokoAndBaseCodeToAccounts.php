<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIdTokoAndBaseCodeToAccounts extends Migration
{
    public function up()
    {
        // 1. Alter accounts table
        $fields = [
            'id_toko' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
                'after' => 'id'
            ],
            'base_code' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
                'after' => 'code'
            ],
        ];
        $this->forge->addColumn('accounts', $fields);

        // 2. Remove unique constraint on code
        // and add unique constraint on (code, id_toko) if necessary, 
        // but since codes are unique (1011, 1021 etc), maybe just keep it simple.
        // Actually, the previous 'code' was unique. Let's drop that.
        try {
            $this->db->query('ALTER TABLE accounts DROP INDEX code');
        } catch (\Throwable $e) {
        }

        // Add index for id_toko
        $this->db->query('ALTER TABLE accounts ADD INDEX (id_toko)');
        // Add index for base_code
        $this->db->query('ALTER TABLE accounts ADD INDEX (base_code)');

        // 3. Update existing accounts: base_code = code
        $this->db->query("UPDATE accounts SET base_code = code");

        // 4. Generate accounts for each toko
        $tokoList = $this->db->table('toko')->get()->getResultArray();
        $baseAccounts = $this->db->table('accounts')->where('id_toko', null)->get()->getResultArray();

        foreach ($tokoList as $toko) {
            foreach ($baseAccounts as $acc) {
                $baseCode = $acc['base_code'];
                // Pattern: 10X1 where X is id_toko
                // substr($baseCode, 0, 2) . $toko['id'] . substr($baseCode, 3)
                $newCode = substr($baseCode, 0, 2) . $toko['id'] . substr($baseCode, 3);

                // Check if already exists
                $exists = $this->db->table('accounts')
                    ->where('code', $newCode)
                    ->countAllResults();

                if ($exists == 0) {
                    $this->db->table('accounts')->insert([
                        'id_toko' => $toko['id'],
                        'base_code' => $baseCode,
                        'code' => $newCode,
                        'name' => $acc['name'],
                        'type' => $acc['type'],
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
        // Safety: Remove only the store-specific accounts?
        $this->db->table('accounts')->where('id_toko !=', null)->delete();
        $this->forge->dropColumn('accounts', ['id_toko', 'base_code']);
        // Re-add unique constraint if needed, but usually down is for rollback.
    }
}
