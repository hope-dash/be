<?php

namespace App\Controllers;

use App\Models\JsonResponse;
use CodeIgniter\RESTful\ResourceController;

class LogAktivitasController extends ResourceController
{
    protected $modelName = 'App\Models\LogAktivitasModel';
    protected $format = 'json';

    protected $jsonResponse;

    public function __construct()
    {
        $this->jsonResponse = new JsonResponse();

    }

    public function index()
    {
        $db = \Config\Database::connect();
        $builder = $db->table('log_aktivitas')
            ->select('log_aktivitas.*, users.name as user_name')
            ->join('users', 'users.user_id = log_aktivitas.user_id', 'left');

        // Ambil filter dari query params
        $targetTable = $this->request->getGet('target_table');
        $targetId = $this->request->getGet('target_id');
        $actionType = $this->request->getGet('action_type');
        $username = $this->request->getGet('username');
        $user_id = $this->request->getGet('user_id');
        $startDate = $this->request->getGet('start_date');
        $endDate = $this->request->getGet('end_date');

        if ($targetTable) {
            $builder->where('log_aktivitas.target_table', $targetTable);
        }
        if ($targetId) {
            $builder->where('log_aktivitas.target_id', $targetId);
        }
        if ($actionType) {
            $builder->where('log_aktivitas.action_type', $actionType);
        }
        if ($username) {
            $builder->like('users.name', $username);
        }
        if ($user_id) {
            $builder->like('users.user_id', $user_id);
        }
        if ($startDate) {
            $builder->where('DATE(log_aktivitas.created_at) >=', $startDate);
        }
        if ($endDate) {
            $builder->where('DATE(log_aktivitas.created_at) <=', $endDate);
        }

        // Pagination
        $page = (int) ($this->request->getGet('page') ?? 1);
        $limit = (int) ($this->request->getGet('limit') ?? 10);
        $limit = $limit > 0 ? $limit : 10;
        $offset = ($page - 1) * $limit;

        // Total data
        $total_data = $builder->countAllResults(false); // false supaya query tidak direset

        // Ambil data
        $result = $builder
            ->orderBy('log_aktivitas.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        // Total halaman
        $total_page = $limit > 0 ? ceil($total_data / $limit) : 1;

        return $this->jsonResponse->multiResp(
            'Log aktivitas berhasil diambil',
            $result,
            $total_data,
            $total_page,
            $page,
            $limit,
            200
        );
    }
}
