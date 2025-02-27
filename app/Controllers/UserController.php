<?php

namespace App\Controllers;

use App\Models\JsonResponse;
use App\Models\Jwtoken;
use App\Models\UserModel;
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
    public function __construct()
    {
        $this->model = new UserModel();
        $this->jsonResponse = new JsonResponse();
        $this->JWToken = new Jwtoken();
    }
    public function create()
    {
        try {
            $data = [
                "name" => $this->request->getVar("name"),
                "username" => $this->request->getVar("username"),
                "email" => $this->request->getVar("email"),
                "password" => password_hash($this->request->getVar("password"), PASSWORD_DEFAULT),
                "access" => $this->request->getVar("access"),
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
            $query = $this->model->where("username", $this->request->getVar("username"))
                ->first();

            if ($query && password_verify($this->request->getVar("password"), $query['password'])) {
                $data["user_id"] = $query["user_id"];
                $token = $this->JWToken->generateToken($data);
                if ($token) {
                    return $this->jsonResponse->oneResp("Login Berhasil", ["token" => $token, "user_id" => $query["user_id"]]);
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
        try {
            $user = $this->model->find($id);
            if (!$user) {
                return $this->failNotFound("User with ID $id not found.");
            }
            $data = [
                "name" => $this->request->getVar("name"),
                "username" => $this->request->getVar("username"),
                "email" => $this->request->getVar("email"),
                "password" => password_hash($this->request->getVar("password"), PASSWORD_DEFAULT),
                "access" => $this->request->getVar("access"),
            ];
            $query = $this->model->update($id, $data);
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
    { {
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
        ;
    }

    public function userById($id = null)
    {
        try {
            $user = $this->model->where("user_id", $id);
            if ($user) {
                $query = $this->model->first();
                return $this->jsonResponse->oneResp("", $query, 200);
            } else {
                return $this->jsonResponse->error("User Not Found", 401);
            }

        } catch (\Exception $e) {
            return $this->respond($e->getMessage(), 400);
        }
    }

    public function getAllUser()
    {
        try {
            $sortBy = $this->request->getGet('sortBy') ?? 'id';
            $sortMethod = strtolower($this->request->getGet('sortMethod')) ?? 'asc';
            $namaUser = $this->request->getGet('username') ?? '';
            $limit = (int) $this->request->getGet('limit') ?? 10;
            $page = (int) $this->request->getGet('page') ?? 1;

            $allowedSortBy = ['id', 'toko_name', 'alamat', 'phone_number', 'email_toko'];
            $allowedSortMethod = ['asc', 'desc'];

            $sortBy = in_array($sortBy, $allowedSortBy) ? $sortBy : 'id';
            $sortMethod = in_array($sortMethod, $allowedSortMethod) ? $sortMethod : 'asc';

            $offset = ($page - 1) * $limit;

            $result = $this->model->orderBy($sortBy, $sortMethod)
                ->limit($limit, $offset);

            if (!empty($namaUser)) {
                $result = $result->like('username', $namaUser, 'both');
            }

            $result = $result->get()->getResult();

            if (!$result) {
                return $this->jsonResponse->multiResp('', [], 0, 0, 200);
            }

            $total_data = count($result);
            $total_page = ceil($total_data / $limit);

            return $this->jsonResponse->multiResp('', $result, $total_data, $total_page, 200);
        } catch (\Exception $e) {
            return $this->jsonResponse->error($e->getMessage(), 400);
        }
    }
}
