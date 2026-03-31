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

        CLI::write("Initializing accounts for " . count($tokoList) . " stores...", 'yellow');

        foreach ($tokoList as $toko) {
            $tokoId = $toko['id'];
            $tenantId = $toko['tenant_id'];
            $tokoName = $toko['toko_name'];

            CLI::write("Processing Toko: $tokoName (ID: $tokoId)", 'cyan');

            // 2. Fetch templates from database (where tenant_id and id_toko are NULL)
            $templates = $db->table('accounts')
                ->where('tenant_id', null)
                ->where('id_toko', null)
                ->get()
                ->getResultArray();

            if (empty($templates)) {
                CLI::error("  ! No template accounts found in 'accounts' table (tenant_id & id_toko NULL).");
                continue;
            }

            foreach ($templates as $tpl) {
                // Generate specific code: first 2 digits + toko_id + last digit
                $baseCode = $tpl['base_code'] ?? $tpl['code'];
                $prefix = substr($baseCode, 0, 2);
                $suffix = substr($baseCode, 2); // get everything after prefix
                $specificCode = $prefix . $tokoId . $suffix;
                $specificName = $tpl['name'] . ' ' . $tokoName;
                // Check if already exists
                $exists = $db->table('accounts')
                    ->where('code', $specificCode)
                    ->where('tenant_id', $tenantId)
                    ->countAllResults();

                if ($exists === 0) {
                    $db->table('accounts')->insert([
                        'tenant_id' => $tenantId,
                        'id_toko' => $tokoId,
                        'code' => $specificCode,
                        'base_code' => $baseCode,
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
