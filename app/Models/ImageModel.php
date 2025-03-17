<?php

namespace App\Models;

use CodeIgniter\Model;

class ImageModel extends Model
{
    protected $table = 'image';
    protected $primaryKey = 'id';

    protected $allowedFields = ['type', 'kode', 'url'];
    protected $validationRules = [
        'kode' => 'required|alpha_numeric',
        'url' => 'required|valid_url',
    ];


}
