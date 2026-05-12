<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoredWebsite;
use App\Services\RemoteServerService;
use App\Services\SetupConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Throwable;

class SetupController extends Controller
{
    public function status(SetupConfigurationService $setupService): JsonResponse
    {
        $setup = $setupService->current();

        return response()->json([
            'completed' => (bool) $setup->is_completed,
            'config' => $this->serializeConfig($setup),
            'websites' => MonitoredWebsite::query()
                ->orderBy('domain')
                ->get()
                ->map(fn (MonitoredWebsite $website) => $this->serializeWebsite($website))
                ->values(),
        ]);
    }

    public function discover(
        Request $request,
        SetupConfigurationService $setupService,
        RemoteServerService $remoteServer,
    ): JsonResponse {
        $data = $this->validatedServerConfig($request, $setupService);
        $setup = $setupService->preview($data);

        try {
            $sites = $remoteServer->discoverSites($setup);
            $server = $remoteServer->serverSummary($setup);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'server_host' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'config' => $this->serializeConfig($setup),
            'server' => $server,
            'sites' => collect($sites)->map(function (array $site): array {
                $existing = MonitoredWebsite::query()->where('domain', $site['domain'])->first();

                return array_merge($site, [
                    'website_username' => $existing?->website_username,
                    'has_website_password' => (bool) ($existing?->has_website_password),
                    'enabled' => $existing?->enabled ?? true,
                    'warning_threshold_percent' => $existing?->warning_threshold_percent ?? 85,
                    'quota_bytes' => $existing?->quota_bytes ?? 0,
                ]);
            })->values(),
        ]);
    }

    public function complete(
        Request $request,
        SetupConfigurationService $setupService,
    ): JsonResponse {
        $serverData = $this->validatedServerConfig($request, $setupService);
        $payload = $request->validate([
            'auth_site_domain' => ['required', 'string', 'max:255'],
            'websites' => ['required', 'array', 'min:1'],
            'websites.*.name' => ['required', 'string', 'max:255'],
            'websites.*.domain' => ['required', 'string', 'max:255'],
            'websites.*.usage_endpoint_url' => ['required', 'url', 'max:2048'],
            'websites.*.config_endpoint_url' => ['nullable', 'url', 'max:2048'],
            'websites.*.discovery_root' => ['nullable', 'string', 'max:1000'],
            'websites.*.discovery_conf_path' => ['nullable', 'string', 'max:1000'],
            'websites.*.website_username' => ['required', 'string', 'max:255'],
            'websites.*.website_password' => ['nullable', 'string', 'max:2000'],
            'websites.*.enabled' => ['nullable', 'boolean'],
            'websites.*.warning_threshold_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'websites.*.quota_bytes' => ['nullable', 'integer', 'min:0'],
            'websites.*.quota_gb' => ['nullable', 'numeric', 'min:0'],
        ]);

        $domains = collect($payload['websites'])->pluck('domain')->all();
        if (! in_array($payload['auth_site_domain'], $domains, true)) {
            abort(422, 'Website xác thực administrator phải nằm trong danh sách multisite vừa quét.');
        }

        $setup = DB::transaction(function () use ($setupService, $serverData, $payload) {
            $setup = $setupService->persist(array_merge($serverData, [
                'auth_site_domain' => $payload['auth_site_domain'],
            ]), true);

            foreach ($payload['websites'] as $websiteInput) {
                $website = MonitoredWebsite::query()->firstOrNew([
                    'domain' => $websiteInput['domain'],
                ]);

                $password = trim((string) ($websiteInput['website_password'] ?? ''));
                if ($password === '' && ! $website->exists) {
                    abort(422, sprintf('Thiếu mật khẩu truy cập cho website %s.', $websiteInput['domain']));
                }

                $quotaBytes = array_key_exists('quota_gb', $websiteInput) && $websiteInput['quota_gb'] !== null && $websiteInput['quota_gb'] !== ''
                    ? (int) round(((float) $websiteInput['quota_gb']) * 1024 * 1024 * 1024)
                    : (int) ($websiteInput['quota_bytes'] ?? ($website->quota_bytes ?? 0));

                $website->name = $websiteInput['name'];
                $website->usage_endpoint_url = $websiteInput['usage_endpoint_url'];
                $website->config_endpoint_url = $websiteInput['config_endpoint_url']
                    ?: $this->derivedConfigEndpoint($websiteInput['usage_endpoint_url']);
                $website->website_username = $websiteInput['website_username'];
                if ($password !== '') {
                    $website->website_password = $password;
                }
                $website->quota_bytes = $quotaBytes;
                $website->enabled = (bool) ($websiteInput['enabled'] ?? ($website->enabled ?? true));
                $website->warning_threshold_percent = (int) ($websiteInput['warning_threshold_percent'] ?? ($website->warning_threshold_percent ?? 85));
                $website->discovery_root = $websiteInput['discovery_root'] ?? $setup->drupal_project_path;
                $website->discovery_conf_path = $websiteInput['discovery_conf_path'] ?? null;
                if (! $website->exists && blank($website->notes)) {
                    $website->notes = sprintf(
                        'Khởi tạo từ setup wizard (%s).',
                        $setup->drupal_project_path
                    );
                }
                $website->save();
            }

            return $setup;
        });

        return response()->json([
            'message' => 'Đã lưu cấu hình server, multisite và credential website.',
            'completed' => true,
            'config' => $this->serializeConfig($setup),
        ]);
    }

    private function validatedServerConfig(Request $request, SetupConfigurationService $setupService): array
    {
        $current = $setupService->current();

        $data = $request->validate([
            'server_host' => ['required', 'string', 'max:255'],
            'server_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'server_username' => ['required', 'string', 'max:255'],
            'server_password' => ['nullable', 'string', 'max:2000'],
            'drupal_project_path' => ['required', 'string', 'max:1000'],
            'drupal_site_scheme' => ['nullable', Rule::in(['http', 'https'])],
        ]);

        if (($data['server_password'] ?? '') === '' && ! $current->has_server_password) {
            abort(422, 'Mật khẩu server là bắt buộc ở lần setup đầu tiên.');
        }

        $data['drupal_site_scheme'] = $data['drupal_site_scheme'] ?? ($current->drupal_site_scheme ?: 'https');

        return $data;
    }

    private function serializeConfig($setup): array
    {
        return [
            'is_completed' => (bool) $setup->is_completed,
            'server_host' => $setup->server_host,
            'server_port' => (int) ($setup->server_port ?: 22),
            'server_username' => $setup->server_username,
            'has_server_password' => (bool) $setup->has_server_password,
            'drupal_project_path' => $setup->drupal_project_path,
            'drupal_site_scheme' => $setup->drupal_site_scheme ?: 'https',
            'auth_site_domain' => $setup->auth_site_domain,
        ];
    }

    private function serializeWebsite(MonitoredWebsite $website): array
    {
        return [
            'id' => $website->id,
            'name' => $website->name,
            'domain' => $website->domain,
            'usage_endpoint_url' => $website->usage_endpoint_url,
            'config_endpoint_url' => $website->config_endpoint_url,
            'website_username' => $website->website_username,
            'has_website_password' => $website->has_website_password,
            'enabled' => $website->enabled,
            'warning_threshold_percent' => $website->warning_threshold_percent,
            'quota_bytes' => $website->quota_bytes,
            'discovery_root' => $website->discovery_root,
            'discovery_conf_path' => $website->discovery_conf_path,
        ];
    }

    private function derivedConfigEndpoint(string $usageEndpoint): string
    {
        return str_ends_with($usageEndpoint, '/json')
            ? substr($usageEndpoint, 0, -5).'/quota/config'
            : rtrim($usageEndpoint, '/').'/quota/config';
    }
}
