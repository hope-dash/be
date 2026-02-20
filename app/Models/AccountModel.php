<?php

namespace App\Models;

use CodeIgniter\Model;

class AccountModel extends Model
{
    protected $table = 'accounts';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'id_toko',
        'code',
        'base_code',
        'name',
        'type',
        'normal_balance',
        'created_at',
        'updated_at'
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Get account by base code and toko id
     */
    public function getByBaseCode($baseCode, $idToko = null)
    {
        return $this->where('base_code', $baseCode)
            ->where('id_toko', $idToko)
            ->first();
    }
}
