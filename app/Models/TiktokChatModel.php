<?php

namespace App\Models;

use CodeIgniter\Model;

class TiktokChatModel extends Model
{
    protected $table            = 'tiktok_chats';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id_toko',
        'shop_id',
        'conversation_id',
        'participant_id',
        'participant_name',
        'participant_avatar',
        'unread_count',
        'last_message',
        'last_message_time',
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
