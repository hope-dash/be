<?php

namespace Config;

use Daycry\CronJob\Config\CronJob as BaseConfig;
use Daycry\CronJob\Scheduler;

class CronJob extends BaseConfig
{
    /*
    |--------------------------------------------------------------------------
    | Default Database Group
    |--------------------------------------------------------------------------
    |
    | The database group to use for logging.
    |
    */
    public ?string $databaseGroup = 'default';

    /*
    |--------------------------------------------------------------------------
    | Log Table Name
    |--------------------------------------------------------------------------
    |
    | The name of the table to use for logging.
    |
    */
    public string $tableName = 'cronjob';

    /*
    |--------------------------------------------------------------------------
    | CronJob Enabled
    |--------------------------------------------------------------------------
    |
    | If false, no jobs will be run.
    |
    */
    public bool $enabled = true;

    /*
    |--------------------------------------------------------------------------
    | Run Jobs in Background
    |--------------------------------------------------------------------------
    |
    | If true, jobs will be run in the background.
    |
    */
    public bool $runInBackground = true;

    /**
     * Set the path to the PHP executable.
     */
    public string $phpPath = 'php';

    /*
    |--------------------------------------------------------------------------
    | Notification Email
    |--------------------------------------------------------------------------
    |
    | The email address to send notifications to.
    |
    */
    public bool $notification = false;
    public string $from = 'your@example.com';
    public string $fromName = 'CronJob';
    public string $to = 'your@example.com';
    public string $toName = 'User';

    /**
     * Define the application's command schedule.
     *
     * @param Scheduler $schedule
     */
    public function init(Scheduler $schedule)
    {
        // Process email queue every minute
        $schedule->command('email:process-queue')->everyMinute()->named('email-queue');

        // You can add more cronjobs here
        // $schedule->command('logs:clear')->daily('00:00')->named('clear-logs');
    }
}
