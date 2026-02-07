<?php

namespace App\Models;

use CodeIgniter\Model;

class StockLedgerModel extends Model
{
    protected $table = 'stock_ledgers';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'id_barang', 'id_toko', 'qty', 'balance', 
        'reference_type', 'reference_id', 'description', 
        'created_at', 'updated_at'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
