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

    public function multiResp($message = "", $data = null, $total_data = 0, $total_page = 0, $code = 200): ResponseInterface
    {
        $res = [
            "status" => "success",
            "message" => $message,
            "total_data" => $total_data,
            "total_page" => $total_page,
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
