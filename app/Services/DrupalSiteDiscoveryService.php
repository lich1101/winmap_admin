<?php

namespace App\Services;

use App\Models\MonitoredWebsite;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DrupalSiteDiscoveryService
{
    public function __construct(
        private readonly SetupConfigurationService $setupConfiguration,
        private readonly RemoteServerService $remoteServer,
    ) {
    }

    public function discover(): array
    {
        $setup = $this->setupConfiguration->current();
        if ($this->setupConfiguration->isRemoteConfigured($setup)) {
            return $this->remoteServer->discoverSites($setup);
        }

        $sites = [];

        foreach ($this->roots() as $root) {
            foreach ($this->discoverRoot($root) as $site) {
                $sites[$site['domain']] = $site;
            }
        }

        ksort($sites, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($sites);
    }

    public function sync(): array
    {
        $created = 0;
        $updated = 0;
        $records = [];

        foreach ($this->discover() as $site) {
            $website = MonitoredWebsite::query()->firstOrNew([
                'domain' => $site['domain'],
            ]);

            $isNew = ! $website->exists;
            $website->name = $this->resolvedName($website, $site);
            $website->usage_endpoint_url = $site['usage_endpoint_url'];
            $website->config_endpoint_url = $site['config_endpoint_url'];
            $website->discovery_root = $site['discovery_root'];
            $website->discovery_conf_path = $site['discovery_conf_path'];
            $website->enabled = $website->exists ? $website->enabled : true;
            $website->quota_bytes = $website->exists ? $website->quota_bytes : 0;
            $website->warning_threshold_percent = $website->exists
                ? $website->warning_threshold_percent
                : (int) config('winmap_admin.discovery.default_warning_threshold_percent', 85);

            if (! $website->exists && empty($website->notes)) {
                $website->notes = sprintf(
                    'Tự động quét từ %s (%s).',
                    $site['discovery_root'],
                    $site['discovery_conf_path']
                );
            }

            $website->save();

            $records[] = $website->fresh();
            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'count' => count($records),
            'data' => $records,
        ];
    }

    private function discoverRoot(string $root): array
    {
        $sitesRoot = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sites';
        if (! is_dir($sitesRoot)) {
            return [];
        }

        $aliases = $this->siteAliases($root);
        $discovered = [];

        foreach (glob($sitesRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            if (in_array($name, ['all', 'private', 'default'], true)) {
                continue;
            }

            if (! is_file($directory.DIRECTORY_SEPARATOR.'settings.php')) {
                continue;
            }

            $hosts = array_values(array_unique(array_filter(array_merge(
                Arr::get($aliases, $name, []),
                [$name]
            ))));
            $primaryHost = $this->primaryHost($name, $hosts);

            if ($primaryHost === '') {
                continue;
            }

            $scheme = trim((string) config('winmap_admin.discovery.default_scheme', 'https'));
            $discovered[] = [
                'name' => $primaryHost,
                'domain' => $primaryHost,
                'hosts' => $hosts,
                'usage_endpoint_url' => sprintf('%s://%s/application/site-usage/json', $scheme, $primaryHost),
                'config_endpoint_url' => sprintf('%s://%s/application/site-usage/quota/config', $scheme, $primaryHost),
                'discovery_root' => $root,
                'discovery_conf_path' => 'sites/'.$name,
            ];
        }

        usort($discovered, static fn (array $a, array $b): int => strcasecmp($a['domain'], $b['domain']));

        return $discovered;
    }

    private function siteAliases(string $root): array
    {
        $file = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sites'.DIRECTORY_SEPARATOR.'sites.php';
        if (! is_readable($file)) {
            return [];
        }

        $sites = [];
        include $file;

        $aliases = [];
        foreach ($sites as $host => $directory) {
            $directory = trim((string) $directory, '/');
            if (Str::startsWith($directory, 'sites/')) {
                $directory = Str::after($directory, 'sites/');
            }

            if ($directory === '') {
                continue;
            }

            $aliases[$directory][] = (string) $host;
        }

        return $aliases;
    }

    private function primaryHost(string $directoryName, array $hosts): string
    {
        foreach ($hosts as $host) {
            if (str_contains($host, '.')) {
                return $host;
            }
        }

        return str_contains($directoryName, '.') ? $directoryName : '';
    }

    private function resolvedName(MonitoredWebsite $website, array $site): string
    {
        if (! $website->exists) {
            return $site['name'];
        }

        if (blank($website->name) || $website->name === $website->domain) {
            return $site['name'];
        }

        return $website->name;
    }

    private function roots(): array
    {
        $configured = config('winmap_admin.discovery.roots', []);

        return array_values(array_filter(array_unique(array_map(
            static fn ($path) => rtrim((string) $path, DIRECTORY_SEPARATOR),
            is_array($configured) ? $configured : []
        ))));
    }
}
