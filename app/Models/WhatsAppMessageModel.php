<?php

namespace App\Models;

use CodeIgniter\Model;

class WhatsAppMessageModel extends Model
{
    protected $table = 'whatsapp_messages';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $protectFields = true;
    protected $allowedFields = [
        'tenant_id',
        'chat_id',
        'direction',
        'message_type',
        'text',
        'media_path',
        'media_mime',
        'external_message_id',
        'quoted_message_id',
        'quoted_text',
        'received_at',
    ];
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
