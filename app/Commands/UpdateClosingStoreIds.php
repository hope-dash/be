<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class UpdateClosingStoreIds extends BaseCommand
{
    protected $group = 'Database';
    protected $name = 'closing:update-store-ids';
    protected $description = 'Updates id_toko in transaction_closing from related transactions';

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        CLI::write('Starting store ID update for transaction_closing records...', 'blue');

        // Get all transaction_closing records with NULL id_toko
        $builder = $db->table('transaction_closing')
            ->select('id, transaction_id')
            ->where('id_toko IS NULL');

        $results = $builder->get()->getResult();

        if (empty($results)) {
            CLI::write('No transaction_closing records with NULL id_toko found.', 'green');
            return;
        }

        $total = count($results);
        CLI::write("Found {$total} records to update", 'yellow');

        $updated = 0;
        $errors = 0;

        foreach ($results as $row) {
            try {
                // Get the transaction to find id_toko
                $transaction = $db->table('transaction')
                    ->select('id_toko')
                    ->where('id', $row->transaction_id)
                    ->get()
                    ->getRow();

                if ($transaction && $transaction->id_toko) {
                    $db->table('transaction_closing')
                        ->where('id', $row->id)
                        ->update(['id_toko' => $transaction->id_toko]);

                    $updated++;

                    if ($updated % 100 === 0) {
                        CLI::showProgress($updated, $total);
                    }
                } else {
                    CLI::write("Warning: No id_toko found for transaction ID {$row->transaction_id}", 'yellow');
                    $errors++;
                }
            } catch (\Exception $e) {
                CLI::error("Error updating record ID {$row->id}: " . $e->getMessage());
                $errors++;
            }
        }

        CLI::showProgress($updated, $total); // Final progress update
        CLI::newLine();

        CLI::write("Update completed!", 'green');
        CLI::write("Successfully updated: {$updated} records", 'green');
        CLI::write("Records with issues: {$errors}", $errors > 0 ? 'yellow' : 'green');

        if ($errors > 0) {
            CLI::write('Note: Some records could not be updated. Check the warnings above.', 'yellow');
        }
    }
}