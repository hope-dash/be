<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Controllers\ChatSSEController;

class CleanupSSECommand extends BaseCommand
{
    protected $group = 'Chat';
    protected $name = 'chat:cleanup-sse';
    protected $description = 'Clean up old SSE queue files (older than 1 hour)';
    protected $usage = 'chat:cleanup-sse';
    protected $arguments = [];
    protected $options = [];

    public function run(array $params = [])
    {
        CLI::write('Cleaning up old SSE queue files...', 'yellow');

        try {
            ChatSSEController::cleanupQueues();
            CLI::write('✓ SSE queue cleanup completed', 'green');
        } catch (\Throwable $e) {
            CLI::error('✗ Failed to cleanup SSE queues: ' . $e->getMessage());
        }
    }
}
