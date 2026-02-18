<?php

namespace App\Models;

use CodeIgniter\Model;

class VillageModel extends Model
{
    protected $table = 'kelurahan';
    protected $primaryKey = 'id';
    protected $allowedFields = ['code', 'name', 'district_code', 'regency_code', 'province_code'];
}
