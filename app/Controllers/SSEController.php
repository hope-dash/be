<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class SSEController extends Controller
{
    public function test()
    {
        // Disable PHP output compression
        if (function_exists('ini_set')) {
            ini_set('zlib.output_compression', '0');
            ini_set('implicit_flush', '1');
        }
        
        ob_implicit_flush(true);

        // Set headers for Server-Sent Events
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Tell NGINX not to buffer

        // Allow CORS if necessary
        header('Access-Control-Allow-Origin: *');

        // Close sessions if opened to prevent blocking other requests
        if (session_id()) {
            session_write_close();
        }

        // Prevent buffering
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Send a message every 10 seconds, limited to 5 iterations for testing
        $maxIterations = 5; 
        for ($i = 1; $i <= $maxIterations; $i++) {
            $time = date('Y-m-d H:i:s');
            $data = json_encode(['time' => $time, 'message' => "Message $i of $maxIterations"]);
            
            echo "event: message\n";
            echo "data: {$data}\n\n";

            // Pad the output if some web servers (like PHP's built in server) buffer until a certain size
            echo str_repeat(" ", 4096) . "\n";
            
            // Flush the output buffer to send data immediately
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            // Wait for 10 seconds
            if ($i < $maxIterations) {
                sleep(10);
            }
        }
        
        // Signal that the stream is closing
        echo "event: close\n";
        echo "data: Stream ended\n\n";
        
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        
        exit;
    }
}
