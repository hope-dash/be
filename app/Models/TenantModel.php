<?php

namespace App\Models;

use CodeIgniter\Model;

class TenantModel extends Model
{
    protected $table = 'tenants';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;

    protected $allowedFields = [
        'code',
        'name',
        'logo_url',
        'url',
        'email',
        'status',
        'moota_token',
        'integration_tiktok_active',
        'integration_shopee_active',
        'integration_email_active',
        'integration_moota_active',
        'integration_whatsapp_active',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
