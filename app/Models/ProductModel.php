<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table = 'product';
    protected $primaryKey = 'id';
    protected $allowedFields = ['id_barang', 'description', 'suplier', 'dropship', 'id_model_barang', 'nama_barang', 'id_seri_barang', 'harga_modal', 'harga_jual', 'harga_jual_toko', 'notes', "created_by", "updated_by"];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
