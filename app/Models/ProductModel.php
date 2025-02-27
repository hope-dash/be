<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table = 'product';
    protected $primaryKey = 'id';
    protected $allowedFields = ['id_barang', 'nama_barang', 'id_seri_barang', 'harga_modal', 'harga_jual'];
}
