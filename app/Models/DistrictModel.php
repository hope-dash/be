<?php

namespace App\Models;

use CodeIgniter\Model;

class DistrictModel extends Model
{
    protected $table = 'kecamatan';
    protected $primaryKey = 'id';
    protected $allowedFields = ['code', 'name', 'regency_code', 'province_code'];
}
