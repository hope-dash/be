<?php

namespace App\Models;

use CodeIgniter\Model;

class SeriModel extends Model
{
    protected $table = 'seri';
    protected $primaryKey = 'id';
    protected $allowedFields = ['seri'];
}
