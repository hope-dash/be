<?php

namespace App\Models;

class TenantQuotaModel extends TenantScopedModel
{
    protected $table = 'tenant_quota';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;

    protected $allowedFields = [
        'tenant_id',
        'month_start',
        'product_quota',
        'product_used',
        'transaction_monthly_quota',
        'transaction_monthly_used',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

