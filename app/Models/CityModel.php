<?php

namespace App\Models;

use CodeIgniter\Model;

class CityModel extends Model
{
    protected $table = 'kota_kabupaten';
    protected $primaryKey = 'id';
    protected $allowedFields = ['code', 'province_code', 'name'];
}
