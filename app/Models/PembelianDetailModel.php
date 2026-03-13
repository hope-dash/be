<?php

namespace App\Models;

class PembelianDetailModel extends TenantScopedModel
{
    protected $table = 'pembelian_detail';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id',
        'pembelian_id',
        'kode_barang',
        'jumlah',
        'harga_satuan',
        'harga_jual',
        'ongkir',
        'total_harga'
    ];
}
