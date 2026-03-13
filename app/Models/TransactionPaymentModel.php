<?php

namespace App\Models;

class TransactionPaymentModel extends TenantScopedModel
{
    protected $table = 'transaction_payments';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'tenant_id',
        'transaction_id', 'amount', 'payment_method', 
        'status', 'paid_at', 'note', 'image_url', 
        'created_at', 'updated_at'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
