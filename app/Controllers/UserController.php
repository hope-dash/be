<?php

namespace App\Controllers;

use App\Models\JsonResponse;
use App\Models\Jwtoken;
use App\Models\UserModel;
use App\Controllers\TokoController;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\HTTP\ResponseInterface;


class UserController extends ResourceController
{
    /**
     * Return an array of resource objects, themselves in array format.
     *
     * @return ResponseInterface
     */
    protected $model;
    protected $jsonResponse;
    protected $JWToken;
    protected $tokoController;
    public function __construct()
    {
        $this->model = new UserModel();
        $this->tokoController = new TokoController();
        $this->jsonResponse = new JsonResponse();
        $this->JWToken = new Jwtoken();
    }
    public function create()
    {
        try {
            $token = $this->request->user ? $this->request->user : null;
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                "name" => 'required',
                "username" => 'required|is_unique[users.username]',
                "email" => 'required|valid_email|is_unique[users.email]',
                "password" => 'required',
                "access" => 'required',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }
            $data = [
                "name" => $data->name,
                "username" => $data->username,
                "email" => $data->email,
                "password" => password_hash($data->password, PASSWORD_DEFAULT),
                "access" => json_encode($data->access),
                "created_by" => $token['user_id'],
            ];
            if ($this->model->insert($data)) {
                return $this->jsonResponse->oneResp("User Created Successfully");
            } else {
                return $this->jsonResponse->error("Create Failed");
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }


    public function create_be()
    {
        try {
            $data = $this->request->getJSON();

            $validation = \Config\Services::validation();
            $validation->setRules([
                "name" => 'required',
                "username" => 'required|is_unique[users.username]',
                "email" => 'required|valid_email|is_unique[users.email]',
                "password" => 'required',
                "access" => 'required',
            ]);

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }
            $data = [
                "name" => $data->name,
                "username" => $data->username,
                "email" => $data->email,
                "password" => password_hash($data->password, PASSWORD_DEFAULT),
                "access" => json_encode($data->access),
                "created_by" => 0,
            ];
            if ($this->model->insert($data)) {
                return $this->jsonResponse->oneResp("User Created Successfully");
            } else {
                return $this->jsonResponse->error("Create Failed");
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function login()
    {
        try {
            $data = $this->request->getJSON();
            $query = $this->model->where("username", $data->umail)->orWhere("email", $data->umail)->where('deleted_at', NULL)->first();

            if ($query && password_verify($data->password, $query['password'])) {
                $userData = [
                    "user_id" => $query['user_id'],
                ];
                $token = $this->JWToken->generateToken($userData);
                if ($token) {
                    return $this->jsonResponse->oneResp("Login Berhasil", ["token" => $token]);
                }
            } else {
                return $this->jsonResponse->error("Username atau Password Salah", 401);
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
    public function edit($id = null)
    {
        $token = $this->request->user;
        try {
            $user = $this->model->find($id);
            $data = $this->request->getJson();

            if (!$user) {
                return $this->failNotFound("User with ID $id not found.");
            }

            $validation = \Config\Services::validation();
            $rules = [
                "name" => 'required',
                "username" => 'required|is_unique[users.username,user_id,' . $id . ']',
                "email" => 'required|valid_email|is_unique[users.email,user_id,' . $id . ']',
                "access" => 'required',
            ];


            if (!empty($data->password)) {
                $rules["password"] = 'min_length[8]';
            }

            $validation->setRules($rules);

            // Konversi stdClass ke array
            $dataArray = (array) $data;

            if (!$this->validate($validation->getRules())) {
                return $this->jsonResponse->error(implode(", ", $validation->getErrors()), 400);
            }

            // Siapkan data untuk diperbarui
            $updateData = [
                "name" => $dataArray['name'],
                "username" => $dataArray['username'],
                "email" => $dataArray['email'],
                "access" => json_encode($dataArray['access']),
                "permissions" => isset($dataArray['permissions']) ? json_encode($dataArray['permissions']) : null,
                "updated_by" => $token['user_id'],
            ];

            // Cek apakah password diisi
            if (!empty($dataArray['password'])) {
                $updateData["password"] = password_hash($dataArray['password'], PASSWORD_DEFAULT);
            }

            // Lakukan pembaruan
            $query = $this->model->update($id, $updateData);
            if ($query) {
                return $this->jsonResponse->oneResp("Akun Berhasil Diperbaharui");
            } else {
                return $this->jsonResponse->error("Tolong di Cek kembali");
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }



    public function delete($id = null)
    {
        try {
            $query = $this->model->where("user_id", $id)
                ->first();

            if ($query) {
                $this->model->delete($id);
                return $this->jsonResponse->oneResp("Data Deleted", "", 200);
            } else {
                return $this->jsonResponse->error("User Not Found", 401);
            }

        } catch (\Exception $e) {
            return $this->respond([
                "status" => "error",
                "message" => $this->request->getVar("username")
            ], 400);
        }

    }

    public function userById($id = null)
    {
        try {

            $query = $this->model->select('user_id, username, name, email, access, permissions, created_at, updated_at')->where("user_id", $id)->first();
            if ($query) {

                if (!empty($query['access'])) {
                    $query['access'] = json_decode($query['access'], true);
                }
                if (!empty($query['permissions'])) {
                    $query['permissions'] = json_decode($query['permissions'], true);
                }

                return $this->jsonResponse->oneResp("", $query, 200);
            } else {
                return $this->jsonResponse->error("User Not Found", 401);
            }

        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }


    public function userByToken()
    {
        $tokenUser = $this->request->user;
        $user = $this->model->find($tokenUser['user_id']);

        if (!$user) {
            return $this->jsonResponse->error("User Not Found", 401);
        }

        $accessArray = json_decode($user['access'], true);
        $permissions = !empty($user['permissions']) ? json_decode($user['permissions'], true) : [];

        $tokoDetails = [];

        foreach ($accessArray as $id) {
            $response = $this->tokoController->getDetailById($id);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);

                $tokoDetails[] = [
                    'label' => $data['data']['toko_name'],
                    'value' => $data['data']['id'],
                ];
            }
        }
        $result = [
            'user_id' => $user['user_id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'access' => $accessArray,
            'permissions' => $permissions,
            'toko' => $tokoDetails,
        ];

        // Mengembalikan response dalam format JSON
        return $this->jsonResponse->oneResp('', $result, 200);
    }


    public function getAllUser()
    {
        try {
            $sortBy = $this->request->getGet('sortBy') ?? 'user_id';
            $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
            $namaUser = $this->request->getGet('username') ?? '';
            $limit = (int) $this->request->getGet('limit') ?: 10;
            $page = (int) $this->request->getGet('page') ?: 1;

            $allowedSortBy = ['id', 'toko_name'];
            $allowedSortMethod = ['asc', 'desc'];

            $sortBy = in_array($sortBy, $allowedSortBy) ? $sortBy : 'user_id';
            $sortMethod = in_array($sortMethod, $allowedSortMethod) ? $sortMethod : 'asc';

            $offset = ($page - 1) * $limit;

            $builder = $this->model;

            if (!empty($namaUser)) {
                $builder = $builder->like('username', $namaUser, 'both');
            }

            $total_data = $builder->countAllResults(false);
            $total_page = ceil($total_data / $limit);

            $result = $builder->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset)
                ->get()
                ->getResult();

            // Process each user to convert access string to array and get toko_name
            foreach ($result as &$user) {
                // Decode the access string to an array
                $user->access = json_decode($user->access, true);

                // Retrieve toko_name for each access id
                $tokoNames = [];
                foreach ($user->access as $id) {
                    if ($id == "0") {
                        $tokoNames[] = "Admin";
                    } else {
                        $response = $this->tokoController->getDetailById($id);
                        if ($response->getStatusCode() === 200) {
                            $data = json_decode($response->getBody(), true);

                            $tokoNames[] = $data['data']['toko_name'];
                        }
                    }

                }
                $user->toko_names = $tokoNames;
            }

            return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, $page, $limit, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }

    public function dropdownUser()
    {
        try {

            $result = $this->model->select('user_id, name')->where('deleted_at', NULL)->get()->getResult();


            $formattedResult = array_map(function ($row) {
                return [
                    'label' => $row->name,
                    'value' => $row->user_id
                ];
            }, $result);
            return $this->jsonResponse->oneResp('', $formattedResult, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }



}
