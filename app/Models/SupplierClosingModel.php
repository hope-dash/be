<?php

namespace App\Models;

class SupplierClosingModel extends TenantScopedModel
{
    protected $table = 'supplier_closing';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'tenant_id',
        'transaction_id',
        'kode_barang',
        'transaction_date',
        'jumlah',
        'harga_jual',
        'total',
        'harga_modal',
        'total_harga_modal',
        'dropship_suplier',
        'closing_month',
        'closing_date',
        'closing_status',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
}
