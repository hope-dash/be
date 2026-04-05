<?php

namespace App\Controllers;

use App\Libraries\TenantContext;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ChatSSEController
 * 
 * Handles Server-Sent Events (SSE) for real-time chat updates
 * Clients connect to this endpoint and receive live updates
 */
class ChatSSEController extends BaseController
{
    private const SSE_FILE_DIR = WRITEPATH . 'sse-messages/';
    private const SSE_TIMEOUT = 30; // Connection timeout in seconds
    private const POLL_INTERVAL = 1; // Check for new messages every X seconds

    /**
     * Subscribe to chat updates via SSE
     * 
     * GET /api/chat/events/:tokoId
     * 
     * Long-polling SSE connection for real-time chat updates
     * 
     * @param int $tokoId Store ID
     * @return ResponseInterface
     */
    public function subscribe($tokoId)
    {
        // Validate store exists
        $sessionModel = new \App\Models\ChatSessionModel();
        $toko = $sessionModel->find($tokoId);
        if (!$toko) {
            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Store not found',
            ]);
        }

        // Prevent session locking
        if (session_id()) {
            session_write_close();
        }

        // Set headers for SSE natively to ensure immediate flush
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        // Disable any existing output buffering
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        // Send initial connection message immediately
        echo "data: " . json_encode([
            'type' => 'connected',
            'message' => 'Connected to chat stream',
            'toko_id' => $tokoId,
            'timestamp' => date('Y-m-d H:i:s'),
        ]) . "\n\n";
        
        if (ob_get_level() > 0) ob_flush();
        flush();

        // Track start time for timeout
        $startTime = time();
        $queueFile = self::SSE_FILE_DIR . "toko_{$tokoId}.queue";
        $lastReadPos = 0;

        // Poll for messages
        while (true) {
            // Check timeout
            if (time() - $startTime > self::SSE_TIMEOUT) {
                // Send keep-alive comment every 30 seconds
                echo ": keep-alive\n\n";
                flush();
                $startTime = time();
            }

            // Check for new messages in queue
            if (file_exists($queueFile)) {
                $currentSize = filesize($queueFile);

                if ($currentSize > $lastReadPos) {
                    // New data available
                    $handle = fopen($queueFile, 'r');
                    if ($handle) {
                        fseek($handle, $lastReadPos);
                        $newData = fread($handle, $currentSize - $lastReadPos);
                        fclose($handle);

                        if (!empty($newData)) {
                            // Process each line as a separate event
                            $lines = explode("\n", trim($newData));
                            foreach ($lines as $line) {
                                if (!empty($line)) {
                                    echo "data: " . $line . "\n\n";
                                    flush();
                                }
                            }

                            $lastReadPos = $currentSize;
                        }
                    }
                }
            }

            // Sleep for a bit to avoid hammering CPU
            usleep(self::POLL_INTERVAL * 1000000);

            // Check if client disconnected
            if (connection_aborted()) {
                log_message('info', 'SSE client disconnected for toko {toko_id}', ['toko_id' => $tokoId]);
                exit;
            }
        }
    }

    /**
     * Poll for chat updates (HTTP alternative to SSE)
     * 
     * GET /api/chat/poll/:tokoId?last_pos=0
     * 
     * Returns:
     * { "events": [...], "next_pos": 1234 }
     * 
     * @param int $tokoId Store ID
     * @return ResponseInterface
     */
    public function poll($tokoId)
    {
        $lastPos = (int)($this->request->getGet('last_pos') ?? 0);
        $queueFile = self::SSE_FILE_DIR . "toko_{$tokoId}.queue";
        $events = [];
        $nextPos = $lastPos;

        if (file_exists($queueFile)) {
            $currentSize = filesize($queueFile);
            
            if ($currentSize > $lastPos) {
                // If lastPos is too old (file was cleaned/truncated), start from current end
                // We'll assume if lastPos is more than 1MB behind, it's effectively 0 or we just send tail
                if ($currentSize - $lastPos > 1024 * 1024) {
                    $lastPos = max(0, $currentSize - 1024);
                }

                $handle = fopen($queueFile, 'r');
                if ($handle) {
                    fseek($handle, $lastPos);
                    $newData = fread($handle, $currentSize - $lastPos);
                    fclose($handle);

                    if (!empty($newData)) {
                        $lines = explode("\n", trim($newData));
                        foreach ($lines as $line) {
                            if (!empty($line)) {
                                $data = json_decode($line, true);
                                if ($data) $events[] = $data;
                            }
                        }
                        $nextPos = $currentSize;
                    }
                }
            }
        }

        return $this->response->setJSON([
            'success' => true,
            'events' => $events,
            'next_pos' => $nextPos,
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }
    /**
     * Subscribe to specific chat updates
     * 
     * Real-time updates for a specific chat conversation
     * 
     * @param int $tokoId Store ID
     * @param int $chatId Chat ID
     * @return ResponseInterface
     */
    public function subscribeChat($tokoId, $chatId)
    {
        // Validate store and chat exist
        $sessionModel = new \App\Models\ChatSessionModel();
        $toko = $sessionModel->find($tokoId);
        if (!$toko) {
            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Store not found',
            ]);
        }

        $chatModel = new \App\Models\WhatsAppChatModel();
        $chat = $chatModel->find($chatId);
        if (!$chat) {
            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Chat not found',
            ]);
        }

        // Prevent session locking
        if (session_id()) {
            session_write_close();
        }

        // Set headers for SSE natively to ensure immediate flush
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        // Send initial connection message immediately
        echo "data: " . json_encode([
            'type' => 'connected',
            'message' => 'Connected to chat stream',
            'toko_id' => $tokoId,
            'chat_id' => $chatId,
            'timestamp' => date('Y-m-d H:i:s'),
        ]) . "\n\n";
        
        if (ob_get_level() > 0) ob_flush();
        flush();

        $startTime = time();
        $queueFile = self::SSE_FILE_DIR . "toko_{$tokoId}.queue";
        $lastReadPos = 0;

        // Poll for messages
        while (true) {
            if (time() - $startTime > self::SSE_TIMEOUT) {
                echo ": keep-alive\n\n";
                flush();
                $startTime = time();
            }

            if (file_exists($queueFile)) {
                $currentSize = filesize($queueFile);

                if ($currentSize > $lastReadPos) {
                    $handle = fopen($queueFile, 'r');
                    if ($handle) {
                        fseek($handle, $lastReadPos);
                        $newData = fread($handle, $currentSize - $lastReadPos);
                        fclose($handle);

                        if (!empty($newData)) {
                            $lines = explode("\n", trim($newData));
                            foreach ($lines as $line) {
                                if (!empty($line)) {
                                    $eventData = json_decode($line, true);

                                    // Only send events for this specific chat
                                    if (
                                        ($eventData['type'] === 'new_message' && (int)$eventData['chat_id'] === (int)$chatId) ||
                                        ($eventData['type'] === 'message_status') ||
                                        ($eventData['type'] === 'session_status')
                                    ) {
                                        echo "data: " . $line . "\n\n";
                                        flush();
                                    }
                                }
                            }

                            $lastReadPos = $currentSize;
                        }
                    }
                }
            }

            usleep(self::POLL_INTERVAL * 1000000);

            if (connection_aborted()) {
                log_message('info', 'SSE client disconnected for chat {chat_id} in toko {toko_id}', [
                    'chat_id' => $chatId,
                    'toko_id' => $tokoId,
                ]);
                exit;
            }
        }
    }

    /**
     * Clean up old SSE queue files (scheduled task)
     * 
     * Removes queue files older than 1 hour
     * Can be called via: php spark chat:cleanup-sse
     */
    public static function cleanupQueues(): void
    {
        $dir = self::SSE_FILE_DIR;
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '*.queue');
        $oneHourAgo = time() - 3600;

        foreach ($files as $file) {
            if (filemtime($file) < $oneHourAgo) {
                @unlink($file);
                log_message('info', 'Cleaned up old SSE queue: {file}', ['file' => $file]);
            }
        }
    }
}
