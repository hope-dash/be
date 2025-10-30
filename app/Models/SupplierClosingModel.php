<?php

namespace App\Models;

use CodeIgniter\Model;

class SupplierClosingModel extends Model
{
    protected $table = 'supplier_closing';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id',
        'kode_barang',
        'transaction_date',
        'jumlah',
        'harga_jual',
        'total',
        'harga_modal',
        'total_harga_modal',
        'dropship_suplier',
        'closing_month',
        'closing_date',
        'closing_status',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
}