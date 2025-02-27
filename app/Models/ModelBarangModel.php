<?php

namespace App\Models;

use CodeIgniter\Model;

class ModelBarangModel extends Model
{
    protected $table = 'model_barang';
    protected $primaryKey = 'id';
    protected $allowedFields = ['kode_awal', 'nama_model'];
}
