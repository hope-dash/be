<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class SyncTransactionTotals extends BaseCommand
{
    protected $group = 'Custom';
    protected $name = 'sync:transaction-totals';
    protected $description = 'Update actual_total dan total_modal dari sales_product ke transaction';

    public function run(array $params)
    {
        $db = Database::connect();

        $transactions = $db->table('transaction')->get()->getResult();

        foreach ($transactions as $trx) {
            $sales = $db->table('sales_product')
                ->select('SUM(actual_total) as total_actual, SUM(total_modal) as total_modal')
                ->where('id_transaction', $trx->id)
                ->get()->getRow();

            $data = [
                'actual_total' => $sales->total_actual ?? 0,
                'total_modal' => $sales->total_modal ?? 0,
            ];

            $db->table('transaction')->update($data, ['id' => $trx->id]);

            CLI::write("Updated transaction ID {$trx->id}: actual_total = {$data['actual_total']}, total_modal = {$data['total_modal']}", 'green');
        }

        CLI::write("Semua transaksi selesai diupdate.", 'yellow');
    }
}
