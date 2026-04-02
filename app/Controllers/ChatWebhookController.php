<?php

namespace App\Controllers;

use App\Libraries\TenantContext;
use App\Models\WhatsAppChatModel;
use App\Models\WhatsAppMessageModel;
use App\Models\ChatSessionModel;

/**
 * ChatWebhookController
 * 
 * Handles incoming webhook messages from external WhatsApp service
 * Stores messages, updates chat status, and triggers SSE events
 */
class ChatWebhookController extends BaseController
{
    private WhatsAppChatModel $chatModel;
    private WhatsAppMessageModel $messageModel;
    private ChatSessionModel $sessionModel;
    private const SSE_FILE_DIR = WRITEPATH . 'sse-messages/';

    public function __construct()
    {
        $this->chatModel = new WhatsAppChatModel();
        $this->messageModel = new WhatsAppMessageModel();
        $this->sessionModel = new ChatSessionModel();

        // Ensure SSE directory exists
        if (!is_dir(self::SSE_FILE_DIR)) {
            @mkdir(self::SSE_FILE_DIR, 0775, true);
        }
    }

    /**
     * Webhook endpoint for incoming messages
     * 
     * POST /api/chat/webhook/:tokoId
     * 
     * Handles:
     * - Incoming text messages
     * - Incoming images
     * - Message status updates
     * - Session status changes
     * 
     * @param int $tokoId Store ID
     * @return ResponseInterface
     */
    public function incoming($tokoId)
    {
        try {
            $payload = $this->request->getJSON(true) ?? $this->request->getRawInput();

            log_message('info', 'Chat webhook received for toko {toko_id}: {payload}', [
                'toko_id' => $tokoId,
                'payload' => json_encode($payload),
            ]);

            // Validate store exists
            $toko = $this->sessionModel->find($tokoId);
            if (!$toko) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Store not found',
                ]);
            }

            // Set tenant context
            TenantContext::set(1); // Adjust based on your multi-tenancy logic

            // Handle different message types
            $messageType = $payload['type'] ?? 'message';

            switch ($messageType) {
                case 'message':
                    $this->handleIncomingMessage($tokoId, $payload);
                    break;
                case 'status':
                    $this->handleStatusUpdate($tokoId, $payload);
                    break;
                case 'session_status':
                    $this->handleSessionStatusChange($tokoId, $payload);
                    break;
            }

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Webhook processed',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Webhook processing failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Processing failed',
            ]);
        }
    }

    /**
     * Handle incoming message
     * 
     * @param int $tokoId Store ID
     * @param array $payload Webhook payload
     */
    private function handleIncomingMessage(int $tokoId, array $payload): void
    {
        // Extract message data
        $from = $payload['from'] ?? $payload['sender'] ?? '';
        $text = $payload['text'] ?? $payload['message'] ?? '';
        $imageUrl = $payload['image_url'] ?? $payload['mediaUrl'] ?? null;
        $mediaMime = $payload['media_mime'] ?? $payload['mediaType'] ?? null;
        $messageId = $payload['messageId'] ?? $payload['id'] ?? null;
        $timestamp = $payload['timestamp'] ?? time();
        $senderName = $payload['sender_name'] ?? $payload['displayName'] ?? null;

        // Ignore group messages (@g.us)
        if (strpos($from, '@g.us') !== false) {
            log_message('info', 'Group message ignored from {from}', ['from' => $from]);
            return;
        }

        // Normalize phone number
        $phone = preg_replace('/@[a-z.us]+$/', '', $from);

        // Find or create chat
        $chat = $this->chatModel
            ->where('phone', $phone)
            ->where('tenant_id', TenantContext::id())
            ->first();

        if (!$chat) {
            $chatId = $this->chatModel->insert([
                'tenant_id' => TenantContext::id(),
                'phone' => $phone,
                'display_name' => $senderName,
                'last_message_at' => date('Y-m-d H:i:s', $timestamp),
                'last_message_snippet' => $text ? mb_substr($text, 0, 120) : '[image]',
                'unread_count' => 1,
            ], true);
            $chat = $this->chatModel->find($chatId);
        } else {
            $this->chatModel->update($chat['id'], [
                'display_name' => $chat['display_name'] ?: $senderName,
                'last_message_at' => date('Y-m-d H:i:s', $timestamp),
                'last_message_snippet' => $text ? mb_substr($text, 0, 120) : '[image]',
                'unread_count' => ($chat['unread_count'] ?? 0) + 1,
            ]);
            $chat = $this->chatModel->find($chat['id']);
        }

        // Handle media
        $mediaPath = null;
        if ($imageUrl) {
            $mediaPath = $this->storeWebpImage($imageUrl);
        }

        // Store message
        $messageData = [
            'tenant_id' => TenantContext::id(),
            'chat_id' => $chat['id'],
            'direction' => 'in',
            'message_type' => $imageUrl ? 'image' : 'text',
            'text' => $text,
            'media_path' => $mediaPath,
            'media_mime' => $mediaMime,
            'received_at' => date('Y-m-d H:i:s', $timestamp),
        ];

        $messageSaved = $this->messageModel->insert($messageData, true);

        // Broadcast via SSE
        $this->broadcastSSEEvent($tokoId, $chat['id'], [
            'type' => 'new_message',
            'chat_id' => $chat['id'],
            'message_id' => $messageSaved,
            'from' => $from,
            'text' => $text,
            'image_url' => $mediaPath,
            'timestamp' => $timestamp,
            'unread_count' => $chat['unread_count'] + 1,
        ]);

        log_message('info', 'Message stored: chat_id={chat_id}, message_id={msg_id}', [
            'chat_id' => $chat['id'],
            'msg_id' => $messageSaved,
        ]);
    }

    /**
     * Handle message status update (delivery, read, etc.)
     * 
     * @param int $tokoId Store ID
     * @param array $payload Webhook payload
     */
    private function handleStatusUpdate(int $tokoId, array $payload): void
    {
        $messageId = $payload['messageId'] ?? null;
        $status = $payload['status'] ?? null; // delivered, read, failed, etc.

        if (!$messageId || !$status) {
            return;
        }

        log_message('info', 'Message status update: {msg_id} -> {status}', [
            'msg_id' => $messageId,
            'status' => $status,
        ]);

        // Could store status in database if needed
        // For now, just broadcast to SSE
        $this->broadcastSSEEvent($tokoId, null, [
            'type' => 'message_status',
            'message_id' => $messageId,
            'status' => $status,
        ]);
    }

    /**
     * Handle session status changes (ready, disconnected, error, etc.)
     * 
     * @param int $tokoId Store ID
     * @param array $payload Webhook payload
     */
    private function handleSessionStatusChange(int $tokoId, array $payload): void
    {
        $status = $payload['status'] ?? null;
        $sessionId = $payload['sessionId'] ?? null;

        if (!$status) {
            return;
        }

        // Update session status
        $this->sessionModel->updateSessionStatus($tokoId, $status, $sessionId);

        log_message('info', 'Session status changed: toko_id={toko_id}, status={status}', [
            'toko_id' => $tokoId,
            'status' => $status,
        ]);

        // Broadcast to SSE
        $this->broadcastSSEEvent($tokoId, null, [
            'type' => 'session_status',
            'status' => $status,
            'sessionId' => $sessionId,
        ]);
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
            // Create separate queue files for different subscribers
            $sseFile = self::SSE_FILE_DIR . "toko_{$tokoId}.queue";

            // Append event to queue
            $event = json_encode($eventData) . "\n";
            @file_put_contents($sseFile, $event, FILE_APPEND);

            log_message('debug', 'SSE event queued for toko {toko_id}', ['toko_id' => $tokoId]);
        } catch (\Throwable $e) {
            log_message('error', 'Failed to broadcast SSE event: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Store incoming image as WebP
     * 
     * @param string $url Image URL
     * @return string|null Path to stored image
     */
    private function storeWebpImage(string $url): ?string
    {
        try {
            $contents = @file_get_contents($url);
            if ($contents === false) {
                return null;
            }

            $img = @imagecreatefromstring($contents);
            if (!$img) {
                return null;
            }

            $dir = WRITEPATH . 'uploads/wa';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $filename = uniqid('wa_', true) . '.webp';
            $fullPath = $dir . '/' . $filename;
            imagewebp($img, $fullPath, 75);
            imagedestroy($img);

            return 'uploads/wa/' . $filename;
        } catch (\Throwable $e) {
            log_message('error', 'Failed to store webp image: {msg}', ['msg' => $e->getMessage()]);
            return null;
        }
    }
}
