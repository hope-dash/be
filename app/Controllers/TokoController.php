<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\JsonResponse;
use App\Models\TokoModel;
use App\Models\AccountModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * @property \CodeIgniter\HTTP\IncomingRequest $request
 */
class TokoController extends BaseController
{
    protected $modelToko;
    protected $accountModel;
    protected $jsonResponse;
    protected $db;


    public function __construct()
    {
        $this->modelToko = new TokoModel();
        $this->accountModel = new AccountModel();
        $this->jsonResponse = new JsonResponse();
        $this->db = \Config\Database::connect();
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
                'provinsi' => 'permit_empty',
                'kota_kabupaten' => 'permit_empty',
                'kecamatan' => 'permit_empty',
                'kelurahan' => 'permit_empty',
                'kode_pos' => 'permit_empty',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $this->db->transStart();

            // 1. Insert Toko first
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
                "provinsi" => $data->provinsi ?? null,
                "kota_kabupaten" => $data->kota_kabupaten ?? null,
                "kecamatan" => $data->kecamatan ?? null,
                "kelurahan" => $data->kelurahan ?? null,
                "kode_pos" => $data->kode_pos ?? null,
            ];

            $this->modelToko->insert($tokoData);
            $tokoId = $this->modelToko->insertID();

            // 2. Automatically generate accounts for this new toko
            $baseAccounts = $this->accountModel->where('id_toko', null)->findAll();
            foreach ($baseAccounts as $acc) {
                $baseCode = $acc['base_code'] ?? $acc['code'];
                $newCode = substr($baseCode, 0, 2) . $tokoId . substr($baseCode, 3);
                $newName = $acc['name'] . ' ' . $data->toko_name;

                $this->accountModel->insert([
                    'id_toko' => $tokoId,
                    'base_code' => $baseCode,
                    'code' => $newCode,
                    'name' => $newName,
                    'type' => $acc['type'],
                    'normal_balance' => $acc['normal_balance'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->jsonResponse->error('Failed to create toko', 500);
            }

            return $this->jsonResponse->oneResp('Add ' . $data->toko_name . ' successfully', [
                'id' => $tokoId,
            ], 201);
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
                'provinsi' => 'permit_empty',
                'kota_kabupaten' => 'permit_empty',
                'kecamatan' => 'permit_empty',
                'kelurahan' => 'permit_empty',
                'kode_pos' => 'permit_empty',
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
                "provinsi" => $data->provinsi ?? null,
                "kota_kabupaten" => $data->kota_kabupaten ?? null,
                "kecamatan" => $data->kecamatan ?? null,
                "kelurahan" => $data->kelurahan ?? null,
                "kode_pos" => $data->kode_pos ?? null,
            ];

            $this->db->transStart();
            $this->modelToko->update($id, $tokoData);

            // Update account names if toko_name changed
            $baseAccounts = $this->accountModel->where('id_toko', null)->findAll();
            foreach ($baseAccounts as $acc) {
                $baseCode = $acc['base_code'] ?? $acc['code'];
                $newName = $acc['name'] . ' ' . $data->toko_name;

                $this->accountModel->builder()
                    ->where('id_toko', $id)
                    ->where('base_code', $baseCode)
                    ->update(['name' => $newName, 'updated_at' => date('Y-m-d H:i:s')]);
            }

            $this->db->transComplete();

            return $this->jsonResponse->oneResp('Toko updated successfully', ['id' => $id], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }


    public function getDetailById($id = null)
    {
        try {
            $toko = $this->modelToko->builder()
                ->select('toko.*, provincy.name as province_name, provincy.name as provincies_name, kota_kabupaten.name as city_name, kecamatan.name as district_name, kelurahan.name as village_name')
                ->join('provincy', 'toko.provinsi = provincy.code', 'left')
                ->join('kota_kabupaten', 'toko.kota_kabupaten = kota_kabupaten.code', 'left')
                ->join('kecamatan', 'toko.kecamatan = kecamatan.code', 'left')
                ->join('kelurahan', 'toko.kelurahan = kelurahan.code', 'left')
                ->where('toko.id', $id)
                ->where('toko.deleted_at', null)
                ->groupBy('toko.id')
                ->get()
                ->getRowArray();

            if ($toko) {
                return $this->jsonResponse->oneResp("", $toko, 200);
            } else {
                return $this->jsonResponse->error("Toko Not Found", 404);
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

            $builder = $this->modelToko->builder()
                ->select('toko.*, provincy.name as province_name, provincy.name as provincies_name, kota_kabupaten.name as city_name, kecamatan.name as district_name, kelurahan.name as village_name')
                ->join('provincy', 'toko.provinsi = provincy.code', 'left')
                ->join('kota_kabupaten', 'toko.kota_kabupaten = kota_kabupaten.code', 'left')
                ->join('kecamatan', 'toko.kecamatan = kecamatan.code', 'left')
                ->join('kelurahan', 'toko.kelurahan = kelurahan.code', 'left')
                ->where('toko.deleted_at', null);

            if (!empty($namaToko)) {
                $builder->like('toko.toko_name', $namaToko, 'both');
            }

            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            // Get paginated results
            $result = $builder->groupBy('toko.id')
                ->orderBy('toko.' . $sortBy, $sortMethod)
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
            $isAll = $this->request->getGet('is_all') === 'true';

            $query = $this->modelToko->builder()
                ->select('toko.id, toko.toko_name, toko.phone_number, toko.bank, toko.nama_pemilik, toko.nomer_rekening, toko.alamat, provincy.name as province_name, provincy.name as provincies_name, kota_kabupaten.name as city_name, kecamatan.name as district_name, kelurahan.name as village_name')
                ->join('provincy', 'toko.provinsi = provincy.code', 'left')
                ->join('kota_kabupaten', 'toko.kota_kabupaten = kota_kabupaten.code', 'left')
                ->join('kecamatan', 'toko.kecamatan = kecamatan.code', 'left')
                ->join('kelurahan', 'toko.kelurahan = kelurahan.code', 'left')
                ->where('toko.deleted_at', NULL);

            if (!$isAll) {
                $query->where('toko.type', 'CABANG');
            }

            if (!empty($tokoIds)) {
                $query->whereIn('toko.id', $tokoIds);
            }

            $result = $query->get()->getResult();

            $formattedResult = array_map(function ($row) {
                return [
                    'label' => $row->toko_name,
                    'value' => $row->id,
                    'phone_number' => $row->phone_number,
                    'bank' => $row->bank,
                    'nama_pemilik' => $row->nama_pemilik,
                    'nomer_rekening' => $row->nomer_rekening,
                    'alamat' => $row->alamat,
                    'province_name' => $row->province_name,
                    'provincies_name' => $row->provincies_name,
                    'city_name' => $row->city_name,
                    'district_name' => $row->district_name,
                    'village_name' => $row->village_name
                ];
            }, $result);

            return $this->jsonResponse->oneResp('', $formattedResult, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
}
