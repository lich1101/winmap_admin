<?php

namespace App\Services;

use App\Models\MonitoredWebsite;
use App\Models\UsageSnapshot;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DrupalUsageClient
{
    public function __construct(
        private readonly SetupConfigurationService $setupConfiguration,
    ) {
    }

    public function refresh(MonitoredWebsite $website): UsageSnapshot
    {
        $checkedAt = now();

        try {
            $payload = $this->fetchUsagePayload($website);

            $sitePayload = $this->pickSitePayload($payload, $website);
            $quota = Arr::get($sitePayload, 'quota', []);
            $diskBytes = (int) $this->firstValue($sitePayload, [
                'totals.disk_bytes',
                'disk.total.bytes',
            ], 0);
            $diskAllocatedBytes = $this->nullableIntegerValue($this->firstValue($sitePayload, [
                'totals.disk_allocated_bytes',
                'disk.total.allocated_bytes',
            ]));
            $databaseBytes = (int) $this->firstValue($sitePayload, [
                'totals.database_bytes',
                'database.total_bytes',
            ], 0);
            $projectBytes = (int) $this->firstValue($sitePayload, [
                'totals.project_bytes',
                'quota.project_bytes',
            ], $diskBytes + $databaseBytes);
            $usagePercent = $website->quota_bytes > 0 ? round(($projectBytes / $website->quota_bytes) * 100, 2) : 0;
            $isBlocked = (bool) Arr::get($quota, 'is_blocked', false);
            $isWarning = (bool) Arr::get($quota, 'is_warning', false);
            $userCount = (int) $this->firstValue($sitePayload, [
                'quota.user_count',
                'package.user_count',
            ], 0);
            $status = $isBlocked ? 'blocked' : ($isWarning ? 'warning' : 'ok');

            $website->forceFill([
                'last_status' => $status,
                'last_error' => null,
                'last_disk_bytes' => $diskBytes,
                'last_database_bytes' => $databaseBytes,
                'last_project_bytes' => $projectBytes,
                'last_user_count' => $userCount,
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

    private function fetchUsagePayload(MonitoredWebsite $website): array
    {
        $response = $this->sendUsageRequest($website);

        try {
            if (! $response->successful()) {
                throw $this->httpErrorException($response, 'Usage endpoint');
            }

            return $this->decodeJsonResponse($response, 'Usage endpoint');
        } catch (Throwable $initialException) {
            if (! $this->shouldRetryUsageWithOAuth($website, $response)) {
                throw $initialException;
            }

            $credentials = $this->websiteCredentials($website);
            $baseUrl = $this->websiteBaseUrl($website);

            try {
                $token = $this->requestDrupalAccessToken($baseUrl, $credentials['username'], $credentials['password']);
                $authedResponse = $this->sendUsageRequest($website, $token);

                if (! $authedResponse->successful()) {
                    throw $this->httpErrorException($authedResponse, 'Usage endpoint');
                }

                return $this->decodeJsonResponse($authedResponse, 'Usage endpoint');
            } catch (Throwable $oauthException) {
                throw new RuntimeException($initialException->getMessage().' | OAuth fallback failed: '.$oauthException->getMessage(), 0, $oauthException);
            }
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
                    'storage_quota_gb' => $website->quota_bytes > 0 ? round($website->quota_bytes / 1024 / 1024 / 1024, 4) : 0,
                    'user_limit' => (int) $website->user_limit,
                    'max_accounts' => (int) $website->user_limit,
                    'warning_threshold_percent' => (int) $website->warning_threshold_percent,
                    'enforcement_enabled' => (bool) $website->enabled,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Quota config endpoint returned HTTP '.$response->status());
            }

            $payload = $this->decodeJsonResponse($response, 'Quota config endpoint');

            $packageSync = $this->syncPackageConfig($website);
            $quota = Arr::get($payload, 'quota', []);
            $website->forceFill([
                'last_sync_status' => 'ok',
                'last_sync_error' => null,
                'last_synced_at' => $syncedAt,
                'last_is_blocked' => (bool) Arr::get($quota, 'is_blocked', $website->last_is_blocked),
                'last_is_warning' => (bool) Arr::get($quota, 'is_warning', $website->last_is_warning),
                'last_user_count' => (int) Arr::get($quota, 'user_count', $website->last_user_count),
            ])->save();

            return $payload + [
                'package_config' => $packageSync,
            ];
        } catch (Throwable $e) {
            $website->forceFill([
                'last_sync_status' => 'error',
                'last_sync_error' => $e->getMessage(),
                'last_synced_at' => $syncedAt,
            ])->save();

            throw $e;
        }
    }

    public function clearCache(MonitoredWebsite $website): array
    {
        return $this->postOperation($website, 'clear_cache', 'cache/clear', 60);
    }

    public function runUpdate(MonitoredWebsite $website): array
    {
        return $this->postOperation($website, 'run_update', 'update/run', 300);
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

    private function decodeJsonResponse(Response $response, string $label): array
    {
        $payload = $response->json();
        if (is_array($payload)) {
            return $payload;
        }

        $payload = $this->decodeJsonString((string) $response->body());
        if (is_array($payload)) {
            return $payload;
        }

        $body = trim(preg_replace('/\s+/', ' ', strip_tags((string) $response->body())) ?? '');
        $snippet = $body !== '' ? substr($body, 0, 500) : 'Endpoint did not return valid JSON.';

        throw new RuntimeException($label.' did not return valid JSON: '.$snippet);
    }

    private function sendUsageRequest(MonitoredWebsite $website, ?string $bearerToken = null): Response
    {
        $request = Http::acceptJson()
            ->timeout(20);
        $headers = $this->headers($website);

        if ($bearerToken !== null && $bearerToken !== '') {
            $request = $request->withToken($bearerToken);
        } elseif ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        return $request->get($website->usage_endpoint_url);
    }

    private function decodeJsonString(string $body): ?array
    {
        $normalized = ltrim($body, "\xEF\xBB\xBF\x00\x1A \t\r\n");
        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $candidate = $this->extractJsonCandidate($normalized);
        if ($candidate === null) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function extractJsonCandidate(string $body): ?string
    {
        $starts = array_filter([
            strpos($body, '{'),
            strpos($body, '['),
        ], static fn ($value) => $value !== false);
        if ($starts === []) {
            return null;
        }

        $ends = array_filter([
            strrpos($body, '}'),
            strrpos($body, ']'),
        ], static fn ($value) => $value !== false);
        if ($ends === []) {
            return null;
        }

        $start = min($starts);
        $end = max($ends);

        if ($end < $start) {
            return null;
        }

        return substr($body, $start, $end - $start + 1);
    }

    private function firstValue(array $payload, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    private function nullableIntegerValue(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function shouldRetryUsageWithOAuth(MonitoredWebsite $website, Response $response): bool
    {
        $credentials = $this->websiteCredentials($website);
        if ($credentials['username'] === '' || $credentials['password'] === '') {
            return false;
        }

        if ($this->websiteBaseUrl($website) === '') {
            return false;
        }

        return in_array($response->status(), [401, 403], true)
            || $this->responseLooksLikeHtml($response);
    }

    private function responseLooksLikeHtml(Response $response): bool
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        $body = ltrim((string) $response->body());
        $prefix = strtolower(substr($body, 0, 500));

        return str_contains($contentType, 'text/html')
            || str_starts_with($prefix, '<!doctype html')
            || str_starts_with($prefix, '<html')
            || str_contains($prefix, '<head')
            || str_contains($prefix, '<body')
            || str_contains($prefix, 'dang nhap')
            || str_contains($prefix, 'đăng nhập')
            || str_contains($prefix, 'system.base.css')
            || str_contains($prefix, 'user/login');
    }

    private function httpErrorException(Response $response, string $label): RuntimeException
    {
        $payload = $this->decodeJsonString((string) $response->body());
        $message = trim((string) Arr::get($payload ?? [], 'message', ''));
        if ($message === '') {
            $message = trim(preg_replace('/\s+/', ' ', strip_tags((string) $response->body())) ?? '');
        }

        return new RuntimeException(
            $label.' returned HTTP '.$response->status().($message !== '' ? ': '.substr($message, 0, 500) : '')
        );
    }

    private function headers(MonitoredWebsite $website): array
    {
        $apiKey = $this->effectiveApiKey($website);

        return $apiKey !== null
            ? ['X-Winmap-Site-Usage-Key' => $apiKey]
            : [];
    }

    private function syncPackageConfig(MonitoredWebsite $website): array
    {
        $credentials = $this->websiteCredentials($website);
        if ($credentials['username'] === '' || $credentials['password'] === '') {
            return [
                'status' => 'skipped',
                'message' => 'Thiếu credential administrator nên chưa gọi /api/admin/package-config.',
            ];
        }

        $baseUrl = $this->websiteBaseUrl($website);
        if ($baseUrl === '') {
            return [
                'status' => 'skipped',
                'message' => 'Không suy ra được base URL website để gọi package-config.',
            ];
        }

        $token = $this->requestDrupalAccessToken($baseUrl, $credentials['username'], $credentials['password']);
        $storageGb = $website->quota_bytes > 0 ? round($website->quota_bytes / 1024 / 1024 / 1024, 4) : 0;

        $response = Http::acceptJson()
            ->timeout(20)
            ->withToken($token)
            ->post($baseUrl.'/api/admin/package-config', [
                'max_accounts' => (int) $website->user_limit,
                'storage_quota_gb' => $storageGb,
            ]);

        $payload = $this->decodeJsonString((string) $response->body())
            ?? ['message' => trim(strip_tags($response->body())) ?: 'Package config endpoint did not return JSON.'];

        if (! $response->successful()) {
            throw new RuntimeException('Package config endpoint returned HTTP '.$response->status().': '.Arr::get($payload, 'message', 'Unknown error'));
        }

        return [
            'status' => 'success',
            'endpoint' => $baseUrl.'/api/admin/package-config',
            'payload' => $payload,
        ];
    }

    private function requestDrupalAccessToken(string $baseUrl, string $username, string $password): string
    {
        $response = Http::acceptJson()
            ->asForm()
            ->timeout(20)
            ->post($baseUrl.'/api/password/token', [
                'grant_type' => 'password',
                'client_id' => (string) config('winmap_admin.drupal_oauth.client_id', 'primary_client'),
                'client_secret' => (string) config('winmap_admin.drupal_oauth.client_secret', ''),
                'username' => $username,
                'password' => $password,
            ]);

        $payload = $this->decodeJsonString((string) $response->body())
            ?? ['message' => trim(strip_tags($response->body())) ?: 'Token endpoint did not return JSON.'];

        if (! $response->successful()) {
            throw new RuntimeException('Token endpoint returned HTTP '.$response->status().': '.Arr::get($payload, 'message', 'Unknown error'));
        }

        $token = (string) Arr::get($payload, 'data.access_token', Arr::get($payload, 'access_token', ''));
        if ($token === '') {
            throw new RuntimeException('Token endpoint không trả access_token.');
        }

        return $token;
    }

    private function websiteCredentials(MonitoredWebsite $website): array
    {
        if (! empty($website->website_username) || ! empty($website->website_password)) {
            return [
                'username' => trim((string) $website->website_username),
                'password' => (string) $website->website_password,
            ];
        }

        $setup = $this->setupConfiguration->current();

        return [
            'username' => trim((string) $setup->default_website_username),
            'password' => (string) $setup->default_website_password,
        ];
    }

    private function websiteBaseUrl(MonitoredWebsite $website): string
    {
        foreach ([$website->usage_endpoint_url, $website->config_endpoint_url] as $endpoint) {
            $endpoint = trim((string) $endpoint);
            if ($endpoint === '') {
                continue;
            }

            $parts = parse_url($endpoint);
            if (! empty($parts['scheme']) && ! empty($parts['host'])) {
                return $parts['scheme'].'://'.$parts['host'];
            }
        }

        return $website->domain ? 'https://'.trim((string) $website->domain, '/') : '';
    }

    private function postOperation(MonitoredWebsite $website, string $operation, string $suffix, int $timeout): array
    {
        $endpoint = $this->operationEndpointUrl($website, $suffix);
        $headers = $this->headers($website);
        if ($endpoint === '') {
            throw new RuntimeException('Thiếu usage endpoint để chạy thao tác '.$operation.'.');
        }
        if ($headers === []) {
            throw new RuntimeException('Website chưa có API key nên không thể chạy thao tác quản trị từ xa.');
        }

        $response = Http::acceptJson()
            ->timeout($timeout)
            ->withHeaders($headers)
            ->post($endpoint);

        $payload = $response->json();
        if (! is_array($payload)) {
            $body = trim(strip_tags($response->body()));
            $payload = [
                'status' => 'error',
                'message' => $body !== '' ? mb_substr($body, 0, 500) : 'Endpoint did not return valid JSON.',
            ];
        }

        if (! $response->successful()) {
            $message = (string) Arr::get($payload, 'message', 'Remote operation returned HTTP '.$response->status());
            throw new RuntimeException($message);
        }

        return $payload + [
            'operation' => $operation,
            'endpoint' => $endpoint,
        ];
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

    private function operationEndpointUrl(MonitoredWebsite $website, string $suffix): string
    {
        $usageEndpoint = trim((string) $website->usage_endpoint_url);
        if ($usageEndpoint === '') {
            return '';
        }

        $base = str_ends_with($usageEndpoint, '/json')
            ? substr($usageEndpoint, 0, -5)
            : rtrim($usageEndpoint, '/');

        return rtrim($base, '/').'/'.ltrim($suffix, '/');
    }

    private function effectiveApiKey(MonitoredWebsite $website): ?string
    {
        $siteKey = trim((string) $website->api_key);
        if ($siteKey !== '') {
            return $siteKey;
        }

        $setup = $this->setupConfiguration->current();
        $defaultKey = trim((string) $setup->default_api_key);

        return $defaultKey !== '' ? $defaultKey : null;
    }
}
