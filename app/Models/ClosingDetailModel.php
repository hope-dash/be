<?php

namespace App\Models;

use CodeIgniter\Model;

class ClosingDetailModel extends Model
{
    protected $table = 'closing_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_closing_id',
        'keterangan',
        'tipe',
        'tanggal',
        'debit',
        'credit',
        'urutan',
        'id_cashflow'
    ];
    protected $useTimestamps = false;

    // Ambil semua detail by closing ID
    public function getDetailsByClosingId(int $closingId)
    {
        return $this->where('transaction_closing_id', $closingId)
            ->orderBy('urutan', 'ASC')
            ->findAll();
    }

}
