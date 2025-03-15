<?php

namespace App\Models;

use CodeIgniter\Model;

class SuplierModel extends Model
{
    protected $table = 'suplier';
    protected $primaryKey = 'id';

    protected $allowedFields = ['suplier_name', 'suplier_number', 'notes', "created_by", "updated_by"];
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

}
