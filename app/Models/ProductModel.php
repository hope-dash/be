<?php

namespace App\Models;

class ProductModel extends TenantScopedModel
{
    protected $table = 'product';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = true;
    protected $allowedFields = ['tenant_id', 'id_barang', 'description', 'suplier', 'dropship', 'id_model_barang', 'nama_barang', 'id_seri_barang', 'harga_modal', 'harga_jual', 'harga_jual_toko', 'notes', 'berat', "created_by", "updated_by"];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
}
