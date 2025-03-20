<?php

namespace App\Models;

use CodeIgniter\Model;

class ReturModel extends Model
{
    protected $table = 'retur';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'transaction_id',
        'kode_barang',
        'barang_cacat',
        'jumlah',
        'solution',
    ];



}
