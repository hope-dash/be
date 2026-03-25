<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddInventoryCacatAccounts extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        
        // 1. Ensure Global Account (Template)
        $globalExists = $db->table('accounts')
            ->where('base_code', '1005')
            ->where('id_toko', null)
            ->countAllResults();
            
        if ($globalExists === 0) {
            $db->table('accounts')->insert([
                'tenant_id' => null,
                'id_toko' => null,
                'code' => '1005',
                'base_code' => '1005',
                'name' => 'Persediaan Barang Cacat',
                'type' => 'ASSET',
                'normal_balance' => 'DEBIT',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // 2. Ensure Toko-specific Accounts
        $tokoList = $db->table('toko')->get()->getResultArray();

        foreach ($tokoList as $toko) {
            $tokoId = $toko['id'];
            $tenantId = $toko['tenant_id'];
            
            // Following the pattern: '10' . $idToko . '5'
            $code = '10' . $tokoId . '5';
            
            $exists = $db->table('accounts')
                ->where('code', $code)
                ->countAllResults();

            if ($exists === 0) {
                $db->table('accounts')->insert([
                    'tenant_id' => $tenantId,
                    'id_toko' => $tokoId,
                    'code' => $code,
                    'base_code' => '1005',
                    'name' => 'Persediaan Barang Cacat (' . $toko['toko_name'] . ')',
                    'type' => 'ASSET',
                    'normal_balance' => 'DEBIT',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        $db->table('accounts')->where('base_code', '1005')->delete();
    }
}
