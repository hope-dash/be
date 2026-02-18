<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProvinceCitySeeder extends Seeder
{
    public function run()
    {
        $db = \Config\Database::connect();

        // 1. Insert Provinces
        $provincePath = WRITEPATH . 'provinces.json';
        if (file_exists($provincePath)) {
            $provinces = json_decode(file_get_contents($provincePath), true);
            if ($provinces) {
                $db->table('provincy')->ignore(true)->insertBatch($provinces);
                echo "Inserted provinces.\n";
            }
        }

        // 2. Insert Cities (Multiple Parts)
        $cityFiles = glob(WRITEPATH . 'cities_*.json');
        foreach ($cityFiles as $file) {
            $cities = json_decode(file_get_contents($file), true);
            if ($cities) {
                // Filter the data to match table columns (code, province_code, name)
                $dataToInsert = array_map(function ($item) {
                    return [
                        'code' => $item['code'],
                        'province_code' => $item['province_code'],
                        'name' => $item['name']
                    ];
                }, $cities);

                $db->table('kota_kabupaten')->ignore(true)->insertBatch($dataToInsert);
                echo "Inserted cities from " . basename($file) . ".\n";
            }
        }
    }
}
