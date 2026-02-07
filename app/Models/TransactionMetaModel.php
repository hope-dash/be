<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionMetaModel extends Model
{
    protected $table = 'transaction_meta';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id', 'key', 'value',
        'created_at', 'updated_at'
    ];
}
