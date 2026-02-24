<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPpnAccounts extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        $tokoList = $db->table('toko')->get()->getResultArray();

        $baseAccounts = [
            [
                'base_code' => '1006',
                'name' => 'PPN Masukan',
                'type' => 'ASSET',
                'normal_balance' => 'DEBIT'
            ],
            [
                'base_code' => '2005',
                'name' => 'PPN Keluaran',
                'type' => 'LIABILITY',
                'normal_balance' => 'CREDIT'
            ]
        ];

        foreach ($baseAccounts as $acc) {
            // Check if base account exists
            $exists = $db->table('accounts')->where('base_code', $acc['base_code'])->where('id_toko', null)->countAllResults();
            if ($exists == 0) {
                $db->table('accounts')->insert([
                    'base_code' => $acc['base_code'],
                    'code' => $acc['base_code'],
                    'name' => $acc['name'] . ' Pusat',
                    'type' => $acc['type'],
                    'normal_balance' => $acc['normal_balance'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Create for each toko
            foreach ($tokoList as $toko) {
                $newCode = substr($acc['base_code'], 0, 2) . $toko['id'] . substr($acc['base_code'], 3);
                $newName = $acc['name'] . ' ' . $toko['toko_name'];

                $existsToko = $db->table('accounts')->where('base_code', $acc['base_code'])->where('id_toko', $toko['id'])->countAllResults();
                if ($existsToko == 0) {
                    $db->table('accounts')->insert([
                        'id_toko' => $toko['id'],
                        'base_code' => $acc['base_code'],
                        'code' => $newCode,
                        'name' => $newName,
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
        $db = \Config\Database::connect();
        $db->table('accounts')->whereIn('base_code', ['1006', '2005'])->delete();
    }
}
