<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class MigrateOldData extends BaseCommand
{
    protected $group       = 'Migration';
    protected $name        = 'migrate:old-data';
    protected $description = 'Migrate data from old database to new database with column auto-matching';

    public function run(array $params)
    {
        $dbOld = Database::connect('old');
        $dbNew = Database::connect('default');

        $tables = [
            'model_barang',
            'image',
            'customer',
            'product',
            'seri',
            'suplier',
        ];

        foreach ($tables as $tableName) {
            CLI::write("Migrating table: $tableName ...", 'yellow');

            try {
                $oldColumns = $dbOld->getFieldNames($tableName);
            } catch (\Exception $e) {
                CLI::error("Table $tableName missing in old DB.");
                continue;
            }

            try {
                $newColumns = $dbNew->getFieldNames($tableName);
            } catch (\Exception $e) {
                CLI::error("Table $tableName missing in new DB.");
                continue;
            }

            CLI::write("Old columns: " . implode(', ', $oldColumns), 'cyan');
            CLI::write("New columns: " . implode(', ', $newColumns), 'cyan');

            $oldData = $dbOld->table($tableName)->get()->getResultArray();
            $count = count($oldData);
            CLI::write("Found $count records in old DB.", 'cyan');

            if ($count > 0) {
                $newData = [];
                foreach ($oldData as $row) {
                    $item = [];

                    // 1. Column Auto-Matching
                    foreach ($newColumns as $colName) {
                        if ($colName === 'tenant_id') {
                            $item[$colName] = 1; // Default tenant_id to 1 as requested
                        } elseif (in_array($colName, $oldColumns) && isset($row[$colName])) {
                            $item[$colName] = $row[$colName];
                        } else {
                            // Column exists in new but not in old
                            // We set to NULL, except if it's already handled (like tenant_id)
                            if (!isset($item[$colName])) {
                                $item[$colName] = null;
                            }
                        }
                    }

                    // Always ensure ID is preserved if possible
                    if (in_array('id', $oldColumns) && in_array('id', $newColumns)) {
                        $item['id'] = $row['id'];
                    }

                    $newData[] = $item;
                }

                $dbNew->query('SET FOREIGN_KEY_CHECKS=0');
                
                $success = true;
                $chunks = array_chunk($newData, 100);
                foreach ($chunks as $chunk) {
                    try {
                        // Use ignore to handle duplicate PKs if re-running
                        // insertBatch returns the number of rows inserted, or FALSE
                        $insertResult = $dbNew->table($tableName)->ignore(true)->insertBatch($chunk);
                        if ($insertResult === false) {
                            $success = false;
                            $err = $dbNew->error();
                            CLI::error("Insert error in $tableName: " . ($err['message'] ?? 'Unknown error'));
                        }
                    } catch (\Exception $e) {
                        $success = false;
                        CLI::error("Exception in $tableName: " . $e->getMessage());
                    }
                }
                
                $dbNew->query('SET FOREIGN_KEY_CHECKS=1');

                if ($success) {
                    CLI::write("Successfully migrated $tableName.", 'green');
                } else {
                    CLI::error("Partial success/failure in $tableName. Check errors above.");
                }
            } else {
                CLI::write("No data found for $tableName in old DB.");
            }
        }

        CLI::write("Migration completed successfully!", 'green');
    }
}
