<?php

namespace App\Models;

use CodeIgniter\Model;

class StockModel extends Model
{
    protected $table = 'stock';
    protected $primaryKey = 'id';
    protected $allowedFields = ['id_barang', 'id_toko', 'stock', 'barang_cacat','dropship'];
}
