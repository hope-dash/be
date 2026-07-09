<?php

namespace App\Models;

use CodeIgniter\Model;

class TiktokMessageModel extends Model
{
    protected $table            = 'tiktok_messages';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tiktok_chat_id',
        'message_id',
        'sender_role',
        'type',
        'content',
        'is_read',
        'create_time',
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
