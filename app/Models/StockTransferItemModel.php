<?php

namespace App\Models;

use CodeIgniter\Model;

class StockTransferItemModel extends Model
{
    protected $table = 'stock_transfer_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transfer_id', 
        'kode_barang', 
        'qty', 
        'harga_modal'
    ];
}
