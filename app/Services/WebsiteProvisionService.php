<?php

namespace App\Services;

use App\Models\MonitoredWebsite;
use App\Models\User;
use App\Models\WebsiteProvisionRun;
use RuntimeException;

class WebsiteProvisionService
{
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly SetupConfigurationService $setupConfiguration,
        private readonly RemoteServerService $remoteServer,
    ) {
    }

    public function recentRuns(int $limit = 12)
    {
        return WebsiteProvisionRun::query()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (WebsiteProvisionRun $run) => $this->serializeRun($run))
            ->values();
    }

    public function createRun(array $data, User $user): WebsiteProvisionRun
    {
        $setup = $this->requiredSetup();

        $subdomain = $this->normalizeSubdomain($data['subdomain'] ?? '');
        $parentDomain = trim((string) ($data['parent_domain'] ?? $this->defaultParentDomain($setup)));
        if ($parentDomain === '') {
            throw new RuntimeException('Không suy ra được domain cha. Hãy điền domain cha khi khởi tạo website.');
        }

        $fullDomain = $subdomain.'.'.$parentDomain;
        if (WebsiteProvisionRun::query()
            ->where('full_domain', $fullDomain)
            ->whereIn('status', ['pending', 'running', 'failed'])
            ->exists()) {
            throw new RuntimeException(sprintf(
                'Đã có provisioning run chưa hoàn tất cho %s. Hãy mở lại run cũ và chạy tiếp các bước còn thiếu.',
                $fullDomain
            ));
        }

        $projectPath = rtrim((string) $setup->drupal_project_path, '/');
        $wwwRoot = trim((string) ($data['www_root'] ?? basename($projectPath)));
        $systemUser = trim((string) ($data['system_user'] ?? ('ftp_'.$parentDomain)));
        $sourceDatabase = trim((string) ($data['source_database'] ?? ''));
        $mysqlPasswordFile = trim((string) ($data['mysql_password_file'] ?? '/root/.mysql_pass'));
        $sslRegistrationEmail = trim((string) ($data['ssl_registration_email'] ?? ('admin@'.$parentDomain)));
        $websiteUsername = trim((string) ($data['website_username'] ?? ''));
        $websitePassword = (string) ($data['website_password'] ?? '');

        if ($sourceDatabase === '') {
            throw new RuntimeException('Source database là bắt buộc để clone database khi tạo website.');
        }

        $steps = $this->stepDefinitions($subdomain, $parentDomain, $fullDomain, $wwwRoot, $systemUser, $sourceDatabase, $mysqlPasswordFile, $sslRegistrationEmail);

        $run = WebsiteProvisionRun::create([
            'user_id' => $user->id,
            'subdomain' => $subdomain,
            'parent_domain' => $parentDomain,
            'full_domain' => $fullDomain,
            'www_root' => $wwwRoot,
            'system_user' => $systemUser,
            'source_database' => $sourceDatabase,
            'mysql_password_file' => $mysqlPasswordFile,
            'ssl_registration_email' => $sslRegistrationEmail,
            'website_username' => $websiteUsername ?: null,
            'website_password' => $websitePassword !== '' ? $websitePassword : null,
            'status' => 'pending',
            'steps' => $steps,
        ]);

        return $run->fresh();
    }

    public function runAll(WebsiteProvisionRun $run): WebsiteProvisionRun
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

    public function runStep(WebsiteProvisionRun $run, string $stepKey): WebsiteProvisionRun
    {
        $setup = $this->requiredSetup();
        $steps = $run->steps;
        $index = collect($steps)->search(fn (array $step) => $step['key'] === $stepKey);

        if ($index === false) {
            throw new RuntimeException('Step provisioning không tồn tại.');
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

            if ($run->status === 'completed') {
                $this->upsertMonitoredWebsite($run, $setup);
            }

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

    public function serializeRun(WebsiteProvisionRun $run): array
    {
        $data = $run->toArray();
        $data['steps'] = $run->steps;
        $data['has_website_password'] = $run->has_website_password;

        return $data;
    }

    public function defaultCreatePayload(): array
    {
        $setup = $this->requiredSetup();
        $parentDomain = $this->defaultParentDomain($setup);

        return [
            'parent_domain' => $parentDomain,
            'www_root' => basename(rtrim((string) $setup->drupal_project_path, '/')),
            'system_user' => $parentDomain !== '' ? 'ftp_'.$parentDomain : '',
            'source_database' => '',
            'mysql_password_file' => '/root/.mysql_pass',
            'ssl_registration_email' => $parentDomain !== '' ? 'admin@'.$parentDomain : '',
            'website_username' => '',
        ];
    }

    private function executeStep($setup, WebsiteProvisionRun $run, string $stepKey): array
    {
        return match ($stepKey) {
            'create_subdomain' => $this->remoteServer->runManagedShellScript($setup, <<<'SH'
set -eu
SUBDOMAIN="$1"
PARENT_DOMAIN="$2"
WWW_ROOT="$3"
echo "Creating subdomain ${SUBDOMAIN}.${PARENT_DOMAIN}"
plesk bin subdomain --create "$SUBDOMAIN" -domain "$PARENT_DOMAIN" -www-root "$WWW_ROOT"
echo "Subdomain created."
SH, [$run->subdomain, $run->parent_domain, $run->www_root], 45),

            'install_ssl' => $this->remoteServer->runManagedShellScript($setup, <<<'SH'
set -eu
FULL_DOMAIN="$1"
REG_EMAIL="$2"
MAX_RETRIES="$3"
attempt=1
while [ "$attempt" -le "$MAX_RETRIES" ]; do
  echo "Attempt $attempt/$MAX_RETRIES"
  if plesk ext sslit --certificate -issue -domain "$FULL_DOMAIN" -registrationEmail "$REG_EMAIL" -secure-domain -secure-www; then
    echo "SSL installed."
    exit 0
  fi
  if [ "$attempt" -ge "$MAX_RETRIES" ]; then
    echo "Error: Install SSL failed after $MAX_RETRIES attempts." >&2
    exit 1
  fi
  echo "Install SSL failed! Retrying in 5 seconds..."
  sleep 5
  attempt=$((attempt + 1))
done
SH, [$run->full_domain, $run->ssl_registration_email, (string) self::MAX_RETRIES], 120),

            'copy_directories' => $this->remoteServer->runManagedShellScript($setup, <<<'SH'
set -eu
PROJECT_PATH="$1"
FULL_DOMAIN="$2"
SYSTEM_USER="$3"
PUBLIC_INIT="$PROJECT_PATH/sites/init"
PUBLIC_TARGET="$PROJECT_PATH/sites/$FULL_DOMAIN"
PRIVATE_INIT="$PROJECT_PATH/sites/private/init"
PRIVATE_TARGET="$PROJECT_PATH/sites/private/$FULL_DOMAIN"
echo "Copying site directories for $FULL_DOMAIN"
[ -d "$PUBLIC_INIT" ] || { echo "Missing $PUBLIC_INIT" >&2; exit 1; }
[ -d "$PRIVATE_INIT" ] || { echo "Missing $PRIVATE_INIT" >&2; exit 1; }
if [ ! -d "$PUBLIC_TARGET" ]; then
  sudo -u "$SYSTEM_USER" cp -r "$PUBLIC_INIT" "$PUBLIC_TARGET"
else
  echo "Public target already exists, skip copy."
fi
if [ ! -d "$PRIVATE_TARGET" ]; then
  sudo -u "$SYSTEM_USER" cp -r "$PRIVATE_INIT" "$PRIVATE_TARGET"
else
  echo "Private target already exists, skip copy."
fi
echo "Directories copied."
SH, [$setup->drupal_project_path, $run->full_domain, $run->system_user], 60),

            'modify_settings' => $this->remoteServer->runManagedShellScript($setup, <<<'SH'
set -eu
PROJECT_PATH="$1"
FULL_DOMAIN="$2"
DB_NAME="$3"
SYSTEM_USER="$4"
SETTINGS_FILE="$PROJECT_PATH/sites/$FULL_DOMAIN/settings.php"
[ -f "$SETTINGS_FILE" ] || { echo "Missing settings file: $SETTINGS_FILE" >&2; exit 1; }
echo "Updating settings.php for $FULL_DOMAIN"
sudo -u "$SYSTEM_USER" sed -i "s/database_name_place_holder/$DB_NAME/g; s/domain_name_place_holder/$FULL_DOMAIN/g;" "$SETTINGS_FILE"
echo "settings.php updated."
SH, [$setup->drupal_project_path, $run->full_domain, $run->subdomain, $run->system_user], 45),

            'create_and_clone_database' => $this->remoteServer->runManagedShellScript($setup, <<<'SH'
set -eu
DB_NAME="$1"
PARENT_DOMAIN="$2"
SOURCE_DB="$3"
MYSQL_PASS_FILE="$4"
MAX_RETRIES="$5"
[ -f "$MYSQL_PASS_FILE" ] || { echo "Missing MySQL root password file: $MYSQL_PASS_FILE" >&2; exit 1; }
ROOT_PASSWORD="$(cat "$MYSQL_PASS_FILE")"
attempt=1
while [ "$attempt" -le "$MAX_RETRIES" ]; do
  echo "Attempt $attempt/$MAX_RETRIES"
  if plesk bin database --create "$DB_NAME" -domain "$PARENT_DOMAIN" -type mysql; then
    echo "Plesk database created."
  else
    echo "Plesk database create returned non-zero, continue to import if database already exists."
  fi
  if mysqldump -u root --password="$ROOT_PASSWORD" "$SOURCE_DB" | mysql -u root --password="$ROOT_PASSWORD" "$DB_NAME"; then
    echo "Database cloned from $SOURCE_DB to $DB_NAME."
    exit 0
  fi
  if [ "$attempt" -ge "$MAX_RETRIES" ]; then
    echo "Error: Database provisioning failed after $MAX_RETRIES attempts." >&2
    exit 1
  fi
  echo "Database provisioning failed! Retrying in 5 seconds..."
  sleep 5
  attempt=$((attempt + 1))
done
SH, [$run->subdomain, $run->parent_domain, $run->source_database, $run->mysql_password_file, (string) self::MAX_RETRIES], 180),

            default => throw new RuntimeException('Step provisioning không hợp lệ.'),
        };
    }

    private function stepDefinitions(
        string $subdomain,
        string $parentDomain,
        string $fullDomain,
        string $wwwRoot,
        string $systemUser,
        string $sourceDatabase,
        string $mysqlPasswordFile,
        string $sslRegistrationEmail,
    ): array {
        return [
            [
                'key' => 'create_subdomain',
                'label' => 'Tạo subdomain Plesk',
                'description' => 'Tạo subdomain mới và trỏ vào www root của project.',
                'command_preview' => sprintf('plesk bin subdomain --create %s -domain %s -www-root %s', $subdomain, $parentDomain, $wwwRoot),
                'status' => 'pending',
                'output' => '',
            ],
            [
                'key' => 'install_ssl',
                'label' => 'Cấp SSL',
                'description' => 'Issue SSL cho website mới với retry.',
                'command_preview' => sprintf('plesk ext sslit --certificate -issue -domain %s -registrationEmail %s -secure-domain -secure-www', $fullDomain, $sslRegistrationEmail),
                'status' => 'pending',
                'output' => '',
            ],
            [
                'key' => 'copy_directories',
                'label' => 'Copy sites/init',
                'description' => 'Tạo site folder public/private từ bộ khởi tạo init.',
                'command_preview' => sprintf('cp -r %s/sites/init %s/sites/%s && cp -r %s/sites/private/init %s/sites/private/%s', '{project}', '{project}', $fullDomain, '{project}', '{project}', $fullDomain),
                'status' => 'pending',
                'output' => '',
            ],
            [
                'key' => 'modify_settings',
                'label' => 'Sửa settings.php',
                'description' => 'Thay placeholder database và domain trong settings.php.',
                'command_preview' => sprintf('sed -i "s/database_name_place_holder/%s/g; s/domain_name_place_holder/%s/g;" {project}/sites/%s/settings.php', $subdomain, $fullDomain, $fullDomain),
                'status' => 'pending',
                'output' => '',
            ],
            [
                'key' => 'create_and_clone_database',
                'label' => 'Tạo DB và clone dữ liệu',
                'description' => 'Tạo database Plesk và import dữ liệu từ source database.',
                'command_preview' => sprintf('plesk bin database --create %s -domain %s -type mysql && mysqldump %s | mysql %s', $subdomain, $parentDomain, $sourceDatabase, $subdomain),
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

    private function upsertMonitoredWebsite(WebsiteProvisionRun $run, $setup): void
    {
        $website = MonitoredWebsite::query()->firstOrNew([
            'domain' => $run->full_domain,
        ]);

        $website->name = $run->full_domain;
        $website->usage_endpoint_url = sprintf('%s://%s/application/site-usage/json', $setup->drupal_site_scheme ?: 'https', $run->full_domain);
        $website->config_endpoint_url = sprintf('%s://%s/application/site-usage/quota/config', $setup->drupal_site_scheme ?: 'https', $run->full_domain);
        $website->website_username = $run->website_username ?: $website->website_username;
        if (! empty($run->website_password)) {
            $website->website_password = $run->website_password;
        }
        $website->enabled = $website->exists ? $website->enabled : true;
        $website->quota_bytes = $website->exists ? $website->quota_bytes : 0;
        $website->user_limit = $website->exists ? $website->user_limit : 0;
        $website->warning_threshold_percent = $website->exists ? $website->warning_threshold_percent : 85;
        $website->discovery_root = $setup->drupal_project_path;
        $website->discovery_conf_path = 'sites/'.$run->full_domain;
        if (! $website->exists && blank($website->notes)) {
            $website->notes = sprintf('Khởi tạo bằng provisioning wizard từ source DB %s.', $run->source_database);
        }
        $website->save();
    }

    private function requiredSetup()
    {
        $setup = $this->setupConfiguration->current();
        if (! $this->setupConfiguration->isRemoteConfigured($setup)) {
            throw new RuntimeException('Cần hoàn tất setup server trước khi dùng chức năng tạo website.');
        }

        return $setup;
    }

    private function normalizeSubdomain(string $subdomain): string
    {
        $subdomain = strtolower(trim($subdomain));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain)) {
            throw new RuntimeException('Subdomain không hợp lệ. Chỉ dùng chữ thường, số và dấu gạch ngang.');
        }

        return $subdomain;
    }

    private function defaultParentDomain($setup): string
    {
        $authSite = trim((string) $setup->auth_site_domain);
        if ($authSite === '' || ! str_contains($authSite, '.')) {
            return '';
        }

        return substr($authSite, strpos($authSite, '.') + 1);
    }
}
