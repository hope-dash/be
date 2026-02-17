<?php

namespace App\Models;

use CodeIgniter\Model;

class CartModel extends Model
{
    protected $table = 'cart';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'customer_id',
        'id_barang',
        'jumlah',
        'id_toko',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
}
