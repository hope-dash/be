<?php

namespace App\Models;

class JournalModel extends TenantScopedModel
{
    protected $table = 'journals';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'tenant_id',
        'id_toko',
        'reference_type', 'reference_id', 'reference_no', 'date', 
        'description', 'total_debit', 'total_credit', 
        'created_at', 'updated_at'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
