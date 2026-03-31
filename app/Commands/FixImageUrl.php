<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class FixImageUrl extends BaseCommand
{
    protected $group = 'Database';
    protected $name = 'db:fix-image-url';
    protected $description = 'Prefix all image URLs with the full domain';

    public function run(array $params)
    {
        $db = Database::connect();
        
        CLI::write("Updating image URLs...", 'yellow');
        
        $sql = "UPDATE image SET url = CONCAT('https://api.hope-sparepart.com/', url) WHERE url NOT LIKE 'https://%'";
        
        $db->query($sql);
        
        $affected = $db->affectedRows();
        
        CLI::write("Success! Affected rows: $affected", 'green');
    }
}
