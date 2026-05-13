<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandLog;
use App\Models\MonitoredWebsite;
use App\Services\ByteFormatter;
use App\Services\ServerUsageService;
use App\Services\SetupConfigurationService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(
        ServerUsageService $serverUsage,
        SetupConfigurationService $setupConfiguration,
    ): JsonResponse
    {
        $websites = MonitoredWebsite::query()
            ->orderBy('domain')
            ->get()
            ->map(fn (MonitoredWebsite $website) => $this->decorateWebsite($website));

        $setup = $setupConfiguration->current();

        return response()->json([
            'setup' => [
                'server_host' => $setup->server_host,
                'server_port' => $setup->server_port,
                'drupal_project_path' => $setup->drupal_project_path,
                'auth_site_domain' => $setup->auth_site_domain,
                'default_website_username' => $setup->default_website_username,
                'has_default_website_password' => (bool) $setup->has_default_website_password,
            ],
            'server' => $serverUsage->summary(),
            'summary' => [
                'website_count' => $websites->count(),
                'enabled_count' => $websites->where('enabled', true)->count(),
                'over_quota_count' => $websites->where('last_is_blocked', true)->count(),
                'warning_count' => $websites->where('last_is_warning', true)->where('last_is_blocked', false)->count(),
                'total_user_count' => $websites->sum('last_user_count'),
                'total_disk_bytes' => $websites->sum('last_disk_bytes'),
                'total_disk_human' => ByteFormatter::human($websites->sum('last_disk_bytes')),
                'total_database_bytes' => $websites->sum('last_database_bytes'),
                'total_database_human' => ByteFormatter::human($websites->sum('last_database_bytes')),
                'total_project_bytes' => $websites->sum('last_project_bytes'),
                'total_project_human' => ByteFormatter::human($websites->sum('last_project_bytes')),
            ],
            'websites' => $websites->values(),
            'recent_commands' => CommandLog::query()->latest()->limit(8)->get(),
        ]);
    }

    private function decorateWebsite(MonitoredWebsite $website): array
    {
        $data = $website->toArray();
        $data['quota_human'] = ByteFormatter::human($website->quota_bytes);
        $data['last_disk_human'] = ByteFormatter::human($website->last_disk_bytes);
        $data['last_database_human'] = ByteFormatter::human($website->last_database_bytes);
        $data['last_project_human'] = ByteFormatter::human($website->last_project_bytes);
        $data['over_quota'] = $website->last_is_blocked || ($website->quota_bytes > 0 && $website->last_project_bytes > $website->quota_bytes);
        $data['user_usage_percent'] = $website->user_limit > 0
            ? round(($website->last_user_count / $website->user_limit) * 100, 2)
            : 0;
        $data['over_user_limit'] = $website->user_limit > 0 && $website->last_user_count > $website->user_limit;
        $data['uses_default_credentials'] = $website->uses_default_credentials;

        return $data;
    }
}
