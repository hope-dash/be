<?php

namespace App\Models;

use CodeIgniter\Model;

class TenantSubscriptionModel extends Model
{
    protected $table = 'tenant_subscriptions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;

    protected $allowedFields = [
        'tenant_id',
        'package_id',
        'status',
        'start_at',
        'end_at',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

