<?php

namespace App\Models;

use CodeIgniter\Model;

class ClosingModel extends Model
{
    protected $table = 'closing';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'date_start',
        'date_end',
        'status',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
        'total_pemasukan',
        'total_modal',
        'total_laba',
        'total_beban',
        'id_toko'
    ];
}
