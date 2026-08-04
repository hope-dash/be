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

        if ($origin === '*') {
            header("Access-Control-Allow-Origin: *");
        } else {
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Credentials: true");
        }

        // Allow any requested headers dynamically, or fallback to a broad list
        $reqHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
        if ($reqHeaders) {
            header("Access-Control-Allow-Headers: $reqHeaders");
        } else {
            header("Access-Control-Allow-Headers: *");
        }

        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PATCH, PUT, DELETE");
        header("Access-Control-Expose-Headers: Content-Disposition, Content-Length, X-Filename, *");

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
