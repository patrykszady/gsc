<?php

namespace App\Console\Commands;

use App\Services\MetaSocialService;
use Illuminate\Console\Command;

class MetaSocialAuth extends Command
{
    protected $signature = 'social:meta-auth
        {--token= : Short-lived user access token from Graph API Explorer}
        {--debug : Show current token debug info}';

    protected $description = 'Set up Meta (Instagram/Facebook) authentication — exchange a short-lived token for a permanent Page Access Token';

    public function handle(MetaSocialService $service): int
    {
        if ($this->option('debug')) {
            return $this->debugToken($service);
        }

        $token = $this->option('token');

        if (! $token) {
            $this->info('=== Meta Social Auth Setup ===');
            $this->newLine();
            $this->line('Steps to get your short-lived token:');
            $this->line('1. Go to https://developers.facebook.com/tools/explorer/');
            $this->line('2. Select your Meta App');
            $this->line('3. Click "Generate Access Token"');
            $this->line('4. Grant pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish');
            $this->line('5. Copy the token');
            $this->newLine();

            $token = $this->ask('Paste your short-lived access token');
        }

        if (! $token) {
            $this->error('No token provided.');
            return 1;
        }

        $this->info('Exchanging for permanent Page Access Token...');

        $pageToken = $service->exchangeForLongLivedToken($token);

        if (! $pageToken) {
            $error = $service->getLastError();
            $this->error('Failed: ' . ($error['message'] ?? 'Unknown error'));
            if (isset($error['body'])) {
                $this->line(json_encode($error['body'], JSON_PRETTY_PRINT));
            }
            return 1;
        }

        $this->newLine();
        $this->info('✅ Success! Here is your permanent Page Access Token:');
        $this->newLine();
        $this->line($pageToken);
        $this->newLine();
        $this->warn('Add this to your .env file as:');
        $this->line('META_PAGE_ACCESS_TOKEN=' . $pageToken);
        $this->newLine();
        $this->info('This token does NOT expire as long as the Meta App and Page remain active.');

        return 0;
    }

    protected function debugToken(MetaSocialService $service): int
    {
        if (! config('services.meta.page_access_token')) {
            $this->error('META_PAGE_ACCESS_TOKEN is not set in .env');
            return 1;
        }

        $this->info('Checking token...');
        $info = $service->debugTokenInfo();

        $tokenData = $info['token_debug']['data'] ?? [];
        $this->table(['Property', 'Value'], [
            ['App ID', $tokenData['app_id'] ?? 'N/A'],
            ['Type', $tokenData['type'] ?? 'N/A'],
            ['Valid', ($tokenData['is_valid'] ?? false) ? '✅ Yes' : '❌ No'],
            ['Expires', ($tokenData['expires_at'] ?? 0) === 0 ? 'Never (permanent)' : date('Y-m-d H:i', $tokenData['expires_at'])],
            ['Scopes', implode(', ', $tokenData['scopes'] ?? [])],
        ]);

        $pages = $info['pages'] ?? [];
        if ($pages) {
            $this->newLine();
            $this->info('Connected Pages:');
            foreach ($pages as $page) {
                $this->line("  - {$page['name']} (ID: {$page['id']})");
            }
        }

        return 0;
    }
}
