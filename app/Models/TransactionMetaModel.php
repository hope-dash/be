<?php

namespace App\Models;

class TransactionMetaModel extends TenantScopedModel
{
    protected $table = 'transaction_meta';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'tenant_id',
        'transaction_id', 'key', 'value',
        'created_at', 'updated_at'
    ];
}
