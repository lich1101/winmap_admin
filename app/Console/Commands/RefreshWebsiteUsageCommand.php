<?php

namespace App\Console\Commands;

use App\Models\MonitoredWebsite;
use App\Services\DrupalUsageClient;
use Illuminate\Console\Command;

class RefreshWebsiteUsageCommand extends Command
{
    protected $signature = 'websites:refresh-usage {--only= : Chỉ refresh một domain cụ thể}';

    protected $description = 'Refresh disk/database/user usage for monitored Drupal websites.';

    public function handle(DrupalUsageClient $client): int
    {
        $query = MonitoredWebsite::query()->where('enabled', true)->orderBy('domain');
        if ($only = trim((string) $this->option('only'))) {
            $query->where('domain', $only);
        }

        $websites = $query->get();
        if ($websites->isEmpty()) {
            $this->info('No enabled websites to refresh.');
            return self::SUCCESS;
        }

        $success = 0;
        $errors = 0;

        foreach ($websites as $website) {
            $snapshot = $client->refresh($website);
            if ($snapshot->status === 'ok') {
                $success++;
                $this->line(sprintf('[ok] %s %s', $website->domain, $snapshot->project_bytes));
                continue;
            }

            $errors++;
            $this->error(sprintf('[error] %s %s', $website->domain, $snapshot->error ?: 'Unknown error'));
        }

        $this->info(sprintf('Refreshed %d websites: %d ok, %d errors.', $websites->count(), $success, $errors));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
