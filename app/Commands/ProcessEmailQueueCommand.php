<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\EmailQueueModel;

class ProcessEmailQueueCommand extends BaseCommand
{
    /**
     * The Command's Group
     *
     * @var string
     */
    protected $group = 'App';

    /**
     * The Command's Name
     *
     * @var string
     */
    protected $name = 'email:process-queue';

    /**
     * The Command's Description
     *
     * @var string
     */
    protected $description = 'Processes the email queue from the database.';

    /**
     * The Command's Usage
     *
     * @var string
     */
    protected $usage = 'email:process-queue [limit]';

    /**
     * The Command's Arguments
     *
     * @var array
     */
    protected $arguments = [
        'limit' => 'Limit the number of emails to process in one run (default: 10)',
    ];

    /**
     * The Command's Options
     *
     * @var array
     */
    protected $options = [];

    /**
     * Actually execute a command.
     *
     * @param array $params
     */
    public function run(array $params)
    {
        helper('email');
        $limit = (int) ($params[0] ?? 10);
        $queueModel = new EmailQueueModel();

        $emails = $queueModel->where('status', 'PENDING')
            ->orWhere('status', 'FAILED')
            ->where('attempts <', 3)
            ->orderBy('created_at', 'ASC')
            ->limit($limit)
            ->findAll();

        if (empty($emails)) {
            CLI::write('No pending emails in the queue.', 'green');
            return;
        }

        foreach ($emails as $email) {
            CLI::write("Processing email ID: {$email['id']} to {$email['recipient']}...", 'cyan');

            // Set tenant context for each email (needed by email_helper)
            if (isset($email['tenant_id'])) {
                $tenantModel = new \App\Models\TenantModel();
                $tenant = $tenantModel->find($email['tenant_id']);
                if ($tenant) {
                    CLI::write("Setting tenant context to: " . ($tenant['name'] ?? 'N/A') . " (ID: " . $email['tenant_id'] . ")", 'yellow');
                    \App\Libraries\TenantContext::set($tenant);
                } else {
                    CLI::error("Tenant not found for ID: " . $email['tenant_id']);
                }
            }

            // Mark as processing
            $queueModel->update($email['id'], ['status' => 'PROCESSING']);

            if (send_email($email['recipient'], $email['subject'], $email['message'])) {
                $queueModel->update($email['id'], [
                    'status' => 'SENT',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'attempts' => $email['attempts'] + 1
                ]);
                CLI::write("Email sent successfully.", 'green');
            } else {
                $emailService = \Config\Services::email();
                $errorMessage = $emailService->printDebugger(['headers', 'subject', 'body']);
                
                $queueModel->update($email['id'], [
                    'status' => 'FAILED',
                    'attempts' => $email['attempts'] + 1,
                    'error_message' => substr($errorMessage, 0, 1000) // Truncate if too long
                ]);
                CLI::error("Failed to send email ID: {$email['id']}");
            }
        }
    }
}
