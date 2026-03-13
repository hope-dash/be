<?php

namespace App\Models;

class CartModel extends TenantScopedModel
{
    protected $table = 'cart';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'tenant_id',
        'customer_id',
        'id_barang',
        'jumlah',
        'id_toko',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
}
