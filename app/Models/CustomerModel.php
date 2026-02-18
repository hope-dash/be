<?php

namespace App\Models;

use CodeIgniter\Model;

class CustomerModel extends Model
{
    protected $table = 'customer';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $protectFields = true;
    protected $allowedFields = [
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
