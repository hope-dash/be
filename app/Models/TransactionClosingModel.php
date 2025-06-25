<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionClosingModel extends Model
{
    protected $table = 'transaction_closing';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id',
        'period_start',
        'period_end',
        'closing_status',
        'payment_count',
        'total_debit',
        'total_credit',
        'total_profit',
        'total_modal',
        'closing_date',
        'created_at',
        'updated_at'
    ];
    protected $useTimestamps = false;

    // Ambil data closing berdasarkan date range tertentu
    public function getByPeriodRange(string $startDate, string $endDate)
    {
        return $this->where('period_start >=', $startDate)
            ->where('period_end <=', $endDate)
            ->findAll();
    }

    // Update status closing berdasarkan id
    public function updateStatus(int $id, int $status)
    {
        return $this->update($id, ['closing_status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }


}
