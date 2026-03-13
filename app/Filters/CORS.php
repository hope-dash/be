<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CORS implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $origin = $request->getHeaderLine('Origin') ?: '*';

        // Use PHP header() for maximum reliability across CI4 lifecycle
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PATCH, PUT, DELETE");
        header("Access-Control-Allow-Headers: X-API-KEY, X-Tenant, Origin, X-Requested-With, Content-Type, Accept, Authorization, Access-Control-Request-Method, Access-Control-Request-Headers");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Expose-Headers: Content-Disposition, Content-Length, X-Filename");

        if (strcasecmp($request->getMethod(), 'options') === 0) {
            header("HTTP/1.1 200 OK");
            exit;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Headers already sent in before() via header()
        return $response;
    }
}
