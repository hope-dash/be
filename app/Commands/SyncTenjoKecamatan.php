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
        $kecamatans = $builder->where('tenjo_id', null)->get()->getResultArray();
        $total = count($kecamatans);

        CLI::write("Syncing $total kecamatans...", 'yellow');

        $success = 0;
        $failed = 0;
        $batchSize = 25; // Konkurensi 25 request per batch
        $chunks = array_chunk($kecamatans, $batchSize);
        $processed = 0;

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $index => $kec) {
                $dbName = trim($kec['name']);

                // Clean name from "(...)" if present
                $searchQuery = $dbName;
                if (preg_match('/^"(.+)\s+\((.+)\)$/i', $dbName, $matches)) {
                    $searchQuery = trim($matches[1]);
                }
                elseif (preg_match('/^(.+)\s+\((.+)\)$/i', $dbName, $matches)) {
                    $searchQuery = trim($matches[1]);
                }
                $searchQuery = ltrim($searchQuery, '"');

                $url = "https://pluginongkoskirim.com/front/tujuan?s=" . urlencode($searchQuery);
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                    ]
                ]);

                curl_multi_add_handle($mh, $ch);
                $handles[] = [
                    'ch' => $ch,
                    'kec' => $kec,
                    'dbName' => $dbName,
                    'searchQuery' => $searchQuery
                ];
            }

            // Jalankan request secara bersamaan
            $active = null;
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);

            while ($active && $mrc == CURLM_OK) {
                if (curl_multi_select($mh) != -1) {
                    do {
                        $mrc = curl_multi_exec($mh, $active);
                    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
                }
            }

            // Proses hasil response
            foreach ($handles as $itemInfo) {
                $processed++;
                $ch = $itemInfo['ch'];
                $dbName = $itemInfo['dbName'];
                $searchQuery = $itemInfo['searchQuery'];
                $kec = $itemInfo['kec'];

                $responseContent = curl_multi_getcontent($ch);
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);

                CLI::print("[$processed/$total] Syncing '$dbName' (Search: '$searchQuery')... ");

                if ($statusCode !== 200) {
                    CLI::write("HTTP Error: " . $statusCode, 'red');
                    $failed++;
                    continue;
                }

                $body = json_decode($responseContent, true);
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
                        ->update(['tenjo_id' => $match['id']]);

                    CLI::write("Success (ID: {$match['id']})", 'green');
                    $success++;
                }
                else {
                    CLI::write("No exact match found", 'yellow');
                    $failed++;
                }
            }

            curl_multi_close($mh);

            // Jeda antar batch untuk keamanan dari rate limit
            usleep(500000); // 0.5s delay
        }

        CLI::write("Process complete.", 'cyan');
        CLI::write("Success: $success, Failed: $failed", 'white');
    }
}