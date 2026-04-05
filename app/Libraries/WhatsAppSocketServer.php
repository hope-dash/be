<?php

namespace App\Libraries;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WhatsAppSocketServer implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;
    protected array $subscribers = []; // Map stored clients by toko_id

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        echo "WhatsApp WebSocket Server initialized.\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        // Store the new connection
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['action'])) {
            return;
        }

        switch ($data['action']) {
            case 'subscribe':
                // Join a specific toko room
                if (isset($data['toko_id'])) {
                    $tokoId = (int)$data['toko_id'];
                    $this->subscribers[$tokoId][$from->resourceId] = $from;
                    echo "Client {$from->resourceId} subscribed to Toko {$tokoId}\n";
                    
                    $from->send(json_encode([
                        'type' => 'subscribed',
                        'toko_id' => $tokoId,
                        'message' => 'Connected to WebSocket'
                    ]));
                }
                break;

            case 'ping':
                $from->send(json_encode(['type' => 'pong', 'time' => time()]));
                break;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        // Remove from all subscribers
        foreach ($this->subscribers as $tokoId => $clients) {
            unset($this->subscribers[$tokoId][$conn->resourceId]);
        }
        
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * Internal method to broadcast to all clients in a specific TOKO room
     */
    public function broadcastToToko(int $tokoId, array $payload)
    {
        if (isset($this->subscribers[$tokoId])) {
            $count = 0;
            $payloadJson = json_encode($payload);
            
            foreach ($this->subscribers[$tokoId] as $client) {
                $client->send($payloadJson);
                $count++;
            }
            log_message('debug', "WebSocket Broadcast: Sent to {$count} clients in TokoRoom {$tokoId}");
        }
    }
}
