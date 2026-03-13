<?php

namespace App\Models;

use CodeIgniter\Model;

class SubscriptionPackageModel extends Model
{
    protected $table = 'subscription_packages';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;

    protected $allowedFields = [
        'code',
        'name',
        'price',
        'currency',
        'duration_months',
        'product_quota',
        'transaction_monthly_quota',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

