<?php

namespace App\Models;

class ReturModel extends TenantScopedModel
{
    protected $table = 'retur';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id',
        'transaction_id',
        'kode_barang',
        'barang_cacat',
        'jumlah',
        'solution',
    ];



}
