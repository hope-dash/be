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
     * Get shipping cost from API with caching
     */
    public function getShippingCost()
    {
        try {
            $origin = $this->request->getGet('origin_village_code');
            $destination = $this->request->getGet('destination_village_code');
            $weight = $this->request->getGet('weight');

            // Validations
            if (!$origin || !$destination || !$weight) {
                return $this->jsonResponse->error('Origin, destination, and weight are required', 400);
            }

            if ($weight <= 0) {
                return $this->jsonResponse->error('Weight must be greater than 0', 400);
            }

            // Define cache key
            $cacheKey = "shipping_cost_{$origin}_{$destination}_{$weight}";

            // Check Cache first
            if ($cachedData = cache($cacheKey)) {
                return $this->jsonResponse->success('Success (from cache)', json_decode($cachedData, true), 200);
            }

            // If not in cache, hit API
            $client = \Config\Services::curlrequest();
            $apiKey = 'h3RMOohkHvQUgargFCih4MEkRs2DGYLVuaqv8NsuRJqxO4I7mI';
            $baseUrl = 'https://use.api.co.id/expedition/shipping-cost';

            $response = $client->get($baseUrl, [
                'headers' => [
                    'x-api-co-id' => $apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    'origin_village_code' => $origin,
                    'destination_village_code' => $destination,
                    'weight' => $weight,
                ],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody();
            $result = json_decode($body, true);

            if ($statusCode === 200 && isset($result['is_success']) && $result['is_success']) {
                // Save to cache for 24 hours (86400 seconds)
                cache()->save($cacheKey, $body, 86400);
                return $this->jsonResponse->success('Success', $result['data'] ?? $result, 200);
            }

            return $statusCode === 200
                ? $this->response->setJSON($result)
                : $this->jsonResponse->error($result['message'] ?? 'Failed to fetch shipping cost', $statusCode);

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
