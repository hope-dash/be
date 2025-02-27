<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\JsonResponse;
use App\Models\TokoModel;
use CodeIgniter\HTTP\ResponseInterface;

class TokoController extends BaseController
{
    protected $modelToko;
    protected $jsonResponse;


    public function __construct()
    {
        $this->modelToko = new TokoModel();
        $this->jsonResponse = new JsonResponse();
    }

    public function create()
    {
        try {
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                'toko_name' => 'required',
                'alamat' => 'required',
                'phone_number' => 'required|numeric|min_length[10]|max_length[15]',
                'email_toko' => 'required|valid_email',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }
            $tokoData = [
                "toko_name" => $data->toko_name,
                "alamat" => $data->alamat,
                "phone_number" => $data->phone_number,
                "email_toko" => $data->email_toko,
            ];

            $this->modelToko->insert($tokoData);

            return $this->jsonResponse->oneResp('Add ' . $data->toko_name . ' successfully', ['id' => $this->modelToko->insertID()], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(),400);
        }
    }

    public function update($id = null)
    {
        try {
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                'toko_name' => 'required',
                'alamat' => 'required',
                'phone_number' => 'required|numeric|min_length[10]|max_length[15]',
                'email_toko' => 'required|valid_email',
            ]);
            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }
            $tokoData = [
                "toko_name" => $data->toko_name,
                "alamat" => $data->alamat,
                "phone_number" => $data->phone_number,
                "email_toko" => $data->email_toko,
            ];

            $this->modelToko->update($id, $tokoData);

            return $this->jsonResponse->oneResp('Toko updated successfully', ['id' => $id], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(),400);
        }
    }

    public function getDetailById($id = null)
    {
        try {

            $toko = $this->modelToko->where("id", $id)->first();
            if ($toko) {
                return $this->jsonResponse->oneResp("", $toko, 200);
            } else {
                return $this->jsonResponse->error("Toko Not Found", 401);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function delete($id = null)
    {
        try {
            $query = $this->modelToko->where("id", $id)
                ->first();

            if ($query) {
                $this->modelToko->delete($id);
                return $this->jsonResponse->oneResp("Data Deleted", "", 200);
            } else {
                return $this->jsonResponse->error("Toko Not Found", 401);
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
}
