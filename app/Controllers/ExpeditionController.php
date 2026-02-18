<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;

class ExpeditionController extends ResourceController
{
    protected $jsonResponse;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();
    }

    /**
     * Get shipping cost from API with caching (Always 1kg base)
     */
    public function getShippingCost()
    {
        try {
            $origin = $this->request->getGet('origin_village_code');
            $destination = $this->request->getGet('destination_village_code');
            $requestedWeight = (float) $this->request->getGet('weight');

            // Validations
            if (!$origin || !$destination || !$requestedWeight) {
                return $this->jsonResponse->error('Origin, destination, and weight are required', 400);
            }

            if ($requestedWeight <= 0) {
                return $this->jsonResponse->error('Weight must be greater than 0', 400);
            }

            // Cache key based on Origin & Destination only (Base 1kg)
            $cacheKey = "shipping_cost_base_{$origin}_{$destination}";

            // Check Cache
            $baseData = cache($cacheKey);

            if (!$baseData) {
                // If not in cache, hit API always with weight = 1
                $client = \Config\Services::curlrequest();
                $apiKey = env('API_CO_ID_KEY');
                $apiUrl = 'https://use.api.co.id/expedition/shipping-cost';

                $response = $client->get($apiUrl, [
                    'headers' => [
                        'x-api-co-id' => $apiKey,
                        'Accept' => 'application/json',
                    ],
                    'query' => [
                        'origin_village_code' => $origin,
                        'destination_village_code' => $destination,
                        'weight' => 1, // Always 1kg
                    ],
                    'http_errors' => false,
                ]);

                $statusCode = $response->getStatusCode();
                $body = $response->getBody();
                $result = json_decode($body, true);

                if ($statusCode === 200 && isset($result['is_success']) && $result['is_success']) {
                    // Save base data (1kg) to cache for 2 weeks (1,209,600 seconds)
                    $baseData = $result['data'] ?? $result;
                    cache()->save($cacheKey, json_encode($baseData), 1209600);
                } else {
                    return $this->jsonResponse->error($result['message'] ?? 'Failed to fetch shipping cost', $statusCode);
                }
            } else {
                $baseData = json_decode($baseData, true);
            }

            // Calculate final price based on requested weight
            if (isset($baseData['couriers'])) {
                foreach ($baseData['couriers'] as &$courier) {
                    $courier['price'] = $courier['price'] * $requestedWeight;
                    $courier['weight'] = $requestedWeight;
                }
            }

            $baseData['weight'] = $requestedWeight;

            return $this->jsonResponse->oneResp('Success', $baseData, 200);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
