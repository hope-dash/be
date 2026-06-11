<?php

namespace App\Models;

class CustomerPointHistoryModel extends TenantScopedModel
{
    protected $table = 'customer_point_history';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $protectFields = true;
    protected $allowedFields = [
        'tenant_id',
        'customer_id',
        'transaction_id',
        'points_change',
        'balance_after',
        'type',
        'description',
        'created_at',
        'updated_at'
    ];
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
