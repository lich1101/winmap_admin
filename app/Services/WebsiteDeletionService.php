<?php

namespace App\Services;

use App\Models\MonitoredWebsite;
use App\Models\User;
use App\Models\WebsiteDeletionRun;
use RuntimeException;

class WebsiteDeletionService
{
    public function __construct(
        private readonly SetupConfigurationService $setupConfiguration,
        private readonly RemoteServerService $remoteServer,
    ) {
    }

    public function createRun(MonitoredWebsite $website, User $user, string $confirmation): WebsiteDeletionRun
    {
        $setup = $this->requiredSetup();
        $domain = trim((string) $website->domain);
        if ($confirmation !== $domain) {
            throw new RuntimeException('Chuỗi xác nhận không đúng domain website cần xóa.');
        }

        if (WebsiteDeletionRun::query()
            ->where('domain', $domain)
            ->whereIn('status', ['pending', 'running', 'failed'])
            ->exists()) {
            throw new RuntimeException(sprintf(
                'Đã có deletion run chưa hoàn tất cho %s. Hãy mở lại run cũ và chạy tiếp hoặc xử lý lỗi trước.',
                $domain
            ));
        }

        [$subdomain, $parentDomain] = $this->splitDomain($domain);
        $projectPath = rtrim((string) $setup->drupal_project_path, '/');
        $systemUser = $this->systemUserFor($parentDomain);

        return WebsiteDeletionRun::create([
            'user_id' => $user->id,
            'monitored_website_id' => $website->id,
            'domain' => $domain,
            'subdomain' => $subdomain,
            'parent_domain' => $parentDomain,
            'project_path' => $projectPath,
            'system_user' => $systemUser,
            'database_name' => $subdomain,
            'status' => 'pending',
            'steps' => $this->stepDefinitions($domain, $subdomain, $parentDomain, $projectPath, $systemUser),
        ])->fresh();
    }

    public function runAll(WebsiteDeletionRun $run): WebsiteDeletionRun
    {
        foreach ($run->steps as $step) {
            if (($step['status'] ?? 'pending') !== 'success') {
                $run = $this->runStep($run, $step['key']);
                if ($run->status === 'failed') {
                    break;
                }
            }
        }

        return $run->fresh();
    }

    public function runStep(WebsiteDeletionRun $run, string $stepKey): WebsiteDeletionRun
    {
        $setup = $this->requiredSetup();
        $steps = $run->steps;
        $index = collect($steps)->search(fn (array $step) => $step['key'] === $stepKey);

        if ($index === false) {
            throw new RuntimeException('Step xóa website không tồn tại.');
        }

        $this->assertPreviousStepsCompleted($steps, (int) $index);

        $steps[$index]['status'] = 'running';
        $steps[$index]['started_at'] = now()->toISOString();
        $steps[$index]['output'] = '';

        $run->status = 'running';
        $run->current_step = $stepKey;
        $run->started_at ??= now();
        $run->last_error = null;
        $run->steps = $steps;
        $run->save();

        try {
            $result = $this->executeStep($setup, $run, $stepKey);
            $run->refresh();
            $steps = $run->steps;
            $steps[$index]['status'] = 'success';
            $steps[$index]['completed_at'] = now()->toISOString();
            $steps[$index]['output'] = trim(($result['stdout'] ?? '')."\n".($result['stderr'] ?? ''));
            $run->steps = $steps;
            $run->current_step = null;
            $run->status = collect($steps)->every(fn (array $step) => ($step['status'] ?? '') === 'success')
                ? 'completed'
                : 'pending';
            $run->completed_at = $run->status === 'completed' ? now() : null;
            $run->save();

            return $run->fresh();
        } catch (\Throwable $exception) {
            $run->refresh();
            $steps = $run->steps;
            $steps[$index]['status'] = 'failed';
            $steps[$index]['completed_at'] = now()->toISOString();
            $steps[$index]['output'] = $exception->getMessage();
            $run->steps = $steps;
            $run->status = 'failed';
            $run->current_step = $stepKey;
            $run->last_error = $exception->getMessage();
            $run->completed_at = null;
            $run->save();

            return $run->fresh();
        }
    }

    public function serializeRun(WebsiteDeletionRun $run): array
    {
        $data = $run->toArray();
        $data['steps'] = $run->steps;

        return $data;
    }

