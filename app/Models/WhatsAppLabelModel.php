<?php

namespace App\Models;

use CodeIgniter\Model;

class WhatsAppLabelModel extends Model
{
    protected $table = 'whatsapp_labels';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $protectFields = true;
    protected $allowedFields = [
        'tenant_id',
        'name',
        'color',
    ];
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
