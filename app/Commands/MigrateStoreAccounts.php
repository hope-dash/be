<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class MigrateStoreAccounts extends BaseCommand
{
    protected $group = 'Migration';
    protected $name = 'migrate:store-accounts';
    protected $description = 'Migrate/Initialize standard accounts for all stores based on templates';

    public function run(array $params)
    {
        $db = Database::connect();
        
        // 1. Fetch all stores
        $tokoList = $db->table('toko')->get()->getResultArray();
        if (empty($tokoList)) {
            CLI::error("No stores found in 'toko' table.");
            return;
        }

        // 2. Define standard template accounts
        // pattern: (code_prefix) (toko_id) (suffix)
        $templates = [
            // ASSETS
            ['base_code' => '1001', 'name' => 'Cash', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['base_code' => '1002', 'name' => 'Bank', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['base_code' => '1003', 'name' => 'Piutang usaha', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['base_code' => '1004', 'name' => 'Inventaris', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['base_code' => '1007', 'name' => 'Persediaan Barang Cacat', 'type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            
            // LIABILITIES
            ['base_code' => '2001', 'name' => 'Hutang Usaha', 'type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            
            // EQUITY
            ['base_code' => '3001', 'name' => 'Ekuitas Pemilik', 'type' => 'EQUITY', 'normal_balance' => 'CREDIT'],
            ['base_code' => '3002', 'name' => 'Bagi hasil', 'type' => 'EQUITY', 'normal_balance' => 'DEBIT'],
            
            // REVENUE
            ['base_code' => '4001', 'name' => 'Pendapatan Penjualan', 'type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['base_code' => '4002', 'name' => 'Diskon Penjualan', 'type' => 'REVENUE', 'normal_balance' => 'DEBIT'],
            ['base_code' => '4003', 'name' => 'Pengembalian Penjualan', 'type' => 'REVENUE', 'normal_balance' => 'DEBIT'],
            
            // COGS & EXPENSE
            ['base_code' => '5001', 'name' => 'Harga Pokok Penjualan', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['base_code' => '6001', 'name' => 'Beban Operasional', 'type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
        ];

        CLI::write("Initializing accounts for " . count($tokoList) . " stores...", 'yellow');

        foreach ($tokoList as $toko) {
            $tokoId = $toko['id'];
            $tenantId = $toko['tenant_id'];
            $tokoName = $toko['toko_name'];

            CLI::write("Processing Toko: $tokoName (ID: $tokoId)", 'cyan');

            foreach ($templates as $tpl) {
                // Generate specific code: first 2 digits + toko_id + last digit
                // e.g. 10(1)1, 30(1)2
                $prefix = substr($tpl['base_code'], 0, 2);
                $suffix = substr($tpl['base_code'], -1);
                $specificCode = $prefix . $tokoId . $suffix;
                $specificName = $tpl['name'] . ' (' . $tokoName . ')';

                // Check if already exists
                $exists = $db->table('accounts')
                    ->where('code', $specificCode)
                    ->countAllResults();

                if ($exists === 0) {
                    $db->table('accounts')->insert([
                        'tenant_id' => $tenantId,
                        'id_toko' => $tokoId,
                        'code' => $specificCode,
                        'base_code' => $tpl['base_code'],
                        'name' => $specificName,
                        'type' => $tpl['type'],
                        'normal_balance' => $tpl['normal_balance'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    // CLI::write("  + Created: $specificCode - $specificName", 'green');
                } else {
                    // CLI::write("  . Skipped: $specificCode (already exists)", 'white');
                }
            }
        }

        CLI::write("Account migration complete!", 'green');
    }
}
