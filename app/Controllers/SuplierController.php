<?php

namespace App\Controllers;

use App\Models\JsonResponse;
use App\Models\SuplierModel;
use CodeIgniter\RESTful\ResourceController;

class SuplierController extends ResourceController
{
    protected $suplierModel;
    protected $jsonResponse;

    public function __construct()
    {
        $this->suplierModel = new SuplierModel();
        $this->jsonResponse = new JsonResponse();
    }

    // GET: Ambil semua supplier
    public function index()
    {
        $sortBy = $this->request->getGet('sortBy') ?? 'id';
        $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
        $search = $this->request->getGet('search') ?? '';
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $page = (int) $this->request->getGet('page') ?: 1;

        $offset = ($page - 1) * $limit;
        $builder = $this->suplierModel;


        if (!empty($search)) {
            $builder = $builder->like('suplier_name', $search, 'both');
        }

        $total_data = $builder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        $result = $builder->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);
    }

    public function dropdownSuplier()
    {
        $result = $this->suplierModel->select('id, suplier_name')->where('deleted_at', NULL)->get()->getResult();
        $formattedResult = array_map(function ($row) {
            return [
                'label' => $row->suplier_name,
                'value' => $row->id
            ];
        }, $result);
        return $this->jsonResponse->oneResp('', $formattedResult, 200);
    }


    // GET: Ambil supplier berdasarkan ID
    public function show($id = null)
    {
        $suplier = $this->suplierModel->find($id);
        if (!$suplier) {
            return $this->jsonResponse->error("Supplier dengan ID $id tidak ditemukan", 400);
        }
        return $this->jsonResponse->oneResp('successfully', $suplier, 201);
    }

    // POST: Tambah supplier baru
    public function create()
    {
        $data = $this->request->getJSON();
        if (!$this->suplierModel->insert($data)) {
            return $this->jsonResponse->error($this->suplierModel->errors(), 400);
        }
        return $this->jsonResponse->oneResp('successfully', "", 201);
    }

    // PUT/PATCH: Update supplier
    public function update($id = null)
    {
        $data = $this->request->getJSON();
        if (!$this->suplierModel->find($id)) {
            return $this->jsonResponse->error("Supplier dengan ID $id tidak ditemukan", 400);

        }
        $this->suplierModel->update($id, $data);
        return $this->jsonResponse->oneResp('successfully', "", 200);
    }


    public function delete($id = null)
    {
        if (!$this->suplierModel->find($id)) {
            return $this->jsonResponse->error("Supplier dengan ID $id tidak ditemukan", 400);
        }
        $this->suplierModel->delete($id);
        return $this->jsonResponse->oneResp('successfully', "", 200);
    }
}
