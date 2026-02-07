<?php

namespace App\Models;

use CodeIgniter\Model;

class JournalModel extends Model
{
    protected $table = 'journals';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'id_toko',
        'reference_type', 'reference_id', 'date', 
        'description', 'total_debit', 'total_credit', 
        'created_at', 'updated_at'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
