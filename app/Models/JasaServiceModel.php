<?php

namespace App\Models;

class JasaServiceModel extends TenantScopedModel
{
    protected $table = 'jasa_service';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id',
        'id_toko',
        'nama_jasa',
        'kategori',
        'komisi',
        'harga',
        'created_by',
        'updated_by'
    ];

    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $validationRules = [
        'nama_jasa' => 'required|min_length[3]|max_length[255]',
        'kategori'  => 'required|in_list[HARDWARE,SOFTWARE]',
        'harga'     => 'required|numeric|greater_than_equal_to[0]',
        'komisi'    => 'required|numeric|greater_than_equal_to[0]',
        'id_toko'   => 'permit_empty|integer'
    ];
}
