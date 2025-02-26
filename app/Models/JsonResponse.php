<?php

namespace App\Models;

use CodeIgniter\Model;
use CodeIgniter\HTTP\ResponseInterface;
class JsonResponse extends Model
{
    public function oneResp($message = "", $data = null, $code = 200)
    {
        $res = [
            "status" => "success",
            "message" => $message,
            "data" => $data,
        ];
        return $this->response($res, $code);
    }

    public function error($message = "", $code = 400)
    {
        $res = [
            "status" => "error",
            "message" => $message,
        ];
        return $this->response($res, $code);
    }
}
