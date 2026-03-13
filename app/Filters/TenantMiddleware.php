<?php

namespace App\Filters;

use App\Libraries\TenantContext;
use App\Models\TenantModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class TenantMiddleware implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Allow preflight requests without tenant resolution.
        if (strtolower($request->getMethod()) === 'options') {
            return;
        }

        $tenantCode = trim((string) $request->getHeaderLine('X-Tenant'));
        if ($tenantCode === '') {
            return service('response')->setJSON([
                'status' => 400,
                'message' => 'Bad Request: X-Tenant header is required',
            ])->setStatusCode(400);
        }

        $tenantModel = new TenantModel();
        $tenant = $tenantModel->where('code', $tenantCode)->where('status', 'active')->first();

        if (!$tenant) {
            return service('response')->setJSON([
                'status' => 404,
                'message' => 'Tenant not found or inactive',
            ])->setStatusCode(404);
        }

        // Store for controller usage and for global access (models/helpers).
        $request->tenant = $tenant;
        TenantContext::set($tenant);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

