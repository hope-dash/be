<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\Libraries\WhatsAppSocketServer;
use React\Socket\Server as ReactSocket;
use React\EventLoop\Loop;

class SocketServerCommand extends BaseCommand
{
    protected $group = 'Chat';
    protected $name = 'socket:serve';
    protected $description = 'Start the WhatsApp WebSocket Server (Ratchet)';
    protected $usage = 'socket:serve';
    protected $arguments = [];
    protected $options = [];

    public function run(array $params = [])
    {
        CLI::write('Starting WhatsApp WebSocket Server...', 'yellow');

        $loop = Loop::get();
        $socketApp = new WhatsAppSocketServer();

        // 1. Setup the WebSocket server for browser clients (Port 3009)
        $wsSocket = new ReactSocket('0.0.0.0:3009', $loop);
        $wsServer = new IoServer(
            new HttpServer(
                new WsServer($socketApp)
            ),
            $wsSocket,
            $loop
        );

        // 2. Setup the internal bridge (Port 8091)
        // This allows our Backend Controllers to "push" events to the WebSocket server
        $internalSocket = new ReactSocket('127.0.0.1:8091', $loop);
        $internalSocket->on('connection', function (\React\Socket\ConnectionInterface $connection) use ($socketApp) {
            $connection->on('data', function ($data) use ($socketApp) {
                try {
                    $payload = json_decode($data, true);
                    if ($payload && isset($payload['toko_id'])) {
                        $tokoId = (int)$payload['toko_id'];
                        $socketApp->broadcastToToko($tokoId, $payload);
                    }
                } catch (\Throwable $e) {
                    echo "Bridge Error: " . $e->getMessage() . "\n";
                }
            });
        });

        CLI::write("✓ WebSocket server listening on port 8090", "green");
        CLI::write("✓ Internal bridge listening on port 8091", "green");
        CLI::write("Press Ctrl+C to stop.", "yellow");

        $loop->run();
    }
}
