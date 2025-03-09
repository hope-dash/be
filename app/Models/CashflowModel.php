<?php

namespace App\Models;

use CodeIgniter\Model;

class CashflowModel extends Model
{
    protected $table = 'cashflow';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $allowedFields = ['debit', 'credit', 'noted', 'type', 'status', 'metode', 'date_time', 'id_toko'];

    // Menggunakan timestamps otomatis jika diperlukan
    protected $useTimestamps = false;
}