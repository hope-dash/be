<?php

namespace App\Models;

class LogAktivitasModel extends TenantScopedModel
{
    protected $table = 'log_aktivitas';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'tenant_id',
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
