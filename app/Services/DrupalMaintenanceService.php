<?php

namespace App\Services;

use App\Models\MonitoredWebsite;
use RuntimeException;

class DrupalMaintenanceService
{
    public function __construct(
        private readonly SetupConfigurationService $setupConfiguration,
        private readonly RemoteServerService $remoteServer,
    ) {
    }

    public function clearCache(MonitoredWebsite $website): array
    {
        return $this->runOperation($website, 'clear-cache');
    }

    public function runUpdate(MonitoredWebsite $website): array
    {
        return $this->runOperation($website, 'run-update');
    }

    private function runOperation(MonitoredWebsite $website, string $operation): array
    {
        $setup = $this->setupConfiguration->current();
        $projectPath = $this->normalizeProjectPath($this->resolveProjectPath($website, $setup->drupal_project_path));
        $siteUri = $this->resolveSiteUri($website, $setup->drupal_site_scheme ?: 'https');
        $startedAt = microtime(true);

        $result = match ($operation) {
            'clear-cache' => $this->remoteServer->runManagedShellScript(
                $setup,
                $this->clearCacheScript(),
                [$projectPath, $siteUri],
                180
            ),
            'run-update' => $this->remoteServer->runManagedShellScript(
                $setup,
                $this->runUpdateScript(),
                [$projectPath, $siteUri],
                600
            ),
            default => throw new RuntimeException('Thao tác bảo trì không được hỗ trợ.'),
        };

        $stdout = trim((string) ($result['stdout'] ?? ''));
        $stderr = trim((string) ($result['stderr'] ?? ''));
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'status' => 'success',
            'operation' => $operation,
            'domain' => $website->domain,
            'site_uri' => $siteUri,
            'project_path' => $projectPath,
            'message' => $this->operationMessage($operation, $website->domain),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'duration_ms' => $durationMs,
            'exit_code' => $result['exit_code'] ?? 0,
        ];
    }

    private function resolveProjectPath(MonitoredWebsite $website, ?string $defaultProjectPath): string
    {
        $projectPath = trim((string) ($website->discovery_root ?: $defaultProjectPath));

        if ($projectPath === '') {
            throw new RuntimeException("Website {$website->domain} chưa có discovery_root hoặc path Drupal mặc định.");
        }

        return rtrim($projectPath, '/');
    }

    private function normalizeProjectPath(string $projectPath): string
    {
        $projectPath = rtrim(trim($projectPath), '/');

        if ($projectPath === '') {
            return '';
        }

        if (basename($projectPath) === 'settings.php') {
            $projectPath = dirname($projectPath);
        }

        if (basename($projectPath) === 'sites') {
            return rtrim(dirname($projectPath), '/');
        }

        if (basename(dirname($projectPath)) === 'sites') {
            return rtrim(dirname(dirname($projectPath)), '/');
        }

        return $projectPath;
    }

    private function resolveSiteUri(MonitoredWebsite $website, string $defaultScheme): string
    {
        foreach ([$website->usage_endpoint_url, $website->config_endpoint_url] as $endpoint) {
            $endpoint = trim((string) $endpoint);
            if ($endpoint === '') {
                continue;
            }

            $parts = parse_url($endpoint);
            if (! empty($parts['scheme']) && ! empty($parts['host'])) {
                $port = ! empty($parts['port']) ? ':'.$parts['port'] : '';

                return $parts['scheme'].'://'.$parts['host'].$port;
            }
        }

        $domain = trim((string) $website->domain);
        if ($domain === '') {
            throw new RuntimeException('Thiếu domain website để chạy drush --uri.');
        }

        $scheme = trim($defaultScheme);

        return ($scheme !== '' ? $scheme : 'https').'://'.$domain;
    }

    private function operationMessage(string $operation, string $domain): string
    {
        return match ($operation) {
            'clear-cache' => "Đã clear cache {$domain} qua SSH/drush.",
            'run-update' => "Đã chạy updb + clear cache {$domain} qua SSH/drush.",
            default => "Đã chạy thao tác {$operation} cho {$domain}.",
        };
    }

    private function drushShellBootstrap(): string
    {
        return <<<'SH'
resolve_php() {
  for candidate in \
    /opt/plesk/php/7.4/bin/php \
    /opt/plesk/php/8.0/bin/php \
    /opt/plesk/php/8.1/bin/php \
    /opt/plesk/php/8.2/bin/php \
    /opt/plesk/php/8.3/bin/php \
    /opt/plesk/php/7.3/bin/php \
    /opt/plesk/php/7.2/bin/php \
    /usr/local/bin/php \
    /usr/bin/php
  do
    if [ -x "$candidate" ]; then
      version="$("$candidate" -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)"
      if [ "$version" -ge 70205 ]; then
        printf '%s\n' "$candidate"
        return 0
      fi
    fi
  done

  return 1
}

resolve_drush() {
  for candidate in \
    /opt/plesk/php/7.4/bin/drush \
    /opt/plesk/php/8.0/bin/drush \
    /opt/plesk/php/8.1/bin/drush \
    /opt/plesk/php/8.2/bin/drush \
    /opt/plesk/php/8.3/bin/drush \
    /usr/local/bin/drush \
    /usr/bin/drush \
    /root/.composer/vendor/bin/drush \
    /var/www/vhosts/.composer/vendor/bin/drush
  do
    if [ -x "$candidate" ] || [ -f "$candidate" ]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done

  if command -v drush >/dev/null 2>&1; then
    command -v drush
    return 0
  fi

  return 1
}

run_drush() {
  PHP_BIN="$(resolve_php)" || {
    echo "Không tìm thấy PHP >= 7.2.5 để chạy drush." >&2
    exit 3
  }

  DRUSH_BIN="$(resolve_drush)" || {
    echo "Không tìm thấy drush trên server." >&2
    exit 3
  }

  # Drush/Composer có thể gọi lại "php" từ PATH; ưu tiên PHP đã chọn (tránh /usr/bin/php 5.4).
  export PATH="$(dirname "$PHP_BIN"):$PATH"

  "$PHP_BIN" "$DRUSH_BIN" "$@"
}
SH;
    }

    private function clearCacheScript(): string
    {
        return $this->drushShellBootstrap().<<<'SH'

set -eu
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
ROOT="$1"
SITE_URI="$2"

if [ ! -d "$ROOT" ]; then
  echo "Drupal root does not exist: $ROOT" >&2
  exit 2
fi

cd "$ROOT"
echo "Processing $SITE_URI"
run_drush -y cc all --root="$ROOT" --uri="$SITE_URI"
echo "Cache cleared for $SITE_URI"
SH;
    }

    private function runUpdateScript(): string
    {
        return $this->drushShellBootstrap().<<<'SH'

set -eu
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
ROOT="$1"
SITE_URI="$2"

if [ ! -d "$ROOT" ]; then
  echo "Drupal root does not exist: $ROOT" >&2
  exit 2
fi

cd "$ROOT"
echo "Processing $SITE_URI"
run_drush -y updb --root="$ROOT" --uri="$SITE_URI"
echo "Database updated for $SITE_URI"
run_drush -y cc all --root="$ROOT" --uri="$SITE_URI"
echo "Cache cleared for $SITE_URI"
SH;
    }
}
