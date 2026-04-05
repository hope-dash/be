<?php

namespace App\Models;

use CodeIgniter\Model;

class WhatsAppChatModel extends Model
{
    protected $table = 'whatsapp_chats';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $protectFields = true;
    protected $allowedFields = [
        'tenant_id',
        'id_toko',
        'phone',
        'jid',
        'session_id',
        'display_name',
        'last_message_at',
        'last_message_snippet',
        'unread_count',
    ];
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
