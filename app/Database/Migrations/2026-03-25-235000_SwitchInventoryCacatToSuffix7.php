<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SwitchInventoryCacatToSuffix7 extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        
        // 1. Ensure Global Account 1007
        $globalExists = $db->table('accounts')
            ->where('base_code', '1007')
            ->where('id_toko', null)
            ->countAllResults();
            
        if ($globalExists === 0) {
            $db->table('accounts')->insert([
                'tenant_id' => null,
                'id_toko' => null,
                'code' => '1007',
                'base_code' => '1007',
                'name' => 'Persediaan Barang Cacat',
                'type' => 'ASSET',
                'normal_balance' => 'DEBIT',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // 2. Ensure Toko-specific Accounts 10x7
        $tokoList = $db->table('toko')->get()->getResultArray();

        foreach ($tokoList as $toko) {
            $tokoId = $toko['id'];
            $tenantId = $toko['tenant_id'];
            $code = '10' . $tokoId . '7';
            
            $exists = $db->table('accounts')
                ->where('code', $code)
                ->countAllResults();

            if ($exists === 0) {
                $db->table('accounts')->insert([
                    'tenant_id' => $tenantId,
                    'id_toko' => $tokoId,
                    'code' => $code,
                    'base_code' => '1007',
                    'name' => 'Persediaan Barang Cacat (' . $toko['toko_name'] . ')',
                    'type' => 'ASSET',
                    'normal_balance' => 'DEBIT',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // 3. Clean up the accidental 1005 accounts IF they were named "Persediaan Barang Cacat"
        // (to avoid breaking genuine Transit accounts)
        $db->table('accounts')
            ->where('base_code', '1005')
            ->like('name', 'Persediaan Barang Cacat')
            ->delete();
    }

    public function down()
    {
        $db = \Config\Database::connect();
        $db->table('accounts')->where('base_code', '1007')->delete();
    }
}
