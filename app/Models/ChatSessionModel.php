<?php

namespace App\Models;

use CodeIgniter\Model;

class ChatSessionModel extends Model
{
    protected $table = 'toko';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $protectFields = true;
    protected $allowedFields = [
        'chat_session_id',
        'chat_session_status',
    ];
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Get chat session by session ID
     * 
     * @param string $sessionId
     * @return array|null
     */
    public function getBySessionId(string $sessionId): ?array
    {
        return $this->where('chat_session_id', $sessionId)->first();
    }

    /**
     * Update session status
     * 
     * @param int $tokoId Store ID
     * @param string $status Session status
     * @param string|null $sessionId Optional session ID to update
     * @return bool
     */
    public function updateSessionStatus(int $tokoId, string $status, ?string $sessionId = null): bool
    {
        $data = ['chat_session_status' => $status];
        if ($sessionId) {
            $data['chat_session_id'] = $sessionId;
        }
        return $this->update($tokoId, $data);
    }
}
