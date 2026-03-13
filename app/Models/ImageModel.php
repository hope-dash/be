<?php

namespace App\Models;

class ImageModel extends TenantScopedModel
{
    protected $table = 'image';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'type', 'kode', 'url'];
    protected $validationRules = [
        'kode' => 'required|alpha_numeric',
        'url' => 'required|valid_url',
    ];


}
