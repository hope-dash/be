<?php

namespace App\Controllers;

use App\Libraries\ChatServiceAPI;
use App\Models\ChatSessionModel;
use App\Libraries\TenantContext;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ChatSessionController
 * 
 * Handles WhatsApp chat session management
 * - Start/stop sessions
 * - Check session status
 * - Send messages
 * - Get QR codes
 */
class ChatSessionController extends BaseController
{
    private ChatSessionModel $sessionModel;
    private ChatServiceAPI $chatService;

    public function __construct()
    {
        $this->sessionModel = new ChatSessionModel();
        $this->chatService = new ChatServiceAPI();
    }

    /**
     * Start a new chat session
     * 
     * POST /api/chat/session/start
     * 
     * @return ResponseInterface
     */
    public function start()
    {
        try {
            $tokoId = (int)($this->request->getPost('toko_id') ?? 0);
            if (!$tokoId) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'toko_id is required',
                ]);
            }

            $toko = $this->sessionModel->find($tokoId);
            if (!$toko) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Store not found',
                ]);
            }

            // Generate session ID from store name + unique identifier
            $sessionId = $toko['toko_name'] . '_' . uniqid() . '_' . $tokoId;
            $sessionId = strtolower(preg_replace('/\s+/', '_', $sessionId));

            // Build webhook URL
            $webhookUrl = site_url('api/chat/webhook/' . $tokoId);

            // Call external service to start session
            $result = $this->chatService->startSession($sessionId, $webhookUrl);

            // Save session ID to database
            $this->sessionModel->updateSessionStatus($tokoId, 'qr_ready', $result['sessionId']);

            log_message('info', 'Chat session started for toko {toko_id}: {session_id}', [
                'toko_id' => $tokoId,
                'session_id' => $result['sessionId'],
            ]);

            return $this->response->setJSON([
                'success' => true,
                'data' => [
                    'toko_id' => $tokoId,
                    'sessionId' => $result['sessionId'],
                    'status' => 'qr_ready',
                    'qr' => $result['qr'] ?? null,
                ],
            ]);
        }
        catch (\Throwable $e) {
            log_message('error', 'Failed to start chat session: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Failed to start session: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get session status
     * 
     * GET /api/chat/session/status/:tokoId
     * 
     * @param int $tokoId Store ID
     * @return ResponseInterface
     */
    public function status($tokoId)
    {
        try {
            $toko = $this->sessionModel->find($tokoId);
            if (!$toko) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Store not found',
                ]);
            }

            if (!$toko['chat_session_id']) {
                return $this->response->setJSON([
                    'success' => true,
                    'data' => [
                        'toko_id' => $tokoId,
                        'status' => 'disconnected',
                        'sessionId' => null,
                    ],
                ]);
            }

            // Get status from external service
            $statusData = $this->chatService->getSessionStatus($toko['chat_session_id']);

            // Update local status if changed
            if ($statusData['status'] !== $toko['chat_session_status']) {
                $this->sessionModel->updateSessionStatus($tokoId, $statusData['status']);
            }

            return $this->response->setJSON([
                'success' => true,
                'data' => [
                    'toko_id' => $tokoId,
                    'sessionId' => $toko['chat_session_id'],
                    'status' => $statusData['status'],
                    'webhookUrl' => $statusData['webhookUrl'] ?? site_url('api/chat/webhook/' . $tokoId),
                ],
            ]);
        }
        catch (\Throwable $e) {
            log_message('error', 'Failed to get session status: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Failed to get session status: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get QR code
     * 
     * GET /api/chat/session/qr/:tokoId
     * 
     * @param int $tokoId Store ID
     * @return ResponseInterface
     */
    public function getQr($tokoId)
    {
        try {
            $toko = $this->sessionModel->find($tokoId);
            if (!$toko || !$toko['chat_session_id']) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Session not found',
                ]);
            }

            $qrData = $this->chatService->getQrCode($toko['chat_session_id']);

            return $this->response->setJSON([
                'success' => true,
                'data' => [
                    'toko_id' => $tokoId,
                    'sessionId' => $toko['chat_session_id'],
                    'qr' => $qrData['qr'] ?? null,
                    'status' => $qrData['status'] ?? $toko['chat_session_status'],
                ],
            ]);
        }
        catch (\Throwable $e) {
            log_message('error', 'Failed to get QR code: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Failed to get QR code: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark chat as read
     * 
     * POST /api/chat/session/read
     * 
     * @return ResponseInterface
     */
    public function readChat()
    {
        try {
            $json = $this->request->getJSON(true);
            $sessionId = $json['sessionId'] ?? '';
            $chatIdInput = $json['chatId'] ?? '';

            if (!$sessionId || !$chatIdInput) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'sessionId and chatId are required',
                ]);
            }

            // Find current store to get tokoId (for SSE and database)
            $toko = $this->sessionModel->where('chat_session_id', $sessionId)->first();
            if (!$toko) {
                 return $this->response->setStatusCode(404)->setJSON(['success'=>false, 'message'=>'Session not found']);
            }
            $tokoId = $toko['id'];

            // Format chatId (JID)
            $chatJid = ChatServiceAPI::formatPhoneNumber($chatIdInput);
            $cleanPhone = preg_replace('/@[a-z0-9.@]+$/', '', $chatJid);

            // 1. Call external service
            $this->chatService->markChatAsRead($sessionId, $chatJid);

            // 2. Update database unread count
            $chatModel = new \App\Models\WhatsAppChatModel();
            $chatModel->set(['unread_count' => 0])
                ->groupStart()
                    ->where('jid', $cleanPhone)
                    ->orWhere('phone', $cleanPhone)
                ->groupEnd()
                ->where('tenant_id', TenantContext::id())
                ->update();

            log_message('info', 'Chat marked as read: {phone} in session {session}', [
                'phone' => $cleanPhone,
                'session' => $sessionId,
            ]);

            // 3. Broadcast SSE
            $this->broadcastSSEEvent($tokoId, null, [
                'type' => 'chat_read',
                'chat_id' => $cleanPhone,
            ]);

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Chat marked as read successfully',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Failed to read chat: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Failed to read chat: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Send message (Text, Image URL, or Image Base64)
     * 
     * POST /api/chat/send
     * 
     * Body JSON:
     * {
     *   "sessionId": "akun1",
     *   "to": "6281234567890@c.us",
     *   "text": "Halo!",
     *   "imageUrl": "...",
     *   "imageBase64": "...",
     *   "caption": "..."
     * }
     * 
     * @return ResponseInterface
     */
    public function send()
    {
        try {
            $json = $this->request->getJSON(true);
            $sessionId = $json['sessionId'] ?? $this->request->getPost('sessionId') ?? '';
            $to = $json['to'] ?? $this->request->getPost('to') ?? '';
            
            $text = $json['text'] ?? $this->request->getPost('text') ?? null;
            $imageUrl = $json['imageUrl'] ?? $this->request->getPost('imageUrl') ?? null;
            $imageBase64 = $json['imageBase64'] ?? $this->request->getPost('imageBase64') ?? null;
            $caption = $json['caption'] ?? $this->request->getPost('caption') ?? null;
            
            $delayMs = (int)($json['delayMs'] ?? $this->request->getPost('delayMs') ?? 0);
            $typingDurationMs = (int)($json['typingDurationMs'] ?? $this->request->getPost('typingDurationMs') ?? 2000);

            if (!$sessionId) {
                return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'sessionId is required']);
            }

            if (!$to) {
                return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'to (phone number) is required']);
            }

            // Format phone number to WhatsApp format
            $toHost = ChatServiceAPI::formatPhoneNumber($to);

            // Send message based on type
            if ($imageUrl) {
                $result = $this->chatService->sendImageMessage($sessionId, $toHost, $imageUrl, $caption, $delayMs);
            }
            elseif ($imageBase64) {
                $result = $this->chatService->sendImageBase64Message($sessionId, $toHost, $imageBase64, $caption, $delayMs);
            }
            elseif ($text) {
                $result = $this->chatService->sendTextMessage($sessionId, $toHost, $text, $delayMs, $typingDurationMs);
            }
            else {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'text, imageUrl, or imageBase64 is required',
                ]);
            }

            log_message('info', 'Message sent via session {session} to {to}', [
                'session' => $sessionId,
                'to' => $toHost,
            ]);

            return $this->response->setJSON([
                'success' => true,
                'data' => [
                    'sessionId' => $sessionId,
                    'to' => $toHost,
                    'messageId' => $result['messageId'] ?? ($result['data']['id'] ?? null),
                    'status' => 'sent',
                ],
            ]);
        }
        catch (\Throwable $e) {
            log_message('error', 'Failed to send message: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Store outgoing message to database
     * 
     * @param int $tokoId Store ID
     * @param string $to Recipient
     * @param string|null $text Message text
     * @param string|null $imageUrl Image URL
     * @param string|null $caption Caption
     */
    private function storeOutgoingMessage(
        int $tokoId,
        string $to,
        ?string $text = null,
        ?string $imageUrl = null,
        ?string $caption = null
        ): void
    {
        try {
            $messageModel = new \App\Models\WhatsAppMessageModel();

            // Extract phone number without suffix
            $phone = preg_replace('/@[a-z.us]+$/', '', $to);

            // Find or create chat
            $chatModel = new \App\Models\WhatsAppChatModel();
            $chat = $chatModel
                ->where('phone', $phone)
                ->where('tenant_id', TenantContext::id())
                ->first();

            if (!$chat) {
                $chatId = $chatModel->insert([
                    'tenant_id' => TenantContext::id(),
                    'phone' => $phone,
                    'display_name' => null,
                    'last_message_at' => date('Y-m-d H:i:s'),
                    'last_message_snippet' => $text ? mb_substr($text, 0, 120) : '[image]',
                    'unread_count' => 0,
                ], true);
                $chat = $chatModel->find($chatId);
            }
            else {
                $chatModel->update($chat['id'], [
                    'last_message_at' => date('Y-m-d H:i:s'),
                    'last_message_snippet' => $text ? mb_substr($text, 0, 120) : '[image]',
                ]);
            }

            $messageType = $imageUrl ? 'image' : 'text';

            $messageModel->insert([
                'tenant_id' => TenantContext::id(),
                'chat_id' => $chat['id'],
                'direction' => 'out',
                'message_type' => $messageType,
                'text' => $text,
                'media_path' => $imageUrl,
                'media_mime' => $imageUrl ? 'image/jpeg' : null,
                'received_at' => date('Y-m-d H:i:s'),
            ]);
        }
        catch (\Throwable $e) {
            log_message('error', 'Failed to store outgoing message: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Disconnect session
     * 
     * POST /api/chat/session/disconnect/:tokoId
     * 
     * @param int $tokoId Store ID
     * @return ResponseInterface
     */
    public function disconnect($tokoId)
    {
        try {
            $toko = $this->sessionModel->find($tokoId);
            if (!$toko || !$toko['chat_session_id']) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Session not found',
                ]);
            }

            // Call external service
            $this->chatService->disconnectSession($toko['chat_session_id']);

            // Update status
            $this->sessionModel->updateSessionStatus($tokoId, 'disconnected', null);

            log_message('info', 'Chat session disconnected for toko {toko_id}', ['toko_id' => $tokoId]);

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Session disconnected successfully',
            ]);
        }
        catch (\Throwable $e) {
            log_message('error', 'Failed to disconnect session: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Failed to disconnect session: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast SSE event to clients
     * 
     * @param int $tokoId Store ID
     * @param int|null $chatId Chat ID (optional, for chat-specific events)
     * @param array $eventData Event data to broadcast
     */
    private function broadcastSSEEvent(int $tokoId, ?int $chatId = null, array $eventData = []): void
    {
        try {
            $sseDir = WRITEPATH . 'sse-messages/';
            if (!is_dir($sseDir)) {
                @mkdir($sseDir, 0775, true);
            }

            $sseFile = $sseDir . "toko_{$tokoId}.queue";

            // Append event to queue
            $event = json_encode($eventData) . "\n";
            @file_put_contents($sseFile, $event, FILE_APPEND);

            log_message('debug', 'SSE event queued for toko {toko_id} from ChatSessionController', ['toko_id' => $tokoId]);
        } catch (\Throwable $e) {
            log_message('error', 'Failed to broadcast SSE event: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}