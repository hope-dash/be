<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class FetchKecamatanCommand extends BaseCommand
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
    protected $name = 'data:fetch-kecamatan';

    /**
     * The Command's Description
     *
     * @var string
     */
    protected $description = 'Fetch kecamatan data from API and insert into database';

    /**
     * The Command's Usage
     *
     * @var string
     */
    protected $usage = 'data:fetch-kecamatan';

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
        $table = $db->table('kecamatan');

        $apiKey = 'h3RMOohkHvQUgargFCih4MEkRs2DGYLVuaqv8NsuRJqxO4I7mI';
        $baseUrl = 'https://use.api.co.id/regional/indonesia/districts';

        $totalPage = 73;

        CLI::write("Starting fetch from page 1 to $totalPage...", 'yellow');

        for ($page = 1; $page <= $totalPage; $page++) {
            CLI::print("Fetching page $page... ");

            try {
                $response = $client->get("$baseUrl?page=$page", [
                    'headers' => [
                        'x-api-co-id' => $apiKey,
                        'Accept' => 'application/json',
                    ],
                ]);

                $result = json_decode($response->getBody(), true);

                if (isset($result['is_success']) && $result['is_success'] && isset($result['data'])) {
                    $insertData = [];
                    foreach ($result['data'] as $item) {
                        $insertData[] = [
                            'code' => $item['code'],
                            'name' => $item['name'],
                            'regency_code' => $item['regency_code'],
                            'province_code' => $item['province_code'],
                        ];
                    }

                    if (!empty($insertData)) {
                        $table->ignore(true)->insertBatch($insertData);
                        CLI::write("Done (" . count($insertData) . " items)", 'green');
                    } else {
                        CLI::write("No data found on this page.", 'yellow');
                    }

                    // Update total pages if it's different in the response
                    if (isset($result['paging']['total_page'])) {
                        $totalPage = $result['paging']['total_page'];
                    }
                } else {
                    CLI::write("Error: " . ($result['message'] ?? 'Unknown error'), 'red');
                }
            } catch (\Exception $e) {
                CLI::write("Failed: " . $e->getMessage(), 'red');
            }
        }

        CLI::write("Process complete.", 'cyan');
    }
}
