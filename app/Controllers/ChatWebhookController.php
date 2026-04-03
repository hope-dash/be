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
            if (!is_numeric($tokoId)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'Invalid toko_id',
                ]);
            }

            $toko = $this->sessionModel->find($tokoId);
            if (!$toko) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Store not found',
                ]);
            }

            // Set tenant context from store
            $tenantId = $toko['tenant_id'] ?? 1;
            TenantContext::set(['id' => $tenantId]);

            // Handle different event types from external service
            $eventType = $payload['event'] ?? null;

            if (!$eventType) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'Missing event field',
                ]);
            }

            switch ($eventType) {
                case 'message_in':
                case 'message_out':
                    $this->handleMessageEvent($tokoId, $payload, $eventType);
                    break;
                case 'message_ack':
                    $this->handleAckEvent($tokoId, $payload);
                    break;
                case 'session_status':
                    $this->handleSessionStatusChange($tokoId, $payload);
                    break;
                default:
                    log_message('warning', 'Unknown webhook event type: {event}', ['event' => $eventType]);
            }

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Webhook processed',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Webhook processing failed: {msg} - {trace}', [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Processing failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle message event (incoming or outgoing)
     * 
     * @param int $tokoId Store ID
     * @param array $payload Webhook payload
     * @param string $eventType "message_in" or "message_out"
     */
    private function handleMessageEvent(int $tokoId, array $payload, string $eventType): void
    {
        // Extract message data
        $from = $payload['from'] ?? null;
        $to = $payload['to'] ?? null;
        $text = $payload['text'] ?? '';
        $imageUrl = $payload['mediaUrl'] ?? $payload['image_url'] ?? null;
        $mediaMime = $payload['mediaType'] ?? $payload['media_mime'] ?? null;
        $messageId = $payload['messageId'] ?? $payload['id'] ?? null;
        $timestamp = $payload['timestamp'] ?? time();
        $senderName = $payload['sender_name'] ?? $payload['displayName'] ?? null;
        $isReply = $payload['isReply'] ?? false;
        $quoted = $payload['quoted'] ?? null;

        $direction = $eventType === 'message_in' ? 'in' : 'out';
        
        // IMPORTANT: Get the customer phone number based on direction
        // message_in: customer is in the 'from' field
        // message_out: customer is in the 'to' field
        if ($direction === 'in') {
            if (empty($from)) {
                log_message('warning', 'message_in: missing from field');
                return;
            }
            $customerPhone = $from;
        } else {
            if (empty($to)) {
                log_message('warning', 'message_out: missing to field');
                return;
            }
            $customerPhone = $to;
        }
        
        // Normalize phone number: remove @c.us, @g.us, @lid and other suffixes
        $customerPhone = trim(preg_replace('/@[a-z0-9.@]+$/', '', $customerPhone));
        
        if (empty($customerPhone)) {
            log_message('warning', 'Empty customer phone after normalization for event {event}', ['event' => $eventType]);
            return;
        }

        // Ignore group messages (only for incoming)
        if ($direction === 'in' && (strpos($from, '@g.us') !== false)) {
            log_message('info', 'Group message ignored from {from}', ['from' => $from]);
            return;
        }

        log_message('info', 'Processing {event}: customer_phone={phone}, direction={dir}', [
            'event' => $eventType,
            'phone' => $customerPhone,
            'dir' => $direction,
        ]);

        // Find or create chat with the customer
        $tenantId = TenantContext::id();
        $chat = $this->chatModel
            ->where('phone', $customerPhone)
            ->where('tenant_id', $tenantId)
            ->first();

        log_message('debug', 'Chat lookup: phone={phone}, tenant={tenant}, found={found}', [
            'phone' => $customerPhone,
            'tenant' => $tenantId,
            'found' => $chat ? 'yes (id=' . $chat['id'] . ')' : 'no (will create)',
        ]);

        if (!$chat) {
            $chatId = $this->chatModel->insert([
                'tenant_id' => $tenantId,
                'phone' => $customerPhone,
                'display_name' => $senderName,
                'last_message_at' => date('Y-m-d H:i:s', $timestamp),
                'last_message_snippet' => $text ? mb_substr($text, 0, 120) : ($imageUrl ? '[image]' : '[media]'),
                'unread_count' => $direction === 'in' ? 1 : 0,
            ], true);
            
            if ($chatId === false) {
                log_message('error', 'Failed to create chat: {error}', ['error' => $this->chatModel->errors() ?? 'Unknown']);
                return;
            }
            
            $chat = $this->chatModel->find($chatId);
            if (!$chat) {
                log_message('error', 'Created chat id {id} but could not retrieve it', ['id' => $chatId]);
                return;
            }
            
            log_message('info', 'Chat created: id={id}, phone={phone}', [
                'id' => $chat['id'],
                'phone' => $customerPhone,
            ]);
        } else {
            // Update chat - increment unread only for incoming messages
            $unreadInc = ($direction === 'in') ? 1 : 0;
            $updateData = [
                'last_message_at' => date('Y-m-d H:i:s', $timestamp),
                'last_message_snippet' => $text ? mb_substr($text, 0, 120) : ($imageUrl ? '[image]' : '[media]'),
                'unread_count' => ($chat['unread_count'] ?? 0) + $unreadInc,
            ];
            
            // Only update display_name if not already set
            if (empty($chat['display_name']) && $senderName) {
                $updateData['display_name'] = $senderName;
            }
            
            $this->chatModel->update($chat['id'], $updateData);
            $chat = $this->chatModel->find($chat['id']);
            
            log_message('info', 'Chat updated: id={id}, direction={dir}, unread_inc={inc}', [
                'id' => $chat['id'],
                'dir' => $direction,
                'inc' => $unreadInc,
            ]);
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
            'direction' => $direction,
            'message_type' => $imageUrl ? 'image' : 'text',
            'text' => $text,
            'media_path' => $mediaPath,
            'media_mime' => $mediaMime,
            'external_message_id' => $messageId,
            'received_at' => date('Y-m-d H:i:s', $timestamp),
        ];

        // Add reply info if available
        if ($isReply && $quoted) {
            $messageData['quoted_message_id'] = $quoted['messageId'] ?? null;
            $messageData['quoted_text'] = $quoted['text'] ?? null;
        }

        $messageSaved = $this->messageModel->insert($messageData, true);

        // Broadcast via SSE
        $this->broadcastSSEEvent($tokoId, $chat['id'], [
            'type' => 'new_message',
            'chat_id' => $chat['id'],
            'message_id' => $messageSaved,
            'external_message_id' => $messageId,
            'from' => $from,
            'to' => $to,
            'text' => $text,
            'image_url' => $mediaPath,
            'direction' => $direction,
            'timestamp' => $timestamp,
            'is_reply' => $isReply,
        ]);

        log_message('info', 'Message stored: chat_id={chat_id}, msg_id={msg_id}, direction={dir}', [
            'chat_id' => $chat['id'],
            'msg_id' => $messageSaved,
            'dir' => $direction,
        ]);
    }

    /**
     * Handle message acknowledgment
     * 
     * @param int $tokoId Store ID
     * @param array $payload Webhook payload
     */
    private function handleAckEvent(int $tokoId, array $payload): void
    {
        $messageId = $payload['messageId'] ?? null;
        $ack = $payload['ack'] ?? null; // 1=sent, 2=delivered, 3=read
        $status = $payload['status'] ?? null; // "sent", "delivered", "failed", etc.

        if (!$messageId) {
            return;
        }

        // Get actual status value for consistency
        $statusMap = [
            1 => 'sent',
            2 => 'delivered',
            3 => 'read',
        ];

        $finalStatus = $status ?? ($statusMap[$ack] ?? 'unknown');

        log_message('info', 'Message ack: {msg_id} -> {status}', [
            'msg_id' => $messageId,
            'status' => $finalStatus,
        ]);

        // Broadcast to SSE (all subscribers)
        $this->broadcastSSEEvent($tokoId, null, [
            'type' => 'message_ack',
            'message_id' => $messageId,
            'status' => $finalStatus,
            'ack' => $ack,
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
