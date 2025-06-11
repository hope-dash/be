<?php

namespace App\Models;

use CodeIgniter\Model;

class ClosingDetailModel extends Model
{
    protected $table = 'closing_detail';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'closing_id',
        'id_transaction',
        'kode_barang',
        'jumlah',
        'harga_system',
        'harga_jual',
        'total',
        'modal_system',
        'total_modal',
        'actual_per_piece',
        'actual_total',
    ];
}
