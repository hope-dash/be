<?php

namespace App\Models;

class CashflowModel extends TenantScopedModel
{
    protected $table = 'cashflow';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $allowedFields = ['tenant_id', 'debit', 'credit', 'noted', 'type', 'status', 'metode', 'date_time', 'id_toko', 'closing'];

    // Menggunakan timestamps otomatis jika diperlukan
    protected $useTimestamps = false;
}
