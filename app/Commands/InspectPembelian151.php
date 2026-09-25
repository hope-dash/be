<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InspectPembelian151 extends BaseCommand
{
    protected $group = 'Pembelian';
    protected $name = 'pembelian:inspect-151';
    protected $description = 'Inspect Pembelian 151 logs and ledgers';

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        CLI::write("=== Checking log_aktivitas >= 114870 ===", "yellow");
        $logs = $db->table('log_aktivitas')
            ->where('id >=', 114870)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        CLI::write("Found " . count($logs) . " logs:", "green");
        foreach ($logs as $l) {
            CLI::write("ID: {$l['id']} | Tenant: " . var_export($l['tenant_id'], true) . " | Action: {$l['action_type']} | Table: {$l['target_table']} | Target: {$l['target_id']} | User: {$l['user_id']} | Desc: {$l['description']}");
        }

        CLI::write("\n=== Checking stock_ledgers ===", "yellow");
        $ledgers = $db->table('stock_ledgers')
            ->where('reference_id', 151)
            ->get()->getResultArray();

        CLI::write("Found " . count($ledgers) . " ledgers:", "green");
        $types = [];
        $tenants = [];
        $tokos = [];
        foreach ($ledgers as $sl) {
            $types[$sl['reference_type']] = ($types[$sl['reference_type']] ?? 0) + 1;
            $tenants[var_export($sl['tenant_id'], true)] = ($tenants[var_export($sl['tenant_id'], true)] ?? 0) + 1;
            $tokos[$sl['id_toko']] = ($tokos[$sl['id_toko']] ?? 0) + 1;
        }
        print_r(['types' => $types, 'tenants' => $tenants, 'tokos' => $tokos]);

        CLI::write("\nSample stock_ledger entry:", "yellow");
        if (!empty($ledgers)) {
            print_r(array_slice($ledgers, 0, 3));
        }

        CLI::write("\n=== Checking journals ===", "yellow");
        $journals = $db->table('journals')
            ->where('reference_id', 151)
            ->get()->getResultArray();
        print_r($journals);
    }
}
