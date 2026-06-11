<?php

namespace App\Libraries;

use App\Models\TokoMetaModel;
use Exception;

class MootaService
{
    private string $token = '';
    private string $secret = '';
    private string $baseUrl = "https://app.moota.co/api/v2";
    private TokoMetaModel $tokoMetaModel;

    public function __construct()
    {
        $this->token = getenv('MOOTA_TOKEN') ?: '';
        $this->secret = getenv('MOOTA_SECRET') ?: '';
        $this->tokoMetaModel = new TokoMetaModel();
    }

    /**
     * Initialize Moota credentials for a specific Toko (Shop/Application)
     */
    public function initializeForToko(int $idToko): self
    {
        $token = $this->tokoMetaModel->getMeta($idToko, 'moota_token');
        $secret = $this->tokoMetaModel->getMeta($idToko, 'moota_secret');

        if (!empty($token)) {
            $this->token = $token;
        }
        if (!empty($secret)) {
            $this->secret = $secret;
        }

        return $this;
    }

    /**
     * Send HTTP request to Moota API
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        if (empty($this->token)) {
            throw new Exception("Moota token is not configured for this application.");
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
     * Verify Webhook Signature using MOOTA_SECRET and automatically load credentials for matching Toko
     */
    public function verifyAndLoadWebhookConfig(string $rawPayload, string $signatureHeader): ?int
    {
        // 1. Try global secret first
        if (!empty($this->secret)) {
            $localSignature = hash_hmac('sha256', $rawPayload, $this->secret);
            if (hash_equals($localSignature, $signatureHeader)) {
                return 0; // Configured globally in .env (return 0 or main toko)
            }
        }

        // 2. Search all Toko Meta secrets to match signature
        $secrets = $this->tokoMetaModel->where('meta_key', 'moota_secret')->findAll();
        foreach ($secrets as $row) {
            $tokoSecret = $row['meta_value'];
            if (empty($tokoSecret)) {
                continue;
            }

            $localSignature = hash_hmac('sha256', $rawPayload, $tokoSecret);
            if (hash_equals($localSignature, $signatureHeader)) {
                $idToko = (int)$row['id_toko'];
                // Load token and secret for this specific shop
                $this->initializeForToko($idToko);
                return $idToko;
            }
        }

        return null; // No match found
    }

    /**
     * Store Bank Account to Moota
     */
    public function addBankAccount(array $data): array
    {
        return $this->request('POST', '/bank/store', $data);
    }

    /**
     * Get Registered Bank Accounts in Moota
     */
    public function getBankAccounts(array $filters = []): array
    {
        return $this->request('GET', '/bank', $filters);
    }

    /**
     * Get Mutations from Moota
     */
    public function getMutations(array $filters = []): array
    {
        return $this->request('GET', '/mutation', $filters);
    }
}
