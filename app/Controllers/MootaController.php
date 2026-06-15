<?php

namespace App\Controllers;

use App\Libraries\MootaService;
use App\Models\JsonResponse;
use Exception;

class MootaController extends BaseController
{
    private MootaService $mootaService;
    public function __construct()
    {
        $this->mootaService = new MootaService();
        $this->jsonResponse = new JsonResponse();
    }

    public function setupToken()
    {
        $db = \Config\Database::connect();
        $token = "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiJucWllNHN3OGxsdyIsImp0aSI6IjlmNTJmNjAxMTU2MmM2YzJhYTg2Mzk4MWU0ZDFhYjZkZDdkMGQzMzExZTgzNjE4YWNlN2UxNTBhZmExZjJlZGJhY2Y2MDJlMWU1Y2RjN2MzIiwiaWF0IjoxNzgxMTY3Njc2LjIzNzY1OSwibmJmIjoxNzgxMTY3Njc2LjIzNzY2MiwiZXhwIjoxODEyNzAzNjc2LjIzNDYyNywic3ViIjoiMjIzNjkiLCJzY29wZXMiOlsiYXBpIiwibXV0YXRpb25fcmVhZCIsImJhbmsiLCJiYW5rX3JlYWQiLCJtdXRhdGlvbiJdfQ.K3GF5qOdAMR0Xug2iugDl_DW4CVusXoZkOjE_1F_30hlpbmsocNh5D3XUV3tCHTBuRtO2KTDuvAghRgjOdCJK19_QNqJcbzSh4smpV7a4ySWOLAcw0X5klPZSf8aWjzPyDmeV0ytjyulS_LKZ42DcuU_O59Lb5XnJctgeF5Ybw1iqOIRmUK0eZrpx3JD6ps-y5QyejoXqZZ0G8eipTE4oN1ANBtzoEaOPnP0soF2WkZetpGF7avSfoVHP8loPg4yqkA24HZTjBEZ9S3wmCfAmMJeXIaVPkL1cW5kL5y_PNKfESBcH5zx-h4wgl6afB6_yKnhm3oBtLHCKFtIslitjyr56ZCghPnDqSRAWbYz7ClmuwrHtpsLDVlyTXW-h6-D3MxgCBMjHg1gVPRpp9BgSO0hkXc_pk8ZCCY0iJSi1TbRCN3Bj4Pz_8_VaVyXomRU9-uRQOMfkPURhRCSoMxOB-CEBu6sH0bUhXMOwnF2vz4NOr2iE3BCMHmjXIE0J4R9CquTz4gOUBKMK2RoSIPx2NJkIEBHZPg3TR05KlEUE8wX-0yCGlREgq1I3CbPT4kjGMbWCZhetGPyHlz6rdjLXuEV6PDkjkKkZDX6x9rG6BruacWesYVywwtql6vs7Oj1UfKdgc5gycvE3T2xB70YtKKEaUTede1T_Dsgi-fzGn4";
        
        $db->table('tenants')->where('id', 1)->update([
            'moota_token' => $token
        ]);

        $db->table('toko')->where('id', 10)->update([
            'moota_bank_id' => 'm9xzXMEyjPJ'
        ]);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Token and Toko 10 bank ID updated successfully'
        ]);
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
            $payload = json_decode($rawPayload, true);

            if (empty($payload)) {
                $payload = $this->request->getJSON(true) ?: $this->request->getPost();
            }

            // Log webhook payload
            log_message('info', "[Moota Webhook Received] Payload: " . json_encode($payload));

            if (!is_array($payload)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => false,
                    'message' => 'Invalid webhook payload structure'
                ]);
            }

            // If it's a single associative array, wrap it in a list
            if (!empty($payload) && array_is_list($payload) === false) {
                $payload = [$payload];
            }

            $processedCount = 0;
            $db = \Config\Database::connect();
            $transactionController = new \App\Controllers\TransactionControllerV2();

            foreach ($payload as $mutation) {
                $paymentDetail = $mutation['payment_detail'] ?? null;
                if (empty($paymentDetail)) {
                    continue;
                }

                $trxIdMoota = $paymentDetail['trx_id'] ?? null;
                $status = strtolower($paymentDetail['status'] ?? '');

                if (empty($trxIdMoota)) {
                    continue;
                }

                // If status is present, verify it indicates success/completion
                if (!empty($status) && !in_array($status, ['completed', 'success', 'paid'])) {
                    continue;
                }

                $amount = (float)($mutation['amount'] ?? $paymentDetail['amount_captured'] ?? $paymentDetail['total'] ?? 0);
                $uniqueCode = (float)($paymentDetail['unique_code'] ?? 0);
                $amountCaptured = (float)($paymentDetail['amount_captured'] ?? 0);

                // Calculate total items price
                $totalItemsPrice = 0;
                if (!empty($paymentDetail['items']) && is_array($paymentDetail['items'])) {
                    foreach ($paymentDetail['items'] as $item) {
                        $price = (float)($item['price'] ?? 0);
                        $qty = (float)($item['qty'] ?? 1);
                        $ratio = (float)($item['ratio'] ?? 1);
                        $totalItemsPrice += $price * $qty * $ratio;
                    }
                }

                // Look up matching moota_trx_id key in transaction_meta
                $meta = $db->table('transaction_meta')
                           ->where('key', 'moota_trx_id')
                           ->where('value', $trxIdMoota)
                           ->get()
                           ->getRowArray();

                $transactionId = null;
                if ($meta) {
                    $transactionId = (int)$meta['transaction_id'];
                } else if (!empty($paymentDetail['order_id'])) {
                    // Fallback: lookup by parsing invoice prefix from order_id
                    $invoicePart = explode('-', $paymentDetail['order_id'])[0];
                    $trxDb = $db->table('transaction')
                                ->where('invoice', $invoicePart)
                                ->get()
                                ->getRowArray();
                    if ($trxDb) {
                        $transactionId = (int)$trxDb['id'];
                    }
                }

                if ($transactionId) {
                    // Check unique code matching condition
                    if ($uniqueCode > 0 && ($uniqueCode + $totalItemsPrice) == $amountCaptured) {
                        // Save/update moota_unique_code in transaction_meta
                        $existingUniqueCodeMeta = $db->table('transaction_meta')
                            ->where('transaction_id', $transactionId)
                            ->where('key', 'moota_unique_code')
                            ->get()
                            ->getRowArray();

                        if ($existingUniqueCodeMeta) {
                            $db->table('transaction_meta')
                               ->where('id', $existingUniqueCodeMeta['id'])
                               ->update(['value' => (string)$uniqueCode]);
                        } else {
                            $db->table('transaction_meta')
                               ->insert([
                                   'transaction_id' => $transactionId,
                                   'key' => 'moota_unique_code',
                                   'value' => (string)$uniqueCode
                               ]);
                        }
                    }

                    // Subtract the unique code from the payment amount if it exists
                    $paymentAmount = $amount;
                    if ($uniqueCode > 0) {
                        $paymentAmount = $amount - $uniqueCode;
                    }

                    try {
                        // Call the exposed public method to add payment details automatically
                        $transactionController->internalAddPayment($transactionId, $paymentAmount, 'BANK_TRANSFER', null, 0);
                        $processedCount++;
                    } catch (\Exception $ex) {
                        log_message('error', "[Moota Webhook] Error processing payment for Transaction ID {$transactionId}: " . $ex->getMessage());
                    }
                }
            }

            return $this->response->setStatusCode(200)->setJSON([
                'status'          => true,
                'message'         => 'Webhook processed successfully',
                'processed_count' => $processedCount
            ]);

        } catch (Exception $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
