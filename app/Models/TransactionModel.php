<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionModel extends Model
{
    protected $table = 'transaction';
    protected $primaryKey = 'id';
    protected $allowedFields = ['amount', 'total_payment', 'po', 'status', 'delivery_status', 'discount_type', 'discount_amount', 'invoice', 'id_toko', 'date_time', 'created_by', 'updated_by','actual_total','total_modal'];

    protected $statuses = [
        'SUCCESS' => 'Success',
        'WAITING_PAYMENT' => 'Waiting for Payment',
        'FAILED' => 'Failed',
        'CANCEL' => 'Cancelled',
        'REFUNDED' => 'Refunded',
        'NEED_REFUNDED' => 'Need to Refund',
        'RETUR' => 'Retur',
        'PAID' => 'Paid',
        'PACKING' => 'Packing',
        'IN_DELIVERY' => 'In Delivery',
        'PARTIALLY_PAID' => 'DP',

    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function getStatuses()
    {
        return $this->statuses;
    }


}
