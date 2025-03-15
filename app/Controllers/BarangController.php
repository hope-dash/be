<?php

namespace App\Controllers;

use App\Models\ModelBarangModel;
use App\Models\SeriModel;
use CodeIgniter\RESTful\ResourceController;
use App\Models\JsonResponse;

class BarangController extends ResourceController
{
    protected $modelBarangModel;
    protected $seriModel;
    protected $jsonResponse;
    public function __construct()
    {
        $this->modelBarangModel = new ModelBarangModel();
        $this->seriModel = new SeriModel();
        $this->jsonResponse = new JsonResponse();
    }

    // Create Model Barang
    public function createModelBarang()
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();
        $validation = \Config\Services::validation();
        $validation->setRules([
            'kode_awal' => 'required',
            'nama_model' => 'required',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }
        $data->created_by = $token['user_id'];
        $this->modelBarangModel->insert($data);

        return $this->jsonResponse->oneResp('Add ' . $data->nama_model . ' successfully', ['id' => $this->modelBarangModel->insertID()], 201);
    }

    // Read All Model Barang
    public function listModelBarang()
    {
        $sortBy = $this->request->getGet('sortBy') ?? 'id';
        $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
        $search = $this->request->getGet('search') ?? '';
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $page = (int) $this->request->getGet('page') ?: 1;

        $offset = ($page - 1) * $limit;
        $builder = $this->modelBarangModel;


        if (!empty($search)) {
            $builder = $builder->like('nama_model', $search, 'both');
        }

        $total_data = $builder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        $result = $builder->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);

    }

    // Create Seri
    public function createSeri()
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();
        $validation = \Config\Services::validation();
        $validation->setRules([
            'seri' => 'required',
        ]);
        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }
        $data->created_by = $token['user_id'];

        $this->seriModel->insert($data);
        return $this->jsonResponse->oneResp('Add ' . $data->seri . ' successfully', ['id' => $this->seriModel->insertID()], 201);
    }

    // Read All Seri
    public function listSeri()
    {
        $sortBy = $this->request->getGet('sortBy') ?? 'id';
        $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
        $search = $this->request->getGet('search') ?? '';
        $limit = (int) $this->request->getGet('limit') ?: 10;
        $page = (int) $this->request->getGet('page') ?: 1;

        $offset = ($page - 1) * $limit;
        $builder = $this->seriModel;

        if (!empty($search)) {
            $builder = $builder->like('seri
            ', $search, 'both');
        }

        $total_data = $builder->countAllResults(false);
        $total_page = ceil($total_data / $limit);

        $result = $builder->orderBy($sortBy, $sortMethod)
            ->limit($limit, $offset)
            ->get()
            ->getResult();

        return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);


    }

    // Update Model Barang
    public function updateModelBarang($id = null)
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();
        $data->updated_by = $token['user_id'];
        $this->modelBarangModel->update($id, $data);
        return $this->jsonResponse->oneResp('Update ' . $data->nama_model . ' successfully', [], 200);
    }

    // Update Seri
    public function updateSeri($id = null)
    {
        $token = $this->request->user;
        $data = $this->request->getJSON();
        $data->updated_by = $token['user_id'];
        $this->seriModel->update($id, $data);
        return $this->jsonResponse->oneResp('Update ' . $data->seri . ' successfully', [], 200);
    }

    public function deleteModel($id = null)
    {
        try {
            $query = $this->modelBarangModel->where("id", $id)
                ->first();

            if ($query) {
                $this->modelBarangModel->delete($id);
                return $this->jsonResponse->oneResp("Data Deleted", "", 200);
            } else {
                return $this->jsonResponse->error("Data Not Found", 401);
            }

        } catch (\Exception $e) {
            return $this->respond([
                "status" => "error",
                "message" => $this->request->getVar("id")
            ], 400);
        }

    }

    public function deleteSeri($id = null)
    {
        try {
            $query = $this->seriModel->where("id", $id)
                ->first();

            if ($query) {
                $this->seriModel->delete($id);
                return $this->jsonResponse->oneResp("Data Deleted", "", 200);
            } else {
                return $this->jsonResponse->error("Data Not Found", 401);
            }

        } catch (\Exception $e) {
            return $this->respond([
                "status" => "error",
                "message" => $this->request->getVar("id")
            ], 400);
        }

    }

    public function dropdownSeri()
    {
        try {

            $result = $this->seriModel->select('id, seri')->where('deleted_at', NULL)->get()->getResult();


            $formattedResult = array_map(function ($row) {
                return [
                    'label' => $row->seri,
                    'value' => $row->id
                ];
            }, $result);
            return $this->jsonResponse->oneResp('', $formattedResult, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }


    public function dropdownModel()
    {
        try {

            $result = $this->modelBarangModel->select('id, nama_model')->where('deleted_at', NULL)->get()->getResult();


            $formattedResult = array_map(function ($row) {
                return [
                    'label' => $row->nama_model,
                    'value' => $row->id
                ];
            }, $result);
            return $this->jsonResponse->oneResp('', $formattedResult, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

}
