<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\CustomerModel;
use App\Models\JsonResponse;
use CodeIgniter\HTTP\ResponseInterface;

class CustomerController extends BaseController
{
    protected $customer;
    protected $jsonResponse;

    public function __construct()
    {
        $this->customer = new CustomerModel();
        $this->jsonResponse = new JsonResponse();
    }
    public function createCustomer()
    {
        try {
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                'nama_customer' => 'required',
                'alamat' => 'required',
                'no_hp_customer' => 'required|min_length[10]|max_length[15]|is_unique[customer.no_hp_customer]',
                'email' => 'permit_empty|valid_email|is_unique[customer.email]',
                'provinsi' => 'permit_empty',
                'kota_kabupaten' => 'permit_empty',
                'kecamatan' => 'permit_empty',
                'kelurahan' => 'permit_empty',
                'kode_pos' => 'permit_empty',
                'discount_type' => 'permit_empty|in_list[percentage,fixed]',
                'discount_value' => 'permit_empty|numeric',
                'type' => 'permit_empty|in_list[regular,special]',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $customerData = [
                'nama_customer' => $data->nama_customer,
                'username' => $data->username ?? NULL,
                'type' => $data->type ?? 'regular',
                'alamat' => $data->alamat,
                'no_hp_customer' => $data->no_hp_customer,
                'email' => $data->email ?? NULL,
                'provinsi' => $data->provinsi ?? NULL,
                'kota_kabupaten' => $data->kota_kabupaten ?? $data->kota ?? NULL,
                'kecamatan' => $data->kecamatan ?? NULL,
                'kelurahan' => $data->kelurahan ?? NULL,
                'kode_pos' => $data->kode_pos ?? NULL,
                'discount_type' => $data->discount_type ?? NULL,
                'discount_value' => $data->discount_value ?? 0,
            ];


            $query = $this->customer->insert($customerData);

            if ($query) {
                $insertid = $this->customer->insertId();
                return $this->jsonResponse->oneResp('Customer Added', ['customer_id' => $insertid]);
            }
            else {
                return $this->jsonResponse->error("Add Customer Failed");

            }
        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage());
        }
    }

    public function updateCustomer($id = null)
    {
        try {
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                'nama_customer' => 'required',
                'alamat' => 'required',
                'no_hp_customer' => "required|min_length[10]|max_length[15]|is_unique[customer.no_hp_customer,id,{$id}]",
                'email' => "permit_empty|valid_email|is_unique[customer.email,id,{$id}]",
                'provinsi' => 'permit_empty',
                'kota_kabupaten' => 'permit_empty',
                'kecamatan' => 'permit_empty',
                'kelurahan' => 'permit_empty',
                'kode_pos' => 'permit_empty',
                'discount_type' => 'permit_empty|in_list[percentage,fixed]',
                'discount_value' => 'permit_empty|numeric',
                'type' => 'permit_empty|in_list[regular,special]',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $customerData = [
                'nama_customer' => $data->nama_customer,
                'username' => $data->username ?? NULL,
                'type' => $data->type ?? 'regular',
                'alamat' => $data->alamat,
                'no_hp_customer' => $data->no_hp_customer,
                'email' => $data->email ?? NULL,
                'provinsi' => $data->provinsi ?? NULL,
                'kota_kabupaten' => $data->kota_kabupaten ?? $data->kota ?? NULL,
                'kecamatan' => $data->kecamatan ?? NULL,
                'kelurahan' => $data->kelurahan ?? NULL,
                'kode_pos' => $data->kode_pos ?? NULL,
                'discount_type' => $data->discount_type ?? NULL,
                'discount_value' => $data->discount_value ?? 0,
            ];

            $query = $this->customer->update($id, $customerData);

            if ($query) {
                return $this->jsonResponse->oneResp('Customer Updated', ['customer_id' => $id]);
            }
            else {
                return $this->jsonResponse->error("Update Customer Failed");

            }
        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage());
        }
    }

    public function deleteCustomer($id = null)
    {
        try {
            $query = $this->customer->where("id", $id)
                ->first();

            if ($query) {
                $this->customer->delete($id);
                return $this->jsonResponse->oneResp("Data Deleted", "", 200);
            }
            else {
                return $this->jsonResponse->error("Customer Not Found", 401);
            }

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage());
        }
    }

    public function getByIdCustomer($id = null)
    {
        try {
            $query = $this->customer->builder()
                ->select('customer.*, provincy.name as nama_provinsi, kota_kabupaten.name as nama_kota, kecamatan.name as nama_kecamatan, kelurahan.name as nama_kelurahan')
                ->join('provincy', 'customer.provinsi = provincy.code', 'left')
                ->join('kota_kabupaten', 'customer.kota_kabupaten = kota_kabupaten.code', 'left')
                ->join('kecamatan', 'customer.kecamatan = kecamatan.code', 'left')
                ->join('kelurahan', 'customer.kelurahan = kelurahan.code', 'left')
                ->where('customer.id', $id)
                ->get()
                ->getRowArray();

            if ($query) {
                return $this->jsonResponse->oneResp("", $query, 200);
            }
            else {
                return $this->jsonResponse->error("User Not Found", 404);
            }

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function getAllCustomer()
    {
        try {
            $sortBy = $this->request->getGet('sortBy') ?? 'id';
            $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
            $namaUser = $this->request->getGet('nama_customer') ?? $this->request->getGet('search') ?? '';
            $limit = max((int)($this->request->getGet('limit') ?: 10), 1);
            $page = max((int)($this->request->getGet('page') ?: 1), 1);
            $offset = ($page - 1) * $limit;

            $allowedSortBy = ['id', 'nama_customer'];
            $allowedSortMethod = ['asc', 'desc'];

            $sortBy = in_array($sortBy, $allowedSortBy) ? $sortBy : 'id';
            $sortMethod = in_array($sortMethod, $allowedSortMethod) ? $sortMethod : 'asc';

            // Base query for filtering
            $builder = $this->customer->builder()
                ->select('customer.*, provincy.name as nama_provinsi, kota_kabupaten.name as nama_kota, kecamatan.name as nama_kecamatan, kelurahan.name as nama_kelurahan')
                ->join('provincy', 'customer.provinsi = provincy.code', 'left')
                ->join('kota_kabupaten', 'customer.kota_kabupaten = kota_kabupaten.code', 'left')
                ->join('kecamatan', 'customer.kecamatan = kecamatan.code', 'left')
                ->join('kelurahan', 'customer.kelurahan = kelurahan.code', 'left')
                ->where('customer.deleted_at', null);

            if (!empty($namaUser)) {
                $builder->like('customer.nama_customer', $namaUser, 'both');
            }

            // Count total data before applying limit
            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            // Fetch paginated data
            $result = $builder
                ->orderBy('customer.' . $sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResult();

            return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function checkSpecialCustomer()
    {
        try {
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                'username' => 'required',
                'phone_number' => 'required|min_length[10]|max_length[15]',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            $username = $data->username;
            $phone_number = $data->phone_number;

            $customer = $this->customer->builder()
                ->select('customer.*, provincy.name as nama_provinsi, kota_kabupaten.name as nama_kota, kecamatan.name as nama_kecamatan, kelurahan.name as nama_kelurahan')
                ->join('provincy', 'customer.provinsi = provincy.code', 'left')
                ->join('kota_kabupaten', 'customer.kota_kabupaten = kota_kabupaten.code', 'left')
                ->join('kecamatan', 'customer.kecamatan = kecamatan.code', 'left')
                ->join('kelurahan', 'customer.kelurahan = kelurahan.code', 'left')
                ->where('customer.username', $username)
                ->where('customer.no_hp_customer', $phone_number)
                ->where('customer.type', 'special')
                ->where('customer.deleted_at', null)
                ->get()
                ->getRowArray();

            if (!$customer) {
                return $this->jsonResponse->error("Customer not found or not special", 404);
            }

            return $this->jsonResponse->oneResp("Customer found", $customer);

        }
        catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 500);
        }
    }


}