    private function executeStep($setup, WebsiteDeletionRun $run, string $stepKey): array
    {
        return match ($stepKey) {
            'remove_subdomain' => $this->remoteServer->runManagedShellScript($setup, <<<'SH'
set -eu
SUBDOMAIN="$1"
PARENT_DOMAIN="$2"
echo "Removing subdomain ${SUBDOMAIN}.${PARENT_DOMAIN}"
if plesk bin subdomain --info "$SUBDOMAIN" -domain "$PARENT_DOMAIN" >/dev/null 2>&1; then
  plesk bin subdomain --remove "$SUBDOMAIN" -domain "$PARENT_DOMAIN"
  echo "Subdomain removed."
else
  echo "Subdomain does not exist in Plesk, skip."
fi
SH, [$run->subdomain, $run->parent_domain], 60),

            'remove_directories' => $this->remoteServer->runManagedShellScript($setup, <<<'SH'
set -eu
PROJECT_PATH="$1"
FULL_DOMAIN="$2"
SYSTEM_USER="$3"
PUBLIC_TARGET="$PROJECT_PATH/sites/$FULL_DOMAIN"
PRIVATE_TARGET="$PROJECT_PATH/sites/private/$FULL_DOMAIN"

case "$PUBLIC_TARGET" in "$PROJECT_PATH"/sites/*) ;; *) echo "Unsafe public target: $PUBLIC_TARGET" >&2; exit 1 ;; esac
case "$PRIVATE_TARGET" in "$PROJECT_PATH"/sites/private/*) ;; *) echo "Unsafe private target: $PRIVATE_TARGET" >&2; exit 1 ;; esac

echo "Removing site directories for $FULL_DOMAIN"
if [ -d "$PUBLIC_TARGET" ]; then
  sudo -u "$SYSTEM_USER" rm -rf "$PUBLIC_TARGET"
  echo "Removed $PUBLIC_TARGET"
else
  echo "Public target does not exist, skip."
fi
if [ -d "$PRIVATE_TARGET" ]; then
  sudo -u "$SYSTEM_USER" rm -rf "$PRIVATE_TARGET"
  echo "Removed $PRIVATE_TARGET"
else
  echo "Private target does not exist, skip."
fi
SH, [$run->project_path, $run->domain, $run->system_user], 60),

            'remove_database' => $this->remoteServer->runManagedShellScript($setup, <<<'SH'
set -eu
DB_NAME="$1"
PARENT_DOMAIN="$2"
echo "Removing database $DB_NAME"
if plesk bin database --info "$DB_NAME" -domain "$PARENT_DOMAIN" >/dev/null 2>&1; then
  plesk bin database --remove "$DB_NAME" -domain "$PARENT_DOMAIN"
  echo "Database removed."
else
  echo "Database does not exist in Plesk, skip."
fi
SH, [$run->database_name, $run->parent_domain], 60),

            'remove_admin_record' => $this->removeAdminRecord($run),
            default => throw new RuntimeException('Step xóa website không hợp lệ.'),
        };
    }

    private function removeAdminRecord(WebsiteDeletionRun $run): array
    {
        $website = MonitoredWebsite::query()
            ->whereKey($run->monitored_website_id)
            ->orWhere('domain', $run->domain)
            ->first();

        if ($website) {
            $website->delete();

            return ['stdout' => 'Admin monitoring record removed.', 'stderr' => '', 'exit_code' => 0];
        }

        return ['stdout' => 'Admin monitoring record already removed, skip.', 'stderr' => '', 'exit_code' => 0];
    }

    private function stepDefinitions(string $domain, string $subdomain, string $parentDomain, string $projectPath, string $systemUser): array
    {
        return [
            [
                'key' => 'remove_subdomain',
                'label' => 'Xóa subdomain Plesk',
                'description' => 'Xóa subdomain khỏi Plesk.',
                'command_preview' => sprintf('plesk bin subdomain --remove %s -domain %s', $subdomain, $parentDomain),
                'status' => 'pending',
                'output' => '',
            ],
            [
                'key' => 'remove_directories',
                'label' => 'Xóa thư mục site',
                'description' => 'Xóa sites/<domain> và sites/private/<domain> được tạo từ init.',
                'command_preview' => sprintf('rm -rf %s/sites/%s %s/sites/private/%s', $projectPath, $domain, $projectPath, $domain),
                'status' => 'pending',
                'output' => '',
            ],
            [
                'key' => 'remove_database',
                'label' => 'Xóa database',
                'description' => 'Xóa database Plesk cùng tên subdomain.',
                'command_preview' => sprintf('plesk bin database --remove %s -domain %s', $subdomain, $parentDomain),
                'status' => 'pending',
                'output' => '',
            ],
            [
                'key' => 'remove_admin_record',
                'label' => 'Xóa khỏi admin',
                'description' => 'Xóa record theo dõi và usage snapshots trong Winmap Admin.',
                'command_preview' => sprintf('DELETE monitored website %s', $domain),
                'status' => 'pending',
                'output' => '',
            ],
        ];
    }

    private function assertPreviousStepsCompleted(array $steps, int $currentIndex): void
    {
        for ($index = 0; $index < $currentIndex; $index++) {
            if (($steps[$index]['status'] ?? 'pending') !== 'success') {
                throw new RuntimeException(sprintf(
                    'Cần hoàn tất step trước đó: %s.',
                    $steps[$index]['label'] ?? $steps[$index]['key'] ?? 'unknown'
                ));
            }
        }
    }

    private function splitDomain(string $domain): array
    {
        $parts = explode('.', $domain, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException('Domain không hợp lệ để suy ra subdomain và domain cha.');
        }

        return [$parts[0], $parts[1]];
    }

    private function systemUserFor(string $parentDomain): string
    {
        return 'ftp_'.$parentDomain;
    }

    private function requiredSetup()
    {
        $setup = $this->setupConfiguration->current();
        if (! $this->setupConfiguration->isRemoteConfigured($setup)) {
            throw new RuntimeException('Cần hoàn tất setup server trước khi dùng chức năng xóa website.');
        }

        return $setup;
    }
}
