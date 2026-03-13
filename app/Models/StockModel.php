<?php

namespace App\Models;

class StockModel extends TenantScopedModel
{
    protected $table = 'stock';
    protected $primaryKey = 'id';
    protected $allowedFields = ['tenant_id', 'id_barang', 'id_toko', 'stock', 'barang_cacat', 'dropship'];
}
