<?php

namespace App\Models;

use CodeIgniter\Model;

class PembelianBiayaModel extends Model
{
    protected $table = 'pembelian_biaya';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'pembelian_id', 'nama_biaya', 'jumlah'
    ];
}
