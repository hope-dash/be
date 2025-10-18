<?php

namespace App\Models;

use CodeIgniter\Model;

class LogAktivitasModel extends Model
{
    protected $table = 'log_aktivitas';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'user_id',
        'action_type',
        'target_table',
        'target_id',
        'description',
        'detail',
        'created_at'
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
