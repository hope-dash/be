<?php

namespace App\Models;

class StockTransferModel extends TenantScopedModel
{
    protected $table = 'stock_transfer';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'ref_id', 
        'source_toko_id', 
        'target_toko_id', 
        'status', 
        'note', 
        'date', 
        'total_value', 
        'ongkos_kirim', 
        'payment_method', 
        'created_by', 
        'approved_by', 
        'tenant_id'
    ];
    protected $useTimestamps = true;
    protected $returnType = 'array';
}
