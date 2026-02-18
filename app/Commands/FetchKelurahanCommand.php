<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class FetchKelurahanCommand extends BaseCommand
{
    /**
     * The Command's Group
     *
     * @var string
     */
    protected $group = 'Data';

    /**
     * The Command's Name
     *
     * @var string
     */
    protected $name = 'data:fetch-kelurahan';

    /**
     * The Command's Description
     *
     * @var string
     */
    protected $description = 'Fetch kelurahan data from API and insert into database';

    /**
     * The Command's Usage
     *
     * @var string
     */
    protected $usage = 'data:fetch-kelurahan';

    /**
     * The Command's Arguments
     *
     * @var array
     */
    protected $arguments = [];

    /**
     * The Command's Options
     *
     * @var array
     */
    protected $options = [];

    /**
     * Actually execute a command.
     *
     * @param array $params
     */
    public function run(array $params)
    {
        $client = \Config\Services::curlrequest();
        $db = \Config\Database::connect();
        $table = $db->table('kelurahan');

        $apiKey = env('API_CO_ID_KEY');
        $baseUrl = 'https://use.api.co.id/regional/indonesia/villages';

        $totalPage = 896;

        CLI::write("Starting fetch from page 1 to $totalPage...", 'yellow');

        for ($page = 1; $page <= $totalPage; $page++) {
            CLI::print("Fetching page $page... ");

            $retryCount = 0;
            $maxRetries = 3;
            $success = false;

            while ($retryCount < $maxRetries && !$success) {
                try {
                    // Check DB connection
                    try {
                        $db->listTables();
                    } catch (\Exception $e) {
                        CLI::write("Reconnecting to database...", 'yellow');
                        $db->reconnect();
                    }

                    $response = $client->get("$baseUrl?page=$page", [
                        'headers' => [
                            'x-api-co-id' => $apiKey,
                            'Accept' => 'application/json',
                        ],
                        'timeout' => 30, // Increase timeout
                    ]);

                    $result = json_decode($response->getBody(), true);

                    if (isset($result['is_success']) && $result['is_success'] && isset($result['data'])) {
                        $insertData = [];
                        foreach ($result['data'] as $item) {
                            $insertData[] = [
                                'code' => $item['code'],
                                'name' => $item['name'],
                                'district_code' => $item['district_code'],
                                'regency_code' => $item['regency_code'],
                                'province_code' => $item['province_code'],
                            ];
                        }

                        if (!empty($insertData)) {
                            $table->ignore(true)->insertBatch($insertData);
                            $success = true;
                            CLI::write("Done (" . count($insertData) . " items)", 'green');
                        } else {
                            $success = true;
                            CLI::write("No data found.", 'yellow');
                        }

                        if (isset($result['paging']['total_page'])) {
                            $totalPage = $result['paging']['total_page'];
                        }

                        // Respect rate limit: 20 req/s = 50ms/req. 
                        // Using 100ms to be safe (10 req/s).
                        usleep(100000);
                    } else {
                        CLI::write("API Error: " . ($result['message'] ?? 'Unknown error'), 'red');
                        $retryCount++;
                        sleep(2);
                    }
                } catch (\Exception $e) {
                    CLI::write("Attempt " . ($retryCount + 1) . " failed: " . $e->getMessage(), 'red');
                    $retryCount++;
                    sleep(2);

                    if (strpos($e->getMessage(), 'gone away') !== false) {
                        $db->reconnect();
                    }
                }
            }

            if (!$success) {
                CLI::write("Skipping page $page after $maxRetries failures.", 'red');
            }
        }

        CLI::write("Process complete.", 'cyan');
    }
}
