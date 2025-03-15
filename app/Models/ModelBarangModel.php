<?php

namespace App\Models;

use CodeIgniter\Model;

class ModelBarangModel extends Model
{
    protected $table = 'model_barang';
    protected $primaryKey = 'id';
    protected $allowedFields = ['kode_awal', 'nama_model', "created_by", "updated_by"];

    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

}
