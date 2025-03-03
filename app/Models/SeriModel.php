<?php

namespace App\Models;

use CodeIgniter\Model;

class SeriModel extends Model
{
    protected $table = 'seri';
    protected $primaryKey = 'id';
    protected $allowedFields = ['seri'];

    protected $useSoftDeletes = true;
    protected $deletedField = 'deleted_at';
}
