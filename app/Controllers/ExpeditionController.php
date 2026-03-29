<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;
use App\Libraries\TenantContext;

class ExpeditionController extends ResourceController
{
    protected $jsonResponse;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
    }

    /**
     * Get shipping cost from API with caching
     */
    public function getShippingCost()
    {
        try {
            $idToko = $this->request->getGet('id_toko');
            $destination = $this->request->getGet('destination_village_code');
            $requestedWeight = (float)$this->request->getGet('weight');

            // Validations
            if (!$idToko || !$destination || !$requestedWeight) {
                return $this->jsonResponse->error('id_toko, destination_village_code, and weight are required', 400);
            }

            if ($requestedWeight <= 0) {
                return $this->jsonResponse->error('Weight must be greater than 0', 400);
            }

            // Fetch Origin from Toko
            $tokoModel = new \App\Models\TokoModel();
            $toko = $tokoModel->find($idToko);

            if (!$toko) {
                return $this->jsonResponse->error('Toko not found', 404);
            }

            if (empty($toko['kelurahan'])) {
                return $this->jsonResponse->error('Toko does not have a village code (kelurahan) configured', 400);
            }

            $originVillageCode = $toko['kelurahan'];

            // 1. Resolve Tenjo IDs for Origin and Destination
            $originTenjoId = $this->getTenjoIdFromKelurahan($originVillageCode);
            $destinationTenjoId = $this->getTenjoIdKecamatan($destination);

            // Cache key based on Origin & Destination & Weight
            $cacheKey = "shipping_cost_tenjo_{$originTenjoId}_{$destinationTenjoId}_{$requestedWeight}";
            $cachedData = cache($cacheKey);

            if ($cachedData) {
                return $this->jsonResponse->oneResp('Success', json_decode($cachedData, true), 200);
            }

            if ($originTenjoId && $destinationTenjoId) {
                // Hits New API: pluginongkoskirim.com
                $client = \Config\Services::curlrequest();
                $apiUrl = 'https://pluginongkoskirim.com/front/tarif';

                $response = $client->post($apiUrl, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                    ],
                    'json' => [
                        'asal_id' => $originTenjoId,
                        'tujuan_id' => $destinationTenjoId,
                        'berat' => $requestedWeight,
                    ],
                    'http_errors' => false,
                ]);

                $statusCode = $response->getStatusCode();
                $result = json_decode($response->getBody(), true);

                if ($statusCode === 200 && isset($result['data'])) {
                    // Refactor response to match the existing format if needed, 
                    // or just return as is if the frontend expects this format.
                    // The user provided the new structure: data -> array of {nama: JNE, slug: jne, tarif: [...]}

                    // Transform to match old format's structure for backward compatibility if possible
                    // Old format had 'couriers' key. Let's provide a 'couriers' key as well.
                    $formattedData = [
                        'origin' => $originVillageCode,
                        'destination' => $destination,
                        'weight' => $requestedWeight,
                        'tenjo_data' => $result['data'],
                        'couriers' => [] // Map tenjo data to this if helpful
                    ];

                    foreach ($result['data'] as $courier) {
                        $slug = $courier['slug'] ?? $courier['nama'];
                        foreach ($courier['tarif'] as $service) {
                            $formattedData['couriers'][] = [
                                'slug' => $slug,
                                'courier_code' => $slug . "-" . $service['namaLayanan'],
                                'courier_name' => strtoupper($slug) . " - " . $service['namaLayanan'],
                                'price' => (float)str_replace(['Rp ', '.'], '', $service['cost']),
                                'weight' => $requestedWeight,
                                'estimation' => $service['etd']
                            ];
                        }
                    }

                    cache()->save($cacheKey, json_encode($formattedData), 86400); // 1 day cache
                    return $this->jsonResponse->oneResp('Success', $formattedData, 200);
                }
            }

            /* OLD LOGIC (api.co.id) - Commented as requested
             $cacheKeyOld = "shipping_cost_base_{$originVillageCode}_{$destination}";
             $baseData = cache($cacheKeyOld);
             if (!$baseData) {
             $client = \Config\Services::curlrequest();
             $apiKey = env('API_CO_ID_KEY');
             $apiUrl = 'https://use.api.co.id/expedition/shipping-cost';
             $response = $client->get($apiUrl, [
             'headers' => [
             'x-api-co-id' => $apiKey,
             'Accept' => 'application/json',
             ],
             'query' => [
             'origin_village_code' => $originVillageCode,
             'destination_village_code' => $destination,
             'weight' => 1,
             ],
             'http_errors' => false,
             ]);
             $statusCode = $response->getStatusCode();
             $result = json_decode($response->getBody(), true);
             if ($statusCode === 200 && isset($result['is_success']) && $result['is_success']) {
             $baseData = $result['data'] ?? $result;
             cache()->save($cacheKeyOld, json_encode($baseData), 1209600);
             } else {
             return $this->jsonResponse->error($result['message'] ?? 'Failed to fetch shipping cost', $statusCode);
             }
             } else {
             $baseData = json_decode($baseData, true);
             }
             if (isset($baseData['couriers'])) {
             foreach ($baseData['couriers'] as &$courier) {
             $courier['price'] = $courier['price'] * $requestedWeight;
             $courier['weight'] = $requestedWeight;
             }
             }
             $baseData['weight'] = $requestedWeight;
             return $this->jsonResponse->oneResp('Success', $baseData, 200);
             */

            return $this->jsonResponse->error('Unable to calculate shipping cost via new API (Tenjo ID mapping missing)', 400);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    private function getTenjoIdFromKelurahan($kelurahanCode)
    {
        $db = \Config\Database::connect();
        $builder = $db->table('kelurahan');
        $kelurahan = $builder->select('regency_code')->where('code', $kelurahanCode)->get()->getRowArray();

        if (!$kelurahan)
            return null;

        $city = $db->table('kota_kabupaten')->select('tenjo_id')->where('code', $kelurahan['regency_code'])->get()->getRowArray();

        return $city['tenjo_id'] ?? null;
    }

    private function getTenjoIdKecamatan($kelurahanCode)
    {
        $db = \Config\Database::connect();
        $builder = $db->table('kelurahan');
        $kecamatan = $builder->select('district_code')->where('code', $kelurahanCode)->get()->getRowArray();

        if (!$kecamatan)
            return null;

        $district = $db->table('kecamatan')->select('tenjo_id')->where('code', $kecamatan['district_code'])->get()->getRowArray();

        return $district['tenjo_id'] ?? null;
    }
}