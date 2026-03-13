<?php

namespace App\Models;

class PembelianBiayaModel extends TenantScopedModel
{
    protected $table = 'pembelian_biaya';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id',
        'pembelian_id', 'nama_biaya', 'jumlah'
    ];
}
