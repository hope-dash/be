<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class SyncTenjoCity extends BaseCommand
{
    protected $group = 'Data';
    protected $name = 'data:sync-tenjo-city';
    protected $description = 'Sync city data with Tenjo API (pluginongkoskirim.com)';
    protected $usage = 'data:sync-tenjo-city';

    public function run(array $params)
    {
        $client = \Config\Services::curlrequest();
        $db = \Config\Database::connect();
        
        $builder = $db->table('kota_kabupaten');
        $cities = $builder->get()->getResultArray();
        $total = count($cities);
        
        CLI::write("Syncing $total cities...", 'yellow');
        
        $success = 0;
        $failed = 0;

        foreach ($cities as $index => $city) {
            $current = $index + 1;
            $dbName = trim($city['name']);
            
            // Step 1: Handle prefixes as requested
            $searchQuery = $dbName;
            $requiredJenis = null;

            if (preg_match('/^(Kota|Kabupaten|Kab\.?)\s+(.+)$/i', $dbName, $matches)) {
                $searchQuery = trim($matches[2]);
                $kind = strtolower($matches[1]);
                if ($kind === 'kota') {
                    $requiredJenis = 'Kota';
                } else {
                    $requiredJenis = 'Kab.';
                }
            }

            CLI::print("[$current/$total] Syncing '$dbName' (Search: '$searchQuery')... ");
            
            try {
                $url = "https://pluginongkoskirim.com/front/asal?s=" . urlencode($searchQuery);
                
                $response = $client->get($url, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                    ],
                    'timeout' => 10,
                    'http_errors' => false
                ]);
                
                if ($response->getStatusCode() !== 200) {
                    CLI::write("HTTP Error: " . $response->getStatusCode(), 'red');
                    $failed++;
                    continue;
                }

                $body = json_decode($response->getBody(), true);
                $results = $body['data'] ?? [];
                
                if (empty($results) || (isset($results[0]['jenis']) && $results[0]['jenis'] === 'Data tidak ditemukan')) {
                    // Failover if specific search failed, try searching the original dbName
                    if ($searchQuery !== $dbName) {
                        $url = "https://pluginongkoskirim.com/front/asal?s=" . urlencode($dbName);
                        $response = $client->get($url, ['headers' => ['Accept' => 'application/json']]);
                        $body = json_decode($response->getBody(), true);
                        $results = $body['data'] ?? [];
                    }
                    
                    if (empty($results) || (isset($results[0]['jenis']) && $results[0]['jenis'] === 'Data tidak ditemukan')) {
                        CLI::write("API: Data not found", 'yellow');
                        $failed++;
                        continue;
                    }
                }

                $match = null;
                
                // If we have a requiredJenis (stripped from DB name), strict match
                if ($requiredJenis) {
                    foreach ($results as $item) {
                        if (strcasecmp(trim($item['nama']), $searchQuery) === 0 && strcasecmp(trim($item['jenis']), $requiredJenis) === 0) {
                            $match = $item;
                            break;
                        }
                    }
                }

                // If no strict match yet, try exact match with dbName or searchName
                if (!$match) {
                    foreach ($results as $item) {
                        $apiName = trim($item['nama']);
                        if (strcasecmp($apiName, $dbName) === 0 || strcasecmp($apiName, $searchQuery) === 0) {
                            $match = $item;
                            break;
                        }
                    }
                }
                

                if ($match) {
                    $db->table('kota_kabupaten')
                        ->where('id', $city['id'])
                        ->update([
                            'tenjo_id' => $match['id'],
                            'jenis' => $match['jenis']
                        ]);
                    
                    CLI::write("Success (ID: {$match['id']}, Jenis: {$match['jenis']})", 'green');
                    $success++;
                } else {
                    CLI::write("No match found", 'yellow');
                    $failed++;
                }
                
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
