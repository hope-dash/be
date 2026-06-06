<?php

namespace App\Models;

class TeknisiKomisiModel extends TenantScopedModel
{
    protected $table = 'teknisi_komisi';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id',
        'id_toko',
        'transaction_id',
        'sales_product_id',
        'jasa_service_id',
        'teknisi_id',
        'komisi_persen',
        'harga_jasa',
        'komisi_nominal',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
