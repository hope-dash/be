<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionModel extends Model
{
    protected $table = 'transaction';
    protected $primaryKey = 'id';
    protected $allowedFields = ['amount', 'total_payment', 'status', 'invoice', 'id_toko', 'date_time'];

    protected $statuses = [
        'SUCCESS' => 'Success',
        'WAITING_PAYMENT' => 'Waiting for Payment',
        'FAILED' => 'Failed',
        'CANCEL' => 'Cancelled',
        'REFUNDED' => 'Refunded',
    ];

    public function getStatuses()
    {
        return $this->statuses;
    }


}
