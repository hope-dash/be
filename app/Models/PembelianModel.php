<?php

namespace App\Models;

use CodeIgniter\Model;

class PembelianModel extends Model
{
    protected $table = 'pembelian';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tanggal_belanja',
        'supplier_id',
        'total_belanja',
        'catatan',
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'id_toko'
    ];

    protected $useTimestamps = true;
    protected $useSoftDeletes = true;

    // Optional: relasi dengan detail & biaya
    public function getWithDetails($id)
    {
        return $this->select('pembelian.*, suplier.suplier_name')
            ->join('suplier', 'suplier.id = pembelian.supplier_id', 'left')
            ->where('pembelian.id', $id)
            ->first();
    }
}
