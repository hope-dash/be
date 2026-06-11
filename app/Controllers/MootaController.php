<?php

namespace App\Controllers;

use App\Libraries\MootaService;
use App\Models\JsonResponse;
use Exception;

class MootaController extends BaseController
{
    private MootaService $mootaService;
    private JsonResponse $jsonResponse;

    public function __construct()
    {
        $this->mootaService = new MootaService();
        $this->jsonResponse = new JsonResponse();
    }

    /**
     * Add a bank account to Moota
     * POST /api/v2/moota/bank
     */
    public function addBank()
    {
        try {
            $data = $this->request->getJSON(true);
            $idToko = (int)($data['id_toko'] ?? $this->request->getGet('id_toko') ?? 0);

            if ($idToko > 0) {
                $this->mootaService->initializeForToko($idToko);
            }

            $result = $this->mootaService->addBankAccount($data);

            return $this->jsonResponse->oneResp("Bank account successfully added to Moota.", $result, 201);
        } catch (Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Get registered bank accounts from Moota
     * GET /api/v2/moota/bank
     */
    public function getBanks()
    {
        try {
            $filters = $this->request->getGet() ?: [];
            $idToko = (int)($filters['id_toko'] ?? 0);

            if ($idToko > 0) {
                $this->mootaService->initializeForToko($idToko);
            }

            $result = $this->mootaService->getBankAccounts($filters);

            return $this->jsonResponse->oneResp("Success fetching bank accounts from Moota.", $result, 200);
        } catch (Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    /**
     * Receive Moota Webhook Callback
     * POST /api/v2/moota/webhook
     */
    public function webhook()
    {
        try {
            $rawPayload = $this->request->getBody();
            
            // Get Signature header
            $signature = $this->request->getHeaderLine('Signature') ?: $this->request->getHeaderLine('signature');
            
            if (empty($signature)) {
                return $this->response->setStatusCode(401)->setJSON([
                    'status'  => false,
                    'message' => 'Signature header missing'
                ]);
            }

            // Verify signature and load credentials for the matching Toko
            $idToko = $this->mootaService->verifyAndLoadWebhookConfig($rawPayload, $signature);
            if ($idToko === null) {
                return $this->response->setStatusCode(401)->setJSON([
                    'status'  => false,
                    'message' => 'Invalid webhook signature'
                ]);
            }

            $payload = json_decode($rawPayload, true);
            
            // Log received webhook data for debugging/future processing
            log_message('info', "[Moota Webhook] Received for Toko ID {$idToko}: " . $rawPayload);

            return $this->response->setStatusCode(200)->setJSON([
                'status'    => true,
                'message'   => 'Webhook verified and logged successfully',
                'id_toko'   => $idToko,
                'data'      => $payload
            ]);

        } catch (Exception $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
