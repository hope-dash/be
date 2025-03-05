<?php

namespace App\Models;

use CodeIgniter\Model;

class SuplierModel extends Model
{
    protected $table = 'suplier';
    protected $primaryKey = 'id';

    protected $allowedFields = ['suplier_name', 'suplier_number', 'notes'];
    protected $useSoftDeletes = true;
    protected $deletedField = 'deleted_at';

}
