<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\VoucherModel;
use App\Models\JsonResponse;
use CodeIgniter\API\ResponseTrait;

class VoucherController extends ResourceController
{
    use ResponseTrait;

    protected $voucherModel;
    protected $jsonResponse;

    public function __construct()
    {
        helper('log');
        $this->voucherModel = new VoucherModel();
        $this->jsonResponse = new JsonResponse();
    }

    // Create Voucher
    public function create()
    {
        try {
            $data = $this->request->getJSON();
            $userId = $this->request->user['user_id'] ?? 0;

            $validation = \Config\Services::validation();
            $validation->setRules([
                'code' => 'required|is_unique[voucher.code]',
                'discount_type' => 'required|in_list[FIXED,PERCENTAGE]',
                'discount_value' => 'required|numeric',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $voucherData = [
                'code' => strtoupper($data->code),
                'description' => $data->description ?? null,
                'discount_type' => $data->discount_type,
                'discount_value' => $data->discount_value,
                'min_purchase' => $data->min_purchase ?? null,
                'max_discount' => $data->max_discount ?? null,
                'usage_limit' => $data->usage_limit ?? null,
                'valid_from' => $data->valid_from ?? null,
                'valid_until' => $data->valid_until ?? null,
                'is_active' => $data->is_active ?? 1,
                'created_by' => $userId,
            ];

            $this->voucherModel->insert($voucherData);
            $voucherId = $this->voucherModel->getInsertID();

            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'CREATE',
                'target_table' => 'voucher',
                'target_id' => $voucherId,
                'description' => "Created voucher: {$data->code}",
                'detail' => $voucherData
            ]);

            return $this->jsonResponse->oneResp('Voucher created successfully', ['id' => $voucherId], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // List Vouchers
    public function index()
    {
        try {
            $limit = (int) $this->request->getGet('limit') ?: 20;
            $page = (int) $this->request->getGet('page') ?: 1;
            $offset = ($page - 1) * $limit;
            $isActive = $this->request->getGet('is_active');

            $builder = $this->voucherModel;

            if ($isActive !== null) {
                $builder = $builder->where('is_active', $isActive);
            }

            $totalData = $builder->countAllResults(false);
            $totalPage = ceil($totalData / $limit);

            $vouchers = $builder
                ->orderBy('created_at', 'DESC')
                ->limit($limit, $offset)
                ->findAll();

            return $this->jsonResponse->multiResp('', $vouchers, $totalData, $totalPage, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Update Voucher
    public function update($id = null)
    {
        try {
            $data = $this->request->getJSON();
            $userId = $this->request->user['user_id'] ?? 0;

            $voucher = $this->voucherModel->find($id);
            if (!$voucher) {
                return $this->jsonResponse->error("Voucher not found", 404);
            }

            $updateData = [];
            if (isset($data->description)) $updateData['description'] = $data->description;
            if (isset($data->discount_type)) $updateData['discount_type'] = $data->discount_type;
            if (isset($data->discount_value)) $updateData['discount_value'] = $data->discount_value;
            if (isset($data->min_purchase)) $updateData['min_purchase'] = $data->min_purchase;
            if (isset($data->max_discount)) $updateData['max_discount'] = $data->max_discount;
            if (isset($data->usage_limit)) $updateData['usage_limit'] = $data->usage_limit;
            if (isset($data->valid_from)) $updateData['valid_from'] = $data->valid_from;
            if (isset($data->valid_until)) $updateData['valid_until'] = $data->valid_until;
            if (isset($data->is_active)) $updateData['is_active'] = $data->is_active;

            $this->voucherModel->update($id, $updateData);

            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'UPDATE',
                'target_table' => 'voucher',
                'target_id' => $id,
                'description' => "Updated voucher: {$voucher['code']}",
                'detail' => $updateData
            ]);

            return $this->jsonResponse->oneResp('Voucher updated successfully', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }

    // Delete Voucher
    public function delete($id = null)
    {
        try {
            $userId = $this->request->user['user_id'] ?? 0;

            $voucher = $this->voucherModel->find($id);
            if (!$voucher) {
                return $this->jsonResponse->error("Voucher not found", 404);
            }

            $this->voucherModel->delete($id);

            log_aktivitas([
                'user_id' => $userId,
                'action_type' => 'DELETE',
                'target_table' => 'voucher',
                'target_id' => $id,
                'description' => "Deleted voucher: {$voucher['code']}",
            ]);

            return $this->jsonResponse->oneResp('Voucher deleted successfully', [], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }
}
