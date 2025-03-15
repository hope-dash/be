<?php

namespace App\Models;

use CodeIgniter\Model;

class SeriModel extends Model
{
    protected $table = 'seri';
    protected $primaryKey = 'id';
    protected $allowedFields = ['seri', "created_by", "updated_by"];

    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
}
