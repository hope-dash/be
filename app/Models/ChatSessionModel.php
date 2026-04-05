<?php

namespace App\Models;

use CodeIgniter\Model;

class ChatSessionModel extends Model
{
    protected $table = 'toko'; // Keep reference to shop ID
    protected $primaryKey = 'id';
    
    // This model will now primarily act as a wrapper around TokoMeta for WhatsApp settings
    private TokoMetaModel $metaModel;

    public function __construct()
    {
        parent::__construct();
        $this->metaModel = new TokoMetaModel();
    }

    /**
     * Get chat session by session ID
     * 
     * @param string $sessionId
     * @return array|null
     */
    public function getBySessionId(string $sessionId): ?array
    {
        $meta = $this->metaModel->where([
            'meta_key' => 'chat_session_id',
            'meta_value' => $sessionId
        ])->first();

        if (!$meta) return null;

        return $this->find($meta['id_toko']);
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
        $this->metaModel->setMeta($tokoId, 'chat_session_status', $status);
        if ($sessionId) {
            $this->metaModel->setMeta($tokoId, 'chat_session_id', $sessionId);
        }
        return true;
    }

    /**
     * Get session status from meta
     */
    public function getSessionStatus(int $tokoId): ?string
    {
        return $this->metaModel->getMeta($tokoId, 'chat_session_status');
    }

    /**
     * Get session ID from meta
     */
    public function getSessionId(int $tokoId): ?string
    {
        return $this->metaModel->getMeta($tokoId, 'chat_session_id');
    }
}
