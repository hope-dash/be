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
        $data = $this->request->getJSON();
        $validation = \Config\Services::validation();
        $validation->setRules([
            'kode_awal' => 'required',
            'nama_model' => 'required',
        ]);

        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $this->modelBarangModel->insert($data);

        return $this->jsonResponse->oneResp('Add ' . $data->nama_model . ' successfully', ['id' => $this->modelBarangModel->insertID()], 201);
    }

    // Read All Model Barang
    public function listModelBarang()
    {
        $data = $this->modelBarangModel->findAll();

        return $this->jsonResponse->oneResp('', $data, 200);

    }

    // Create Seri
    public function createSeri()
    {
        $data = $this->request->getJSON();
        $validation = \Config\Services::validation();
        $validation->setRules([
            'seri' => 'required',
        ]);
        if (!$this->validate($validation->getRules())) {
            return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
        }

        $this->seriModel->insert($data);
        return $this->jsonResponse->oneResp('Add ' . $data->seri . ' successfully', ['id' => $this->seriModel->insertID()], 201);
    }

    // Read All Seri
    public function listSeri()
    {
        $data = $this->seriModel->findAll();
        return $this->jsonResponse->oneResp('', $data, 200);
    }

    // Update Model Barang
    public function updateModelBarang($id = null)
    {
        $data = $this->request->getJSON();
        $this->modelBarangModel->update($id, $data);
        return $this->jsonResponse->oneResp('Update ' . $data->nama_model . ' successfully', [], 200);
    }

    // Update Seri
    public function updateSeri($id = null)
    {
        $data = $this->request->getJSON();
        $this->seriModel->update($id, $data);
        return $this->jsonResponse->oneResp('Update ' . $data->seri . ' successfully', [], 200);
    }
}
