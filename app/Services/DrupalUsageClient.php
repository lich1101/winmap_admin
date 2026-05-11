<?php

namespace App\Services;

use App\Models\MonitoredWebsite;
use App\Models\UsageSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DrupalUsageClient
{
    public function refresh(MonitoredWebsite $website): UsageSnapshot
    {
        $checkedAt = now();

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->withHeaders($website->api_key ? ['X-Winmap-Site-Usage-Key' => $website->api_key] : [])
                ->get($website->usage_endpoint_url);

            if (! $response->successful()) {
                throw new \RuntimeException('Usage endpoint returned HTTP '.$response->status());
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new \RuntimeException('Usage endpoint did not return valid JSON.');
            }

            $sitePayload = $this->pickSitePayload($payload, $website);
            $totals = Arr::get($sitePayload, 'totals', []);
            $quota = Arr::get($sitePayload, 'quota', []);
            $diskBytes = (int) Arr::get($totals, 'disk_bytes', 0);
            $diskAllocatedBytes = Arr::get($totals, 'disk_allocated_bytes');
            $databaseBytes = (int) Arr::get($totals, 'database_bytes', 0);
            $projectBytes = (int) Arr::get($totals, 'project_bytes', $diskBytes + $databaseBytes);
            $usagePercent = $website->quota_bytes > 0 ? round(($projectBytes / $website->quota_bytes) * 100, 2) : 0;
            $isBlocked = (bool) Arr::get($quota, 'is_blocked', false);
            $isWarning = (bool) Arr::get($quota, 'is_warning', false);
            $status = $isBlocked ? 'blocked' : ($isWarning ? 'warning' : 'ok');

            $website->forceFill([
                'last_status' => $status,
                'last_error' => null,
                'last_disk_bytes' => $diskBytes,
                'last_database_bytes' => $databaseBytes,
                'last_project_bytes' => $projectBytes,
                'last_usage_percent' => $usagePercent,
                'last_is_blocked' => $isBlocked,
                'last_is_warning' => $isWarning,
                'last_checked_at' => $checkedAt,
            ])->save();

            return UsageSnapshot::create([
                'monitored_website_id' => $website->id,
                'status' => 'ok',
                'disk_bytes' => $diskBytes,
                'disk_allocated_bytes' => $diskAllocatedBytes === null ? null : (int) $diskAllocatedBytes,
                'database_bytes' => $databaseBytes,
                'project_bytes' => $projectBytes,
                'usage_percent' => $usagePercent,
                'payload' => $sitePayload,
                'checked_at' => $checkedAt,
            ]);
        } catch (Throwable $e) {
            $website->forceFill([
                'last_status' => 'error',
                'last_error' => $e->getMessage(),
                'last_checked_at' => $checkedAt,
            ])->save();

            return UsageSnapshot::create([
                'monitored_website_id' => $website->id,
                'status' => 'error',
                'error' => $e->getMessage(),
                'checked_at' => $checkedAt,
            ]);
        }
    }

    public function syncQuota(MonitoredWebsite $website): array
    {
        $endpoint = $this->configEndpointUrl($website);
        if ($endpoint === '') {
            $website->forceFill([
                'last_sync_status' => 'error',
                'last_sync_error' => 'Thiếu config endpoint để đồng bộ quota.',
            ])->save();

            throw new RuntimeException('Thiếu config endpoint để đồng bộ quota.');
        }

        $syncedAt = now();

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->withHeaders($this->headers($website))
                ->post($endpoint, [
                    'quota_bytes' => (int) $website->quota_bytes,
                    'warning_threshold_percent' => (int) $website->warning_threshold_percent,
                    'enforcement_enabled' => (bool) $website->enabled,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Quota config endpoint returned HTTP '.$response->status());
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Quota config endpoint did not return valid JSON.');
            }

            $quota = Arr::get($payload, 'quota', []);
            $website->forceFill([
                'last_sync_status' => 'ok',
                'last_sync_error' => null,
                'last_synced_at' => $syncedAt,
                'last_is_blocked' => (bool) Arr::get($quota, 'is_blocked', $website->last_is_blocked),
                'last_is_warning' => (bool) Arr::get($quota, 'is_warning', $website->last_is_warning),
            ])->save();

            return $payload;
        } catch (Throwable $e) {
            $website->forceFill([
                'last_sync_status' => 'error',
                'last_sync_error' => $e->getMessage(),
                'last_synced_at' => $syncedAt,
            ])->save();

            throw $e;
        }
    }

    private function pickSitePayload(array $payload, MonitoredWebsite $website): array
    {
        if (isset($payload['sites']) && is_array($payload['sites'])) {
            foreach ($payload['sites'] as $site) {
                $host = (string) Arr::get($site, 'site.host', '');
                $confPath = (string) Arr::get($site, 'site.conf_path', '');
                if (strcasecmp($host, $website->domain) === 0 || strcasecmp(basename($confPath), $website->domain) === 0) {
                    return $site;
                }
            }

            if (! empty($payload['sites'][0]) && is_array($payload['sites'][0])) {
                return $payload['sites'][0];
            }
        }

        return $payload;
    }

    private function headers(MonitoredWebsite $website): array
    {
        return $website->api_key
            ? ['X-Winmap-Site-Usage-Key' => $website->api_key]
            : [];
    }

    private function configEndpointUrl(MonitoredWebsite $website): string
    {
        $configured = trim((string) $website->config_endpoint_url);
        if ($configured !== '') {
            return $configured;
        }

        $usageEndpoint = trim((string) $website->usage_endpoint_url);
        if ($usageEndpoint === '') {
            return '';
        }

        return str_ends_with($usageEndpoint, '/json')
            ? substr($usageEndpoint, 0, -5).'/quota/config'
            : rtrim($usageEndpoint, '/').'/quota/config';
    }
}
