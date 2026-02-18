<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CORS implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Handle preflight (OPTIONS) requests
        if ($request->getMethod() === 'options') {
            $response = service('response');

            $response->setHeader('Access-Control-Allow-Origin', '*');
            $response->setHeader('Access-Control-Allow-Headers', 'X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Authorization, Access-Control-Request-Method');
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PATCH, PUT, DELETE');
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
            $response->setHeader('Access-Control-Expose-Headers', 'Content-Disposition, Content-Length, X-Filename');

            $response->setStatusCode(200);
            $response->send();
            exit; // Force exit to ensure no other output or filters interfere
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Set CORS headers on all outgoing responses
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PATCH, PUT, DELETE');
        $response->setHeader('Access-Control-Allow-Headers', 'X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Authorization');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
        $response->setHeader('Access-Control-Expose-Headers', 'Content-Disposition, Content-Length, X-Filename');

        return $response;
    }
}
