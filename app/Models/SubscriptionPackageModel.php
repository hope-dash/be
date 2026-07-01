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
        'type',
        'wording',
        'description',
        'price',
        'currency',
        'duration_months',
        'product_quota',
        'transaction_monthly_quota',
        'integration_tiktok',
        'integration_shopee',
        'integration_email',
        'integration_moota',
        'integration_whatsapp',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

