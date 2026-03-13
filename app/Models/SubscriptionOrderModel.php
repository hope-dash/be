<?php

namespace App\Models;

class SubscriptionOrderModel extends TenantScopedModel
{
    protected $table = 'subscription_orders';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;

    protected $allowedFields = [
        'tenant_id',
        'package_id',
        'external_transaction_id',
        'status',
        'amount',
        'currency',
        'paid_at',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

