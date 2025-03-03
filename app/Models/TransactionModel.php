<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionModel extends Model
{
    protected $table = 'transaction';
    protected $primaryKey = 'id';
    protected $allowedFields = ['amount', 'status','notes', 'type', 'id_toko', 'date_time'];

    public function getCashflow($filters = [], $limit = 10, $offset = 0)
    {

        if (!empty($filters['status'])) {
            $this->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $this->where('type', $filters['type']);
        }
        if (!empty($filters['id_toko'])) {
            $this->where('id_toko', $filters['id_toko']);
        }
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $this->where('date_time >=', $filters['start_date']);
            $this->where('date_time <=', $filters['end_date']);
        }

        return $this->orderBy('date_time', 'DESC')
            ->findAll($limit, $offset);
    }

    public function countCashflow($filters = [])
    {
        // Apply filters
        if (!empty($filters['status'])) {
            $this->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $this->where('type', $filters['type']);
        }
        if (!empty($filters['id_toko'])) {
            $this->where('id_toko', $filters['id_toko']);
        }
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $this->where('date_time >=', $filters['start_date']);
            $this->where('date_time <=', $filters['end_date']);
        }

        // Return the count of matching entries
        return $this->countAllResults();
    }
}
