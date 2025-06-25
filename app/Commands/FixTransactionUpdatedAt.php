<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class FixTransactionUpdatedAt extends BaseCommand
{
    protected $group = 'Maintenance';
    protected $name = 'fix:updated-at';
    protected $description = 'Fix transaction.updated_at based on earliest cashflow date_time per invoice';

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        $transactions = $db->table('transaction')
            ->select('id, invoice')
            ->where('status !=', 'waiting_payment')
            ->get()->getResult();

        $total = count($transactions);
        CLI::write("Processing $total transactions...", 'yellow');

        foreach ($transactions as $trx) {
            $invoice = $trx->invoice;

            $cashflow = $db->table('cashflow')
                ->where("noted LIKE", '%' . $invoice)
                ->orderBy('date_time', 'asc')
                ->get(1)->getRow();

            if ($cashflow && $cashflow->date_time) {
                $db->table('transaction')
                    ->where('id', $trx->id)
                    ->update([
                        'updated_at' => $cashflow->date_time
                    ]);

                CLI::write("✔ Updated {$trx->invoice} to {$cashflow->date_time}", 'green');
            } else {
                CLI::write("✘ Cashflow not found for {$trx->invoice}", 'red');
            }
        }

        CLI::write("Done.", 'light_blue');
    }
}
