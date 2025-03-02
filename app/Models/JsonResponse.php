<?php

namespace App\Models;

use CodeIgniter\HTTP\ResponseInterface;

class JsonResponse
{
    public function oneResp($message = "", $data = null, $code = 200): ResponseInterface
    {
        $res = [
            "status" => true,
            "message" => $message,
            "data" => $data,
        ];
        return service('response')->setJSON($res)->setStatusCode($code);
    }

    public function multiResp($message = "", $data = null, $total_data = 0, $total_page = 0, $curent_page=1, $limit_data = 10,$code = 200): ResponseInterface
    {
        $res = [
            "status" => true,
            "message" => $message,
            "page" => [
                "total_data" => $total_data,
                "total_page" => $total_page,
                "curent_page" => $curent_page,
                "limit_data" => $limit_data,
            ],
            "data" => $data,
        ];
        return service('response')->setJSON($res)->setStatusCode($code);
    }

    public function error($message = "", $code = 400): ResponseInterface
    {
        $res = [
            "code" => $code,
            "status" => false,
            "message" => $message,
        ];
        return service('response')->setJSON($res)->setStatusCode($code);
    }
}
