<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoredWebsite;
use App\Services\ByteFormatter;
use App\Services\DrupalSiteDiscoveryService;
use App\Services\DrupalUsageClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MonitoredWebsiteController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => MonitoredWebsite::query()
                ->orderBy('domain')
                ->get()
                ->map(fn (MonitoredWebsite $website) => $this->decorate($website)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $website = MonitoredWebsite::create($data);

        return $this->syncAfterSave($website, app(DrupalUsageClient::class), 201);
    }

    public function show(MonitoredWebsite $website): JsonResponse
    {
        return response()->json([
            'data' => $this->decorate($website),
            'snapshots' => $website->snapshots()->latest('checked_at')->limit(30)->get(),
        ]);
    }

    public function update(Request $request, MonitoredWebsite $website): JsonResponse
    {
        $data = $this->validated($request, $website);
        if (! array_key_exists('api_key', $data)) {
            unset($data['api_key']);
        }
        if (($data['api_key'] ?? null) === '__keep__') {
            unset($data['api_key']);
        }

        $website->update($data);

        return $this->syncAfterSave($website->refresh(), app(DrupalUsageClient::class));
    }

    public function destroy(MonitoredWebsite $website): JsonResponse
    {
        $website->delete();

        return response()->json(['status' => 'ok']);
    }

    public function refresh(MonitoredWebsite $website, DrupalUsageClient $client): JsonResponse
    {
        $snapshot = $client->refresh($website);

        return response()->json([
            'website' => $this->decorate($website->refresh()),
            'snapshot' => $snapshot,
        ]);
    }

    public function discovery(DrupalSiteDiscoveryService $discovery): JsonResponse
    {
        return response()->json([
            'data' => $discovery->discover(),
        ]);
    }

    public function syncDiscovery(DrupalSiteDiscoveryService $discovery): JsonResponse
    {
        $result = $discovery->sync();

        return response()->json([
            'message' => sprintf(
                'Đã quét %d website, tạo mới %d và cập nhật %d.',
                $result['count'],
                $result['created'],
                $result['updated']
            ),
            'summary' => [
                'count' => $result['count'],
                'created' => $result['created'],
                'updated' => $result['updated'],
            ],
            'data' => collect($result['data'])->map(fn (MonitoredWebsite $website) => $this->decorate($website))->values(),
        ]);
    }

    private function validated(Request $request, ?MonitoredWebsite $website = null): array
    {
        $id = $website?->id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', Rule::unique('monitored_websites', 'domain')->ignore($id)],
            'usage_endpoint_url' => ['required', 'url', 'max:2048'],
            'config_endpoint_url' => ['nullable', 'url', 'max:2048'],
            'api_key' => ['nullable', 'string', 'max:2048'],
            'website_username' => ['nullable', 'string', 'max:255'],
            'website_password' => ['nullable', 'string', 'max:2000'],
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
            'quota_gb' => ['nullable', 'numeric', 'min:0'],
            'warning_threshold_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'enabled' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (isset($data['quota_gb'])) {
            $data['quota_bytes'] = (int) round(((float) $data['quota_gb']) * 1024 * 1024 * 1024);
            unset($data['quota_gb']);
        }

        $data['quota_bytes'] = $data['quota_bytes'] ?? 0;
        $data['enabled'] = $data['enabled'] ?? true;
        $data['warning_threshold_percent'] = (int) ($data['warning_threshold_percent'] ?? ($website?->warning_threshold_percent ?? 85));

        if (($data['api_key'] ?? '') === '') {
            unset($data['api_key']);
        }

        if (($data['website_password'] ?? '') === '') {
            unset($data['website_password']);
        }

        if (($data['config_endpoint_url'] ?? '') === '' && ! empty($data['usage_endpoint_url'])) {
            $data['config_endpoint_url'] = str_ends_with($data['usage_endpoint_url'], '/json')
                ? substr($data['usage_endpoint_url'], 0, -5).'/quota/config'
                : rtrim($data['usage_endpoint_url'], '/').'/quota/config';
        }

        return $data;
    }

    private function decorate(MonitoredWebsite $website): array
    {
        $data = $website->toArray();
        $data['quota_human'] = ByteFormatter::human($website->quota_bytes);
        $data['last_disk_human'] = ByteFormatter::human($website->last_disk_bytes);
        $data['last_database_human'] = ByteFormatter::human($website->last_database_bytes);
        $data['last_project_human'] = ByteFormatter::human($website->last_project_bytes);
        $data['over_quota'] = $website->last_is_blocked || ($website->quota_bytes > 0 && $website->last_project_bytes > $website->quota_bytes);
        $data['has_website_password'] = $website->has_website_password;

        return $data;
    }

    private function syncAfterSave(MonitoredWebsite $website, DrupalUsageClient $client, int $successStatus = 200): JsonResponse
    {
        try {
            $sync = $client->syncQuota($website);

            return response()->json([
                'data' => $this->decorate($website->refresh()),
                'sync' => $sync,
            ], $successStatus);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Đã lưu cấu hình trong admin nhưng chưa đồng bộ quota xuống website: '.$e->getMessage(),
                'data' => $this->decorate($website->refresh()),
            ], 422);
        }
    }
}
