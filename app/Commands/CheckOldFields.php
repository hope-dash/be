<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class CheckOldFields extends BaseCommand
{
    protected $group = 'Migration';
    protected $name = 'migrate:check-fields';
    protected $description = 'Check field names in old DB';

    public function run(array $params)
    {
        $dbOld = Database::connect('old');
        $trFields = $dbOld->getFieldNames('transaction');
        $spFields = $dbOld->getFieldNames('sales_product');
        $metaFields = $dbOld->getFieldNames('transaction_meta');

        CLI::write("Transaction: " . implode(', ', $trFields));
        CLI::write("SalesProduct: " . implode(', ', $spFields));
        CLI::write("Meta: " . implode(', ', $metaFields));
    }
}
