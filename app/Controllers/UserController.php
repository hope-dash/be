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
    protected $responses;
    protected $JWToken;
    public function __construct()
    {
        $this->model = new UserModel();
        $this->responses = new JsonResponse();
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

                $insertedUserId = $this->model->insertID();

                $data['user_id'] = $insertedUserId;
                $token = $this->JWToken->generateToken($data);
                if ($token) {
                    return $this->responses->oneResp("Registrasi Berhasil", ["token" => $token, "user_id" => $insertedUserId]);
                }
            } else {
                return $this->responses->error("Tolong di Cek kembali");
            }

        } catch (\Exception $e) {
            return $this->responses->error($e->getMessage(), 400);
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
                    return $this->responses->oneResp("Login Berhasil", ["token" => $token, "user_id" => $query["user_id"]]);
                }
            } else {
                return $this->responses->error("Username atau Password Salah", 401);
            }

        } catch (\Exception $e) {
            return $this->responses->error($e->getMessage(), 400);
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
                return $this->responses->oneResp("Akun Berhasil Diperbaharui");
            } else {
                return $this->responses->error("Tolong di Cek kembali");
            }

        } catch (\Exception $e) {
            return $this->responses->error($e->getMessage(), 400);
        }
    }

    public function delete($id=null){
        {
            try{
                $query = $this->model->where("user_id", $id)
                                 ->first();
    
                if ($query) {
                    $this->model->delete($id);
                    return $this->responses->oneResp("Data Deleted", "", 200);
                } else {
                    return $this->responses->error("User Not Found", 401);
                }
    
            } catch(\Exception $e){
                return $this->respond([
                    "status" => "error",
                    "message" => $this->request->getVar("username")
                ], 400);
            }
        };
        }
    
        public function userById($id=null){
            try{
                $user = $this->model->where("user_id", $id);
                if($user){
                    $query = $this->model->first();
                    return $this->responses->oneResp("", $query, 200);
                } else {
                    return $this->responses->error("User Not Found", 401);
                }
                
            } catch(\Exception $e){
                return $this->respond($e->getMessage(), 400);
            }
        }
}
