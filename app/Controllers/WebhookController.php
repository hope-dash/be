<?php

namespace App\Controllers;

use App\Models\WhatsAppChatModel;
use App\Models\WhatsAppMessageModel;
use App\Models\WhatsAppLabelModel;
use App\Models\WhatsAppChatLabelModel;
use App\Libraries\TenantContext;

class WebhookController extends BaseController
{
    /**
     * Receive webhook from WhatsApp gateway and log payload for debugging.
     */
    public function whatsappGateway()
    {
        // Capture whatever the gateway sends (JSON, form-data, or query params)
        $payload = $this->request->getJSON(true);
        if ($payload === null) {
            $payload = $this->request->getRawInput();
        }

        // Ensure we log something even if body is empty
        $payload = $payload ?: [
            'raw_body' => $this->request->getBody(),
            'query'    => $this->request->getGet(),
            'method'   => $this->request->getMethod(),
        ];

        $this->logPayload($payload);

        // Persist minimal data
        [$chat, $message] = $this->storeIncomingMessage($payload);

        return $this->response->setJSON([
            'status' => 'ok',
            'chat_id' => $chat['id'] ?? null,
            'message_id' => $message['id'] ?? null,
        ]);
    }

    private function logPayload(array $payload): void
    {
        // Log to the application log (shows up in console when running `php spark serve`)
        log_message('info', 'WhatsApp Webhook hit: {payload}', [
            'payload' => json_encode($payload),
        ]);

        // Also append to dedicated log file for quick inspection
        $line = sprintf(
            "[%s] %s %s payload=%s%s",
            date('c'),
            $this->request->getMethod(),
            $this->request->getUri()->getPath(),
            json_encode($payload),
            PHP_EOL
        );
        @file_put_contents(WRITEPATH . 'logs/whatsapp-gateway.log', $line, FILE_APPEND);
    }

    /**
     * Store incoming message to lightweight tables.
     * Keeps only essential fields; converts images to webp.
     */
    private function storeIncomingMessage(array $payload): array
    {
        $tenantId = $payload['tenant_id'] ?? (TenantContext::id() ?: null);
        $phone = $this->normalizePhone($payload['from'] ?? $payload['phone'] ?? $payload['wa_id'] ?? '');
        if (!$phone) {
            return [null, null];
        }

        $text = $payload['text'] ?? ($payload['message'] ?? null);
        if (is_array($text) && isset($text['body'])) {
            $text = $text['body'];
        }

        $mediaUrl = $payload['media_url'] ?? ($payload['image']['url'] ?? null);
        $mediaMime = $payload['media_mime'] ?? ($payload['image']['mime_type'] ?? null);
        $messageType = $mediaUrl ? 'image' : 'text';

        $chatModel = new WhatsAppChatModel();
        $messageModel = new WhatsAppMessageModel();

        // Find or create chat
        $chat = $chatModel
            ->where('phone', $phone)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$chat) {
            $chatId = $chatModel->insert([
                'tenant_id' => $tenantId,
                'phone' => $phone,
                'display_name' => $payload['name'] ?? null,
                'last_message_at' => date('Y-m-d H:i:s'),
                'last_message_snippet' => $text ? mb_substr($text, 0, 120) : '[image]',
                'unread_count' => 1,
            ], true);
            $chat = $chatModel->find($chatId);
        } else {
            $chatModel->update($chat['id'], [
                'display_name' => $chat['display_name'] ?: ($payload['name'] ?? null),
                'last_message_at' => date('Y-m-d H:i:s'),
                'last_message_snippet' => $text ? mb_substr($text, 0, 120) : '[image]',
                'unread_count' => ($chat['unread_count'] ?? 0) + 1,
            ]);
            $chat = $chatModel->find($chat['id']);
        }

        $mediaPath = null;
        if ($mediaUrl) {
            $mediaPath = $this->storeWebpImage($mediaUrl);
        }

        $messageId = $messageModel->insert([
            'tenant_id' => $tenantId,
            'chat_id' => $chat['id'],
            'direction' => 'in',
            'message_type' => $messageType,
            'text' => $text,
            'media_path' => $mediaPath,
            'media_mime' => $mediaMime,
            'received_at' => isset($payload['timestamp']) ? date('Y-m-d H:i:s', (int)$payload['timestamp']) : date('Y-m-d H:i:s'),
        ], true);

        $message = $messageModel->find($messageId);

        return [$chat, $message];
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\\D+/', '', $phone);
    }

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
        } catch (\\Throwable $e) {
            log_message('error', 'Failed to store webp image: {msg}', ['msg' => $e->getMessage()]);
            return null;
        }
    }
}
