<?php

namespace App\Models;

use CodeIgniter\Model;

class SalesProductModel extends Model
{
    protected $table = 'sales_product';
    protected $primaryKey = 'id';
    protected $allowedFields = ['id_transaction', 'actual_per_piece', 'actual_total', 'kode_barang', 'jumlah', 'harga_system', 'harga_jual', 'total', 'modal_system', 'total_modal', 'margin', 'closing', 'dropship_suplier', 'discount_type', 'discount_amount'];
}
