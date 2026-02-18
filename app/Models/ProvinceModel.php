<?php

namespace App\Models;

use CodeIgniter\Model;

class ProvinceModel extends Model
{
    protected $table = 'provincy';
    protected $primaryKey = 'id';
    protected $allowedFields = ['code', 'name'];
}
