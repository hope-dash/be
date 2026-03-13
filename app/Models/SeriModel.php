<?php

namespace App\Models;

class SeriModel extends TenantScopedModel
{
    protected $table = 'seri';
    protected $primaryKey = 'id';
    protected $allowedFields = ['tenant_id', 'seri', "created_by", "updated_by"];

    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
}
