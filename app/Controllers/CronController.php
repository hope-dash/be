<?php

namespace App\Controllers;

use App\Models\EmailQueueModel;
use App\Models\JsonResponse;
use CodeIgniter\Controller;

class CronController extends Controller
{
    protected $emailQueueModel;
    protected $jsonResponse;

    public function __construct()
    {
        $this->emailQueueModel = new EmailQueueModel();
        $this->jsonResponse = new JsonResponse();
        helper('email_helper');
    }

    /**
     * Process pending emails in the queue
     * This can be called via a GET/POST request from a cron job (e.g., using curl or wget)
     * Limit to 10 emails per run to avoid timeout
     */
    public function processEmailQueue()
    {
        // Simple security: check for a secret key if provided in query string
        // $key = $this->request->getGet('key');
        // if ($key !== 'YOUR_SECRET_KEY') {
        //     return $this->jsonResponse->error('Unauthorized', 401);
        // }

        $pendingEmails = $this->emailQueueModel
            ->where('status', 'PENDING')
            ->orderBy('created_at', 'ASC')
            ->limit(10)
            ->findAll();

        if (empty($pendingEmails)) {
            return $this->jsonResponse->oneResp('No pending emails found', [], 200);
        }

        $results = [
            'total' => count($pendingEmails),
            'success' => 0,
            'failed' => 0
        ];

        foreach ($pendingEmails as $email) {
            $attempts = ($email['attempts'] ?? 0) + 1;

            // Set tenant context for each email (needed by email_helper)
            if (isset($email['tenant_id'])) {
                $tenantModel = new \App\Models\TenantModel();
                $tenant = $tenantModel->find($email['tenant_id']);
                if ($tenant) {
                    log_message('debug', '[Cron] Setting tenant context to: ' . ($tenant['name'] ?? 'N/A') . ' (ID: ' . $email['tenant_id'] . ')');
                    \App\Libraries\TenantContext::set($tenant);
                } else {
                    log_message('error', '[Cron] Tenant not found for ID: ' . $email['tenant_id']);
                }
            }

            $success = send_email($email['recipient'], $email['subject'], $email['message']);

            if ($success) {
                $this->emailQueueModel->update($email['id'], [
                    'status' => 'SENT',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'attempts' => $attempts
                ]);
                $results['success']++;
            } else {
                $status = ($attempts >= 3) ? 'FAILED' : 'PENDING';
                $this->emailQueueModel->update($email['id'], [
                    'status' => $status,
                    'attempts' => $attempts,
                    'error_message' => 'Email sending failed after attempt ' . $attempts
                ]);
                $results['failed']++;
            }
        }

        return $this->jsonResponse->oneResp('Cron job processed successfully', $results, 200);
    }

    /**
     * Run the Daycry CronJob scheduler via URL
     * This triggers all jobs defined in Config\CronJob
     */
    public function runScheduler()
    {
        $schedulerResult = [];

        // 1. Run Daycry CronJob runner if available
        if (class_exists('\Daycry\CronJob\JobRunner')) {
            try {
                $config = config('CronJob');
                $scheduler = service('scheduler');
                $config->init($scheduler);

                $runner = new \Daycry\CronJob\JobRunner($config);
                $runner->run();
                $schedulerResult['scheduler'] = 'Ran Daycry CronJob scheduler';
            } catch (\Exception $e) {
                $schedulerResult['scheduler_error'] = $e->getMessage();
            }
        }

        // 2. Always run TikTok token refresh process
        try {
            $tiktokRes = $this->performTiktokTokenRefresh();
            $schedulerResult['tiktok_refresh_token'] = $tiktokRes;
        } catch (\Exception $e) {
            $schedulerResult['tiktok_refresh_token_error'] = $e->getMessage();
        }

        return $this->jsonResponse->oneResp('Scheduler run successfully', $schedulerResult, 200);
    }

    /**
     * Internal helper to refresh TikTok tokens
     */
    private function performTiktokTokenRefresh()
    {
        $tokoMetaModel = new \App\Models\TokoMetaModel();
        $tokens = $tokoMetaModel->where('meta_key', 'tiktok_refresh_token')
                               ->where('meta_value !=', '')
                               ->findAll();

        if (empty($tokens)) {
            return ['status' => 'No integrated TikTok stores found'];
        }

        $controller = new \App\Controllers\TiktokController();
        $results = [];

        foreach ($tokens as $tokenRow) {
            $tokoId = $tokenRow['toko_id'];
            $res = $controller->performTokenRefresh($tokoId);
            $results[$tokoId] = $res;
        }

        return $results;
    }

    /**
     * Refresh TikTok access tokens for all integrated shops
     * GET /api/cron/tiktok-refresh-token
     */
    public function refreshTiktokTokens()
    {
        try {
            $tokoMetaModel = new \App\Models\TokoMetaModel();
            $tokens = $tokoMetaModel->where('meta_key', 'tiktok_refresh_token')
                                   ->where('meta_value !=', '')
                                   ->findAll();

            if (empty($tokens)) {
                return $this->jsonResponse->oneResp('No integrated TikTok stores found', [], 200);
            }

            $controller = new \App\Controllers\TiktokController();
            $results = [];

            foreach ($tokens as $tokenRow) {
                $tokoId = $tokenRow['toko_id'];
                $res = $controller->performTokenRefresh($tokoId);
                $results[$tokoId] = $res;
            }

            return $this->jsonResponse->oneResp('TikTok token refresh completed', $results, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
