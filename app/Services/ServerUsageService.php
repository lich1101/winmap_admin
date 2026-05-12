<?php

namespace App\Services;

class ServerUsageService
{
    public function __construct(
        private readonly SetupConfigurationService $setupConfiguration,
        private readonly RemoteServerService $remoteServer,
    ) {
    }

    public function summary(?string $path = null): array
    {
        $setup = $this->setupConfiguration->current();
        if ($this->setupConfiguration->isRemoteConfigured($setup)) {
            return $this->remoteServer->serverSummary($setup);
        }

        $path = $path ?: config('winmap_admin.server_usage_path', '/');
        $resolved = realpath($path) ?: $path;

        $total = @disk_total_space($resolved);
        $free = @disk_free_space($resolved);

        if ($total === false || $free === false || $total <= 0) {
            return [
                'path' => $resolved,
                'status' => 'error',
                'error' => 'Cannot read disk_total_space/disk_free_space for this path.',
                'total_bytes' => 0,
                'used_bytes' => 0,
                'free_bytes' => 0,
                'used_percent' => 0,
                'total_human' => '0 B',
                'used_human' => '0 B',
                'free_human' => '0 B',
            ];
        }

        $used = (int) $total - (int) $free;
        $percent = round(($used / (int) $total) * 100, 2);

        return [
            'path' => $resolved,
            'status' => 'ok',
            'error' => null,
            'total_bytes' => (int) $total,
            'used_bytes' => $used,
            'free_bytes' => (int) $free,
            'used_percent' => $percent,
            'total_human' => ByteFormatter::human((int) $total),
            'used_human' => ByteFormatter::human($used),
            'free_human' => ByteFormatter::human((int) $free),
        ];
    }
}
