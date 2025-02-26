<?php

namespace App\Models;

use CodeIgniter\HTTP\ResponseInterface;

class JsonResponse
{
    public function oneResp($message = "", $data = null, $code = 200): ResponseInterface
    {
        $res = [
            "status" => "success",
            "message" => $message,
            "data" => $data,
        ];
        return service('response')->setJSON($res)->setStatusCode($code);
    }

    public function error($message = "", $code = 400): ResponseInterface
    {
        $res = [
            "status" => "error",
            "message" => $message,
        ];
        return service('response')->setJSON($res)->setStatusCode($code);
    }
}
