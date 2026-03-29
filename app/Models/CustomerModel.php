<?php

namespace App\Models;

class CustomerModel extends TenantScopedModel
{
    protected $table = 'customer';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $protectFields = true;
    protected $allowedFields = [
        'tenant_id',
        'nama_customer',
        'no_hp_customer',
        'alamat',
        'provinsi',
        'kota_kabupaten',
        'kecamatan',
        'kelurahan',
        'kode_pos',
        'type',
        'username',
        'email',
        'password',
        'email_verified_at',
        'email_verification_token',
        'otp_code',
        'otp_expires_at',
        'discount_type',
        'discount_value',
        'reset_token',
        'reset_expiry',
        'created_by',
        'updated_by'
    ];
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

}
