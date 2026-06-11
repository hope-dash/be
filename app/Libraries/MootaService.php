<?php

namespace App\Libraries;

use Exception;

class MootaService
{
    private string $token;
    private string $secret;
    private string $baseUrl = "https://app.moota.co/api/v2";

    public function __construct()
    {
        $this->token = getenv('MOOTA_TOKEN') ?: '';
        $this->secret = getenv('MOOTA_SECRET') ?: '';
    }

    /**
     * Send HTTP request to Moota API
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        if (empty($this->token)) {
            throw new Exception("Moota token (MOOTA_TOKEN) is not configured in .env file.");
        }

        $url = $this->baseUrl . $endpoint;
        $curl = curl_init();

        $headers = [
            "Authorization: Bearer " . $this->token,
            "Accept: application/json",
            "Content-Type: application/json"
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ];

        $method = strtoupper($method);
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            if (!empty($data)) {
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            $options[CURLOPT_URL] = $url;
        }

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            throw new Exception("Moota API Connection Error: " . $err);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = $decoded['message'] ?? "Moota API responded with HTTP code " . $httpCode;
            throw new Exception($message);
        }

        return $decoded ?: [];
    }

    /**
     * Verify Webhook Signature using MOOTA_SECRET
     */
    public function verifySignature(string $rawPayload, string $signatureHeader): bool
    {
        if (empty($this->secret)) {
            return false;
        }

        $localSignature = hash_hmac('sha256', $rawPayload, $this->secret);

        return hash_equals($localSignature, $signatureHeader);
    }

    /**
     * Store Bank Account to Moota
     * POST /bank/store
     */
    public function addBankAccount(array $data): array
    {
        return $this->request('POST', '/bank/store', $data);
    }

    /**
     * Get Registered Bank Accounts in Moota
     * GET /bank
     */
    public function getBankAccounts(array $filters = []): array
    {
        return $this->request('GET', '/bank', $filters);
    }

    /**
     * Get Mutations from Moota
     * GET /mutation
     */
    public function getMutations(array $filters = []): array
    {
        return $this->request('GET', '/mutation', $filters);
    }

    /**
     * Attach webhook to mutation or register webhook (general helper)
     */
    public function createWebhook(string $mutationId, array $data): array
    {
        return $this->request('POST', "/mutation/{$mutationId}/webhook", $data);
    }
}
