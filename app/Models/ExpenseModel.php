<?php

namespace App\Models;

use CodeIgniter\Model;

class ExpenseModel extends Model
{
    protected $table = 'expenses';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'id_toko',
        'account_id',
        'amount',
        'payment_method',
        'date',
        'description',
        'attachment',
        'created_at',
        'updated_at',
        'deleted_at'
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
    protected $useSoftDeletes = true;
}
