<?php

namespace App\Libraries;

class VercelDeploy
{
    private string $token;
    private string $adminProjectId;
    private string $shopProjectId;

    public function __construct()
    {
        $this->token = (string) (env('VERCEL_API_TOKEN') ?? '');
        $this->adminProjectId = (string) (env('VERCEL_ADMIN_PROJECT_ID') ?? '');
        $this->shopProjectId = (string) (env('VERCEL_SHOP_PROJECT_ID') ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->adminProjectId !== '' && $this->shopProjectId !== '';
    }

    public function addDomain(string $domain, string $type): array
    {
        $projectId = $type === 'admin' ? $this->adminProjectId : $this->shopProjectId;
        return $this->call('POST', "/v9/projects/{$projectId}/domains", [
            'name' => $domain,
        ]);
    }

    public function removeDomain(string $domain, string $type): array
    {
        $projectId = $type === 'admin' ? $this->adminProjectId : $this->shopProjectId;
        return $this->call('DELETE', "/v9/projects/{$projectId}/domains/{$domain}");
    }

    public function getDomainStatus(string $domain, string $type): array
    {
        $projectId = $type === 'admin' ? $this->adminProjectId : $this->shopProjectId;
        return $this->call('GET', "/v9/projects/{$projectId}/domains/{$domain}");
    }

    private function call(string $method, string $path, array $body = []): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Vercel API not configured'];
        }

        $url = 'https://api.vercel.com' . $path;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true) ?? [];

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $data,
        ];
    }
}
