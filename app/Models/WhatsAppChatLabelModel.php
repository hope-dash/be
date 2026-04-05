<?php

namespace App\Models;

use CodeIgniter\Model;

class WhatsAppChatLabelModel extends Model
{
    protected $table = 'whatsapp_chat_labels';
    protected $primaryKey = null;
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;
    protected $allowedFields = [
        'tenant_id',
        'chat_id',
        'label_id',
    ];
    public $timestamps = false;
}
