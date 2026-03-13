<?php

namespace App\Models;

class ClosingDetailModel extends TenantScopedModel
{
    protected $table = 'closing_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'tenant_id',
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
