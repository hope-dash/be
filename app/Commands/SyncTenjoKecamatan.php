<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class SyncTenjoKecamatan extends BaseCommand
{
    protected $group = 'Data';
    protected $name = 'data:sync-tenjo-kecamatan';
    protected $description = 'Sync kecamatan data with Tenjo API (pluginongkoskirim.com)';
    protected $usage = 'data:sync-tenjo-kecamatan';

    public function run(array $params)
    {
        $client = \Config\Services::curlrequest();
        $db = \Config\Database::connect();
        
        $builder = $db->table('kecamatan');
        $kecamatans = $builder->get()->getResultArray();
        $total = count($kecamatans);
        
        CLI::write("Syncing $total kecamatans...", 'yellow');
        
        $success = 0;
        $failed = 0;

        foreach ($kecamatans as $index => $kec) {
            $current = $index + 1;
            $dbName = trim($kec['name']);
            
            // Clean name from "(...)" if present
            $searchQuery = $dbName;
            if (preg_match('/^"(.+)\s+\((.+)\)$/i', $dbName, $matches)) {
                $searchQuery = trim($matches[1]);
            } elseif (preg_match('/^(.+)\s+\((.+)\)$/i', $dbName, $matches)) {
                $searchQuery = trim($matches[1]);
            }
            $searchQuery = ltrim($searchQuery, '"');

            CLI::print("[$current/$total] Syncing '$dbName' (Search: '$searchQuery')... ");
            
            try {
                // Endpoint untuk kecamatan/tujuan
                $url = "https://pluginongkoskirim.com/front/tujuan?s=" . urlencode($searchQuery);
                
                $response = $client->get($url, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                    ],
                    'timeout' => 15,
                    'http_errors' => false
                ]);
                
                if ($response->getStatusCode() !== 200) {
                    CLI::write("HTTP Error: " . $response->getStatusCode(), 'red');
                    $failed++;
                    continue;
                }

                $body = json_decode($response->getBody(), true);
                $results = $body['data'] ?? [];
                
                if (empty($results)) {
                    CLI::write("API: Data not found", 'yellow');
                    $failed++;
                    continue;
                }

                $match = null;
                
                // Strict match: cari yang namanya benar-benar sama (case insensitive)
                foreach ($results as $item) {
                    $apiName = trim($item['nama']);
                    if (strcasecmp($apiName, $searchQuery) === 0 || strcasecmp($apiName, $dbName) === 0) {
                        $match = $item;
                        break;
                    }
                }

                if ($match) {
                    $db->table('kecamatan')
                        ->where('id', $kec['id'])
                        ->update([
                            'tenjo_id' => $match['id']
                        ]);
                    
                    CLI::write("Success (ID: {$match['id']})", 'green');
                    $success++;
                } else {
                    CLI::write("No exact match found", 'yellow');
                    $failed++;
                }
                
                // Jeda untuk menghindari rate limit
                usleep(200000); // 0.2s delay
                
            } catch (\Exception $e) {
                CLI::write("Error: " . $e->getMessage(), 'red');
                $failed++;
            }
        }
        
        CLI::write("Process complete.", 'cyan');
        CLI::write("Success: $success, Failed: $failed", 'white');
    }
}
