<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;

/**
 * Chat Service API Library
 * 
 * Communicates with external WhatsApp chat server
 * Handles session management, message sending, and status checks
 */
class ChatServiceAPI
{
    private string $baseUrl;
    private CURLRequest $client;
    private string $apiKey = '';

    public function __construct()
    {
        // Get base URL from config or environment
        $this->baseUrl = env('CHAT_API_BASE_URL', 'http://localhost:3000');
        $this->client = \Config\Services::curlRequest();
    }

    /**
     * Start a new chat session
     * 
     * @param string $sessionId Session identifier (e.g., "toko_name_unique_id")
     * @param string $webhookUrl URL where incoming messages will be sent
     * @return array Response with sessionId and QR code
     * @throws Exception
     */
    public function startSession(string $sessionId, string $webhookUrl): array
    {
        try {
            $response = $this->client->request('POST', "{$this->baseUrl}/api/session/start", [
                'json' => [
                    'sessionId' => $sessionId,
                    'webhookUrl' => $webhookUrl,
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception($body['message'] ?? 'Failed to start session');
            }

            return $body;
        } catch (\Throwable $e) {
            log_message('error', 'ChatServiceAPI::startSession failed: {msg}', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get current session status
     * 
     * @param string $sessionId Session ID to check
     * @return array Session status data
     * @throws Exception
     */
    public function getSessionStatus(string $sessionId): array
    {
        try {
            $response = $this->client->request('GET', "{$this->baseUrl}/api/session/status/{$sessionId}");

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception($body['message'] ?? 'Failed to get session status');
            }

            return $body;
        } catch (\Throwable $e) {
            log_message('error', 'ChatServiceAPI::getSessionStatus failed: {msg}', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get QR code for session
     * 
     * @param string $sessionId Session ID
     * @return array QR code data (base64 encoded image)
     * @throws Exception
     */
    public function getQrCode(string $sessionId): array
    {
        try {
            $response = $this->client->request('GET', "{$this->baseUrl}/api/session/qr/{$sessionId}");

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception($body['message'] ?? 'Failed to get QR code');
            }

            return $body;
        } catch (\Throwable $e) {
            log_message('error', 'ChatServiceAPI::getQrCode failed: {msg}', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Send text message
     * 
     * @param string $sessionId Session ID
     * @param string $to Recipient phone (format: 6281234567890@c.us for private, @g.us for group)
     * @param string $text Message text
     * @param int $delayMs Delay before sending (milliseconds)
     * @param int $typingDurationMs Typing duration indicator (milliseconds)
     * @return array Response with messageId
     * @throws Exception
     */
    public function sendTextMessage(
        string $sessionId,
        string $to,
        string $text,
        int $delayMs = 1000,
        int $typingDurationMs = 2000
    ): array {
        try {
            $response = $this->client->request('POST', "{$this->baseUrl}/api/session/send", [
                'json' => [
                    'sessionId' => $sessionId,
                    'to' => $to,
                    'text' => $text,
                    'delayMs' => $delayMs,
                    'typingDurationMs' => $typingDurationMs,
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception($body['message'] ?? 'Failed to send message');
            }

            return $body;
        } catch (\Throwable $e) {
            log_message('error', 'ChatServiceAPI::sendTextMessage failed: {msg}', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Send image message via URL
     * 
     * @param string $sessionId Session ID
     * @param string $to Recipient phone (format: 6281234567890@c.us)
     * @param string $imageUrl URL of image to send
     * @param string|null $caption Optional caption for image
     * @param int $delayMs Delay before sending (milliseconds)
     * @return array Response with messageId
     * @throws Exception
     */
    public function sendImageMessage(
        string $sessionId,
        string $to,
        string $imageUrl,
        ?string $caption = null,
        int $delayMs = 1000
    ): array {
        try {
            $response = $this->client->request('POST', "{$this->baseUrl}/api/session/send", [
                'json' => [
                    'sessionId' => $sessionId,
                    'to' => $to,
                    'imageUrl' => $imageUrl,
                    'caption' => $caption,
                    'delayMs' => $delayMs,
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception($body['message'] ?? 'Failed to send image');
            }

            return $body;
        } catch (\Throwable $e) {
            log_message('error', 'ChatServiceAPI::sendImageMessage failed: {msg}', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Send base64 image message
     * 
     * @param string $sessionId Session ID
     * @param string $to Recipient phone (format: 6281234567890@c.us)
     * @param string $imageBase64 Base64 string of image
     * @param string|null $caption Optional caption for image
     * @param int $delayMs Delay before sending (milliseconds)
     * @return array Response with messageId
     * @throws Exception
     */
    public function sendImageBase64Message(
        string $sessionId,
        string $to,
        string $imageBase64,
        ?string $caption = null,
        int $delayMs = 1000
    ): array {
        try {
            $response = $this->client->request('POST', "{$this->baseUrl}/api/session/send", [
                'json' => [
                    'sessionId' => $sessionId,
                    'to' => $to,
                    'imageBase64' => $imageBase64,
                    'caption' => $caption,
                    'delayMs' => $delayMs,
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception($body['message'] ?? 'Failed to send base64 image');
            }

            return $body;
        } catch (\Throwable $e) {
            log_message('error', 'ChatServiceAPI::sendImageBase64Message failed: {msg}', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }


    /**
     * Mark chat as read
     * 
     * @param string $sessionId Session ID
     * @param string $chatJid Chat JID (e.g., 6281234567890@c.us)
     * @return array Response status
     * @throws Exception
     */
    public function markChatAsRead(string $sessionId, string $chatJid): array
    {
        try {
            $response = $this->client->request('POST', "{$this->baseUrl}/api/session/read", [
                'json' => [
                    'sessionId' => $sessionId,
                    'chatId' => $chatJid,
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception($body['message'] ?? 'Failed to mark chat as read');
            }

            return $body;
        } catch (\Throwable $e) {
            log_message('error', 'ChatServiceAPI::markChatAsRead failed: {msg}', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Disconnect session
     * 
     * @param string $sessionId Session ID to disconnect
     * @return array Response status
     * @throws Exception
     */
    public function disconnectSession(string $sessionId): array
    {
        try {
            $response = $this->client->request('POST', "{$this->baseUrl}/api/session/disconnect", [
                'json' => ['sessionId' => $sessionId],
            ]);

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception($body['message'] ?? 'Failed to disconnect session');
            }

            return $body;
        } catch (\Throwable $e) {
            log_message('error', 'ChatServiceAPI::disconnectSession failed: {msg}', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Format phone number to WhatsApp format
     * 
     * @param string $phone Phone number
     * @param string $type 'private' for individual (@c.us) or 'group' for group (@g.us)
     * @return string Formatted phone number
     */
    public static function formatPhoneNumber(string $phone, string $type = 'private'): string
    {
        // Remove non-digits
        $phone = preg_replace('/\D+/', '', $phone);

        // Add country code if not present (assume Indonesia +62 if starts with 0)
        if (substr($phone, 0, 1) === '0') {
            $phone = '62' . substr($phone, 1);
        } elseif (!preg_match('/^[0-9]{2,}/', $phone)) {
            // If no country code, assume Indonesia
            $phone = '62' . $phone;
        }

        $suffix = $type === 'group' ? '@g.us' : '@c.us';
        return $phone . $suffix;
    }
}
