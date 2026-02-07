<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionPaymentModel extends Model
{
    protected $table = 'transaction_payments';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id', 'amount', 'payment_method', 
        'status', 'paid_at', 'note', 'image_url', 
        'created_at', 'updated_at'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
