<?php

namespace App\Models;

use CodeIgniter\Model;

class PembelianDetailModel extends Model
{
    protected $table = 'pembelian_detail';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'pembelian_id',
        'kode_barang',
        'jumlah',
        'harga_satuan',
        'ongkir',
        'total_harga'
    ];
}
