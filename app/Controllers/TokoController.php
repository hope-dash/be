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
            $token = $this->request->user;
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                'toko_name' => 'required',
                'alamat' => 'required',
                'phone_number' => 'required|numeric|min_length[10]|max_length[15]',
                'email_toko' => 'required|valid_email',
                'image_logo' => 'permit_empty|valid_url',
                'bank' => 'required|string',
                'nama_pemilik' => 'required|string',
                'nomer_rekening' => 'required|numeric',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $tokoData = [
                "toko_name" => $data->toko_name,
                "alamat" => $data->alamat,
                "phone_number" => $data->phone_number,
                "email_toko" => $data->email_toko,
                "created_by" => $token['user_id'],
                "image_logo" => isset($data->image_logo) ? $data->image_logo : null,
                "bank" => $data->bank,
                "nama_pemilik" => $data->nama_pemilik,
                "nomer_rekening" => $data->nomer_rekening,
            ];

            $this->modelToko->insert($tokoData);

            return $this->jsonResponse->oneResp('Add ' . $data->toko_name . ' successfully', ['id' => $this->modelToko->insertID()], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }


    public function update($id = null)
    {
        try {
            $token = $this->request->user;
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                'toko_name' => 'required',
                'alamat' => 'required',
                'phone_number' => 'required|numeric|min_length[10]|max_length[15]',
                'email_toko' => 'required|valid_email',
                'image_logo' => 'permit_empty|valid_url',
                'bank' => 'required|string',
                'nama_pemilik' => 'required|string',
                'nomer_rekening' => 'required|numeric',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $tokoData = [
                "toko_name" => $data->toko_name,
                "alamat" => $data->alamat,
                "phone_number" => $data->phone_number,
                "email_toko" => $data->email_toko,
                "updated_by" => $token['user_id'],
                "image_logo" => isset($data->image_logo) ? $data->image_logo : null,
                "bank" => $data->bank,
                "nama_pemilik" => $data->nama_pemilik,
                "nomer_rekening" => $data->nomer_rekening,
            ];

            $this->modelToko->update($id, $tokoData);

            return $this->jsonResponse->oneResp('Toko updated successfully', ['id' => $id], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
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

    public function getAllToko()
    {
        try {
            $sortBy = $this->request->getGet('sortBy') ?? 'id';
            $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
            $namaToko = $this->request->getGet('toko_name') ?? '';
            $limit = (int) $this->request->getGet('limit') ?: 10;
            $page = (int) $this->request->getGet('page') ?: 1;

            $allowedSortBy = ['id', 'toko_name', 'alamat', 'phone_number', 'email_toko'];
            $allowedSortMethod = ['asc', 'desc'];

            $sortBy = in_array($sortBy, $allowedSortBy) ? $sortBy : 'id';
            $sortMethod = in_array($sortMethod, $allowedSortMethod) ? $sortMethod : 'asc';

            $offset = ($page - 1) * $limit;

            $builder = $this->modelToko;

            if (!empty($namaToko)) {
                $builder = $builder->like('toko_name', $namaToko, 'both');
            }

            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            // Get paginated results
            $result = $builder->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResult();

            return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function dropdownToko()
    {
        try {
            // Ambil parameter 'toko' dari query string
            $tokoParam = $this->request->getGet('role');
            $tokoIds = array_filter(array_map('trim', explode(',', $tokoParam)));

            $query = $this->modelToko->select('id, toko_name');
            if (!empty($tokoIds)) {
                $query->whereIn('id', $tokoIds);
            }

            $result = $query->get()->getResult();

            $formattedResult = array_map(function ($row) {
                return [
                    'label' => $row->toko_name,
                    'value' => $row->id
                ];
            }, $result);

            return $this->jsonResponse->oneResp('', $formattedResult, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
}
