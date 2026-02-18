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
        $limit = $params[0] ?? 10;
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
                $queueModel->update($email['id'], [
                    'status' => 'FAILED',
                    'attempts' => $email['attempts'] + 1,
                    'error_message' => 'Internal email library error'
                ]);
                CLI::error("Failed to send email.");
            }
        }
    }
}
