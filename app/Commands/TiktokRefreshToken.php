<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\TokoMetaModel;
use App\Controllers\TiktokController;

class TiktokRefreshToken extends BaseCommand
{
    protected $group = 'Cron';
    protected $name = 'tiktok:refresh-token';
    protected $description = 'Refresh TikTok Shop access tokens for all active integrated stores';

    public function run(array $params)
    {
        CLI::write("Starting TikTok Shop token refresh process...", "yellow");

        $tokoMetaModel = new TokoMetaModel();
        $tokens = $tokoMetaModel->where('meta_key', 'tiktok_refresh_token')
                               ->where('meta_value !=', '')
                               ->findAll();

        if (empty($tokens)) {
            CLI::write("No integrated TikTok stores found with a refresh token.", "red");
            return;
        }

        $controller = new TiktokController();

        foreach ($tokens as $tokenRow) {
            $tokoId = $tokenRow['toko_id'];
            CLI::write("Refreshing token for Toko ID: {$tokoId}...", "cyan");

            $result = $controller->performTokenRefresh($tokoId);

            if ($result['success']) {
                CLI::write("Successfully refreshed token for Toko ID {$tokoId}.", "green");
            } else {
                CLI::write("Failed to refresh token for Toko ID {$tokoId}: " . $result['message'], "red");
            }
        }

        CLI::write("TikTok Shop token refresh process completed.", "yellow");
    }
}
