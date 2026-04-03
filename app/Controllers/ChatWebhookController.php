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
        $rawFrom = $payload['rawFrom'] ?? null;
        $rawTo = $payload['rawTo'] ?? null;
        $fromMe = $payload['fromMe'] ?? $payload['from_me'] ?? ($eventType === 'message_out');
        $sessionId = $payload['sessionId'] ?? null;
        
        $text = $payload['text'] ?? '';
        $imageUrl = $payload['mediaUrl'] ?? $payload['image_url'] ?? null;
        $mediaMime = $payload['mediaType'] ?? $payload['media_mime'] ?? null;
        $messageId = $payload['messageId'] ?? $payload['id'] ?? null;
        $timestamp = $payload['timestamp'] ?? time();
        $senderName = $payload['sender_name'] ?? $payload['displayName'] ?? null;
        $isReply = $payload['isReply'] ?? false;
        $quoted = $payload['quoted'] ?? null;

        $direction = ($eventType === 'message_in') ? 'in' : 'out';
        
        // IMPORTANT: Identify the customer (either the sender or recipient)
        // If fromMe is true, the customer is the recipient (rawTo).
        // If fromMe is false, the customer is the sender (rawFrom).
        $customerJid = $fromMe ? $rawTo : $rawFrom;
        $fallbackPhone = $fromMe ? $to : $from;

        if (empty($customerJid) && empty($fallbackPhone)) {
            log_message('warning', "{$eventType}: missing both JID and phone identifiers");
            return;
        }

        // Normalize JID and phone
        $customerJid = $customerJid ? trim(preg_replace('/@[a-z0-9.@]+$/', '', $customerJid)) : null;
        $customerPhone = $fallbackPhone ? trim(preg_replace('/@[a-z0-9.@]+$/', '', $fallbackPhone)) : null;

        // Ignore group messages
        if (($rawFrom && strpos($rawFrom, '@g.us') !== false) || ($rawTo && strpos($rawTo, '@g.us') !== false)) {
            log_message('info', 'Group message ignored');
            return;
        }

        log_message('info', 'Processing {event}: customer_jid={jid}, direction={dir}', [
            'event' => $eventType,
            'jid' => $customerJid,
            'dir' => $direction,
        ]);

        // Find or create chat with the customer
        $tenantId = TenantContext::id();
        $chat = null;

        log_message('debug', "Chat lookup: tenant_id={$tenantId}, jid={$customerJid}, phone={$customerPhone}");

        // 1. Try finding by JID (the most stable ID)
        if ($customerJid) {
            $chat = $this->chatModel
                ->where('jid', $customerJid)
                ->where('tenant_id', $tenantId)
                ->first();
            if ($chat) log_message('debug', "Chat found by JID: id={$chat['id']}");
        }

        // 2. Fallback to phone if not found by JID (links existing records)
        if (!$chat && $customerPhone) {
            $chat = $this->chatModel
                ->where('phone', $customerPhone)
                ->where('tenant_id', $tenantId)
                ->first();
                
            // If found by phone, update its JID now to link subsequent messages
            if ($chat) {
                log_message('debug', "Chat found by Phone: id={$chat['id']}. Linking JID: {$customerJid}");
                if ($customerJid) {
                    $this->chatModel->update($chat['id'], ['jid' => $customerJid]);
                    $chat['jid'] = $customerJid;
                }
            }
        }

        if (!$chat) {
            $chatData = [
                'tenant_id' => $tenantId,
                'jid' => $customerJid,
                'phone' => $customerPhone ?? $customerJid, // Prefer phone for display, fallback to JID
                'session_id' => $sessionId,
                'display_name' => $senderName,
                'last_message_at' => date('Y-m-d H:i:s', $timestamp),
                'last_message_snippet' => $text ? mb_substr($text, 0, 120) : ($imageUrl ? '[image]' : '[media]'),
                'unread_count' => (!$fromMe) ? 1 : 0,
            ];
            
            $chatId = $this->chatModel->insert($chatData, true);
            
            if ($chatId === false || $chatId === 0) {
                $errors = $this->chatModel->errors() ?: 'Database error or validation failed';
                log_message('error', 'Failed to create chat. Errors: {error}. Data: {data}', [
                    'error' => is_array($errors) ? json_encode($errors) : $errors,
                    'data'  => json_encode($chatData)
                ]);
                return;
            }
            
            $chat = $this->chatModel->find($chatId);
            log_message('info', 'Chat created: id={id}, jid={jid}', ['id' => $chat['id'], 'jid' => $customerJid]);
        } else {
            // Unread logic: Reset to 0 for outgoing messages (we've replied), 
            // increment by 1 for incoming messages from the customer.
            $newUnreadCounter = $fromMe ? 0 : ($chat['unread_count'] ?? 0) + 1;

            $updateData = [
                'last_message_at' => date('Y-m-d H:i:s', $timestamp),
                'last_message_snippet' => $text ? mb_substr($text, 0, 120) : ($imageUrl ? '[image]' : '[media]'),
                'unread_count' => $newUnreadCounter,
                'session_id' => $sessionId,
            ];
            
            // Link JID if it was missing
            if (empty($chat['jid']) && $customerJid) {
                $updateData['jid'] = $customerJid;
            }

            // Sync phone if it looks like a real phone and current is an LID or empty
            if ($customerPhone && (empty($chat['phone']) || is_numeric($chat['phone']) && strlen($chat['phone']) > 15)) {
                 if (strlen($customerPhone) < 15) { // LID are usually long strings
                     $updateData['phone'] = $customerPhone;
                 }
            }
            
            if (empty($chat['display_name']) && $senderName) $updateData['display_name'] = $senderName;
            
            $updateOk = $this->chatModel->update($chat['id'], $updateData);
            if (!$updateOk) {
                log_message('error', 'Failed to update chat: {errors}', ['errors' => json_encode($this->chatModel->errors())]);
            }
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
            'direction' => $direction,
            'from_me' => $fromMe,
            'message_type' => $imageUrl ? 'image' : 'text',
            'text' => $text,
            'media_path' => $mediaPath,
            'media_mime' => $mediaMime,
            'external_message_id' => $messageId,
            'session_id' => $sessionId,
            'received_at' => date('Y-m-d H:i:s', $timestamp),
        ];

        // Add reply info if available
        if ($isReply && $quoted) {
            $messageData['quoted_message_id'] = $quoted['messageId'] ?? null;
            $messageData['quoted_text'] = $quoted['text'] ?? null;
        }

        $messageSaved = $this->messageModel->insert($messageData, true);
        
        if (!$messageSaved) {
            log_message('error', 'Failed to save message. Errors: {errors}. Data: {data}', [
                'errors' => json_encode($this->messageModel->errors()),
                'data' => json_encode($messageData)
            ]);
            return;
        }

        // Broadcast via SSE/Polling
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
        $fromMe = $payload['fromMe'] ?? $payload['from_me'] ?? true;
        $rawTo = $payload['rawTo'] ?? null;

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

        // Find the chat_id for this message so we can broadcast to the right room
        $messageRecord = $this->messageModel->where('external_message_id', $messageId)->first();
        $chatId = $messageRecord ? $messageRecord['chat_id'] : null;

        // 1. Update message status in database
        $this->messageModel->set(['status' => $finalStatus])
            ->where('external_message_id', $messageId)
            ->update();

        // 2. Adjust unread count if needed
        // If we acknowledge an outgoing message (especially a read/back ack), 
        // it often implies the chat is being handled, so we can clear unread count.
        if ($fromMe && $rawTo) {
            $tenantId = TenantContext::id();
            $jid = trim(preg_replace('/@[a-z0-9.@]+$/', '', $rawTo));
            
            // Only clear if the current ack shows we are interacting
            $this->chatModel->set(['unread_count' => 0])
                ->where('jid', $jid)
                ->where('tenant_id', $tenantId)
                ->update();
            
            log_message('debug', 'Cleared unread_count for chat {jid} due to message_ack', ['jid' => $jid]);
        }

        // Broadcast to WebSocket (include chatId for room filtering)
        $this->broadcastSSEEvent($tokoId, $chatId, [
            'type' => 'message_ack',
            'message_id' => $messageId,
            'chat_id' => $chatId,
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
     * Broadcast event to external WebSocket service
     */
    private function broadcastSSEEvent(int $tokoId, ?int $chatId = null, array $eventData = []): void
    {
        try {
            $eventData['toko_id'] = $tokoId;
            $eventData['chat_id'] = $chatId; // For room filtering in FE
            
            // 1. Local Fallback (Traditional Polling)
            $sseFile = self::SSE_FILE_DIR . "toko_{$tokoId}.queue";
            $eventJsonStr = json_encode($eventData);
            @file_put_contents($sseFile, $eventJsonStr . "\n", FILE_APPEND);

            // 2. EXTERNAL WEBSOCKET BROADCAST
            $url = "http://localhost:3009/api/ws/room/{$tokoId}/broadcast";
            
            $channels = ['general'];
            if ($chatId) {
                $channels[] = "room_{$chatId}";
            }

            foreach ($channels as $channel) {
                $payload = json_encode([
                    'event'   => 'message',
                    'channel' => $channel,
                    'data'    => $eventData,
                    'chat_id' => $chatId
                ]);

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload)
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, 150); // Short timeout

                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode < 200 || $httpCode >= 300) {
                    log_message('warning', "WS Broadcast failed to {$url} on channel {$channel} with status {$httpCode}");
                } else {
                    log_message('debug', "WS Broadcast success to {$url} on channel {$channel}");
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Failed to broadcast event: {msg}', ['msg' => $e->getMessage()]);
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
