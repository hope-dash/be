<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionModel extends Model
{
    protected $table = 'transaction';
    protected $primaryKey = 'id';
    protected $allowedFields = ['amount', 'total_payment', 'po', 'status', 'invoice', 'id_toko', 'date_time','created_by','updated_by'];

    protected $statuses = [
        'SUCCESS' => 'Success',
        'WAITING_PAYMENT' => 'Waiting for Payment',
        'FAILED' => 'Failed',
        'CANCEL' => 'Cancelled',
        'REFUNDED' => 'Refunded',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function getStatuses()
    {
        return $this->statuses;
    }


}
