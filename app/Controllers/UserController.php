<?php

namespace App\Controllers;

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
    public function __construct(){
        $this->model = new UserModel();
    }
    public function create()
    {
        try{
            $data = [
                "name" => $this->request->getVar("name"),
                "username"=> $this->request->getVar("username"),
                "email"=> $this->request->getVar("email"),
                "password" => password_hash($this->request->getVar("password"), PASSWORD_DEFAULT),
                "access"=> $this->request->getVar("access"),
            ];
            $query = $this->model->save($data);
            if($query){
                return $this->respond([
                    "status" => "success",
                    "message"=> "Registrasi Berhasil"
                ],200);
            } else{
                return $this->fail("Error");
            }

        } catch(\Exception $e){
            return $this->respond($e->getMessage(), 400);
        }
    }

    public function login()
    {
        try{
            $query = $this->model->where("username", $this->request->getVar("username"))
                             ->first();

            if ($query && password_verify($this->request->getVar("password"), $query['password'])) {
                return $this->respond([
                    "status" => "success",
                    "message" => "Login Berhasil"
                ], 200);
            } else {
                return $this->respond([
                    "status" => "fail",
                    "message" => "Username atau Password salah."
                ], 401);
            }

        } catch(\Exception $e){
            return $this->respond([
                "status" => "error",
                "message" => $this->request->getVar("username")
            ], 400);
        }
    }

    public function edit($id=null){
        try{
            $user = $this->model->find($id);
            if (!$user) {
                return $this->failNotFound("User with ID $id not found.");
            }
            $data = [
                "name" => $this->request->getVar("name"),
                "username"=> $this->request->getVar("username"),
                "email"=> $this->request->getVar("email"),
                "password" => password_hash($this->request->getVar("password"), PASSWORD_DEFAULT),
                "access"=> $this->request->getVar("access"),
            ];
            $query = $this->model->update($id, $data);
            if($query){
                return $this->respond([
                    "status" => "success",
                    "message"=> "Update Berhasil"
                ],200);
            } else{
                return $this->fail("Error");
            }

        } catch(\Exception $e){
            return $this->respond($e->getMessage(), 400);
        }
    }

    public function delete($id=null){
    {
        try{
            $query = $this->model->where("user_id", $id)
                             ->first();

            if ($query) {
                $this->model->delete($id);
                return $this->respond([
                    "status" => "success",
                    "message" => "Data Berhasil Dihapus"
                ], 200);
            } else {
                return $this->respond([
                    "status" => "error",
                    "message" => "User tidak ditermukan."
                ], 401);
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
            $user = $this->model->find($id);
            if (!$user) {
                return $this->failNotFound("User with ID $id not found.");
            }
            $query = $this->model->where("user_id", $id)->first();
            if($query){
                return $this->respond([
                    "status" => "success",
                    "message"=> "",
                    "data" => $query
                ],200);
            } else{
                return $this->fail("Error");
            }

        } catch(\Exception $e){
            return $this->respond($e->getMessage(), 400);
        }
    }
}
