<?php

namespace App\Models;

class SuplierModel extends TenantScopedModel
{
    protected $table = 'suplier';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'suplier_name', 'suplier_number', 'notes', "created_by", "updated_by"];
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

}
