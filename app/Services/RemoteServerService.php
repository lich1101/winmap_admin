<?php

namespace App\Services;

use App\Models\SetupConfiguration;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class RemoteServerService
{
    public function discoverSites(SetupConfiguration $setup): array
    {
        $payload = $this->runPhpScript($setup, <<<'PHP'
<?php
$projectPath = rtrim((string) ($argv[1] ?? ''), DIRECTORY_SEPARATOR);
$scheme = trim((string) ($argv[2] ?? 'https')) ?: 'https';

if ($projectPath === '' || ! is_dir($projectPath)) {
    fwrite(STDERR, "Drupal project path does not exist.\n");
    exit(2);
}

$sitesRoot = $projectPath.DIRECTORY_SEPARATOR.'sites';
if (! is_dir($sitesRoot)) {
    fwrite(STDERR, "Drupal sites directory does not exist.\n");
    exit(3);
}

$aliases = [];
$sites = [];
$sitesFile = $sitesRoot.DIRECTORY_SEPARATOR.'sites.php';
if (is_file($sitesFile)) {
    include $sitesFile;
    foreach ((array) $sites as $host => $directory) {
        $directory = trim((string) $directory, '/');
        if (strpos($directory, 'sites/') === 0) {
            $directory = substr($directory, 6);
        }
        if ($directory !== '') {
            $aliases[$directory][] = (string) $host;
        }
    }
}

$items = [];
foreach (glob($sitesRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
    $name = basename($directory);
    if (in_array($name, ['all', 'private', 'default'], true)) {
        continue;
    }

    if (! is_file($directory.DIRECTORY_SEPARATOR.'settings.php')) {
        continue;
    }

    $hosts = array_values(array_unique(array_filter(array_merge($aliases[$name] ?? [], [$name]))));
    $domain = '';
    foreach ($hosts as $host) {
        if (strpos($host, '.') !== false) {
            $domain = $host;
            break;
        }
    }

    if ($domain === '' && strpos($name, '.') !== false) {
        $domain = $name;
    }

    if ($domain === '') {
        continue;
    }

    $items[] = [
        'name' => $domain,
        'domain' => $domain,
        'hosts' => $hosts,
        'usage_endpoint_url' => sprintf('%s://%s/application/site-usage/json', $scheme, $domain),
        'config_endpoint_url' => sprintf('%s://%s/application/site-usage/quota/config', $scheme, $domain),
        'discovery_root' => $projectPath,
        'discovery_conf_path' => 'sites/'.$name,
    ];
}

usort($items, static fn (array $a, array $b): int => strcasecmp($a['domain'], $b['domain']));

echo json_encode(['data' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP, [
            $setup->drupal_project_path,
            $setup->drupal_site_scheme ?: 'https',
        ]);

        return Arr::get($payload, 'data', []);
    }

    public function serverSummary(SetupConfiguration $setup): array
    {
        $result = $this->runShellScript($setup, <<<'SH'
set -eu
TARGET_PATH="$1"
df -Pk "$TARGET_PATH" | tail -n 1
SH, [$setup->drupal_project_path], 20);

        $line = trim($result['stdout']);
        $parts = preg_split('/\s+/', $line);
        if (! is_array($parts) || count($parts) < 6) {
            throw new RuntimeException('Không đọc được kết quả df từ server.');
        }

        [$filesystem, $totalKb, $usedKb, $freeKb, $usedPercent, $mountPath] = [
            $parts[0],
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[3],
            rtrim((string) $parts[4], '%'),
            $parts[5],
        ];

        $totalBytes = $totalKb * 1024;
        $usedBytes = $usedKb * 1024;
        $freeBytes = $freeKb * 1024;

        return [
            'path' => $mountPath,
            'filesystem' => $filesystem,
            'remote_host' => $setup->server_host,
            'status' => 'ok',
            'error' => null,
            'total_bytes' => $totalBytes,
            'used_bytes' => $usedBytes,
            'free_bytes' => $freeBytes,
            'used_percent' => (float) $usedPercent,
            'total_human' => ByteFormatter::human($totalBytes),
            'used_human' => ByteFormatter::human($usedBytes),
            'free_human' => ByteFormatter::human($freeBytes),
        ];
    }

    public function authenticateDrupalAdministrator(SetupConfiguration $setup, string $account, string $password): ?array
    {
        $payload = $this->runDrupalIdentityLookup($setup, [
            'mode' => 'account',
            'account' => $account,
            'password' => $password,
        ]);

        return Arr::get($payload, 'identity');
    }

    public function findDrupalAdministratorByUid(SetupConfiguration $setup, int $uid): ?array
    {
        $payload = $this->runDrupalIdentityLookup($setup, [
            'mode' => 'uid',
            'uid' => $uid,
        ]);

        return Arr::get($payload, 'identity');
    }

    public function runTerminal(SetupConfiguration $setup, array $tokens, string $cwd, int $timeout): array
    {
        return $this->runShellScript($setup, <<<'SH'
set -eu
TARGET_CWD="$1"
shift
if [ -n "$TARGET_CWD" ]; then
  cd "$TARGET_CWD"
fi
"$@"
SH, array_merge([$cwd], $tokens), $timeout);
    }

    public function runManagedShellScript(SetupConfiguration $setup, string $script, array $args = [], int $timeout = 60): array
    {
        return $this->runShellScript($setup, $script, $args, $timeout);
    }

    public function normalizeRemoteCwd(SetupConfiguration $setup, ?string $cwd): string
    {
        $projectPath = rtrim((string) $setup->drupal_project_path, '/');
        $cwd = trim((string) $cwd);

        if ($cwd === '') {
            return $projectPath;
        }

        if (! Str::startsWith($cwd, $projectPath)) {
            throw new RuntimeException('Remote cwd phải nằm trong path dự án Drupal đã cấu hình.');
        }

        return $cwd;
    }

    private function runDrupalIdentityLookup(SetupConfiguration $setup, array $input): array
    {
        $payload = $this->runPhpScript($setup, <<<'PHP'
<?php
function prefix_table($prefix, $table) {
    if (is_array($prefix)) {
        $value = $prefix[$table] ?? ($prefix['default'] ?? '');
        return $value.$table;
    }
    return (string) $prefix.$table;
}

function resolve_site_directory($projectPath, $siteKey) {
    $direct = $projectPath.'/sites/'.$siteKey;
    if (is_file($direct.'/settings.php')) {
        return $direct;
    }

    $sites = [];
    $sitesFile = $projectPath.'/sites/sites.php';
    if (is_file($sitesFile)) {
        include $sitesFile;
        if (! empty($sites[$siteKey])) {
            $directory = trim((string) $sites[$siteKey], '/');
            if (strpos($directory, 'sites/') === 0) {
                $directory = substr($directory, 6);
            }
            $candidate = $projectPath.'/sites/'.$directory;
            if (is_file($candidate.'/settings.php')) {
                return $candidate;
            }
        }
    }

    return null;
}

function load_database_config($settingsPath) {
    $databases = [];
    $db_url = null;
    $db_prefix = '';
    include $settingsPath;

    if (! empty($databases['default']['default'])) {
        $database = $databases['default']['default'];
        return [
            'driver' => 'mysql',
            'host' => (string) ($database['host'] ?? '127.0.0.1'),
            'port' => (string) ($database['port'] ?? '3306'),
            'database' => (string) ($database['database'] ?? ''),
            'username' => (string) ($database['username'] ?? ($database['user'] ?? '')),
            'password' => (string) ($database['password'] ?? ''),
            'prefix' => $database['prefix'] ?? $db_prefix ?? '',
            'unix_socket' => (string) ($database['unix_socket'] ?? ''),
        ];
    }

    if (! empty($db_url)) {
        $parts = parse_url($db_url);
        return [
            'driver' => 'mysql',
            'host' => (string) ($parts['host'] ?? '127.0.0.1'),
            'port' => (string) ($parts['port'] ?? '3306'),
            'database' => ltrim((string) ($parts['path'] ?? ''), '/'),
            'username' => (string) ($parts['user'] ?? ''),
            'password' => (string) ($parts['pass'] ?? ''),
            'prefix' => $db_prefix ?? '',
            'unix_socket' => '',
        ];
    }

    return null;
}

function build_pdo($database) {
    if (! empty($database['unix_socket'])) {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $database['unix_socket'], $database['database']);
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $database['host'],
            $database['port'],
            $database['database']
        );
    }

    return new PDO($dsn, $database['username'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function find_user(PDO $pdo, $prefix, $payload) {
    $usersTable = prefix_table($prefix, 'users');
    if (($payload['mode'] ?? '') === 'uid') {
        $stmt = $pdo->prepare("SELECT uid, name, mail, pass, status FROM {$usersTable} WHERE uid = :uid LIMIT 1");
        $stmt->execute(['uid' => (int) ($payload['uid'] ?? 0)]);
        return $stmt->fetch() ?: null;
    }

    $account = strtolower(trim((string) ($payload['account'] ?? '')));
    $stmt = $pdo->prepare("SELECT uid, name, mail, pass, status FROM {$usersTable} WHERE LOWER(name) = :account AND status = 1 LIMIT 1");
    $stmt->execute(['account' => $account]);
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }

    $stmt = $pdo->prepare("SELECT uid, name, mail, pass, status FROM {$usersTable} WHERE LOWER(mail) = :account AND status = 1 LIMIT 1");
    $stmt->execute(['account' => $account]);
    return $stmt->fetch() ?: null;
}

function is_admin(PDO $pdo, $prefix, $uid) {
    if ((int) $uid === 1) {
        return true;
    }

    $usersRoles = prefix_table($prefix, 'users_roles');
    $rolePermission = prefix_table($prefix, 'role_permission');
    $stmt = $pdo->prepare("
        SELECT 1
        FROM {$usersRoles} ur
        INNER JOIN {$rolePermission} rp ON rp.rid = ur.rid
        WHERE ur.uid = :uid AND rp.permission = :permission
        LIMIT 1
    ");
    $stmt->execute([
        'uid' => (int) $uid,
        'permission' => 'administer users',
    ]);

    return (bool) $stmt->fetchColumn();
}

$projectPath = rtrim((string) ($argv[1] ?? ''), DIRECTORY_SEPARATOR);
$siteKey = trim((string) ($argv[2] ?? ''));
$payload = json_decode(base64_decode((string) ($argv[3] ?? ''), true), true);

if ($projectPath === '' || $siteKey === '' || ! is_array($payload)) {
    echo json_encode(['identity' => null, 'message' => 'Thiếu thông tin xác thực Drupal.']);
    exit(0);
}

$siteDirectory = resolve_site_directory($projectPath, $siteKey);
if (! $siteDirectory) {
    echo json_encode(['identity' => null, 'message' => 'Không tìm thấy thư mục site Drupal để xác thực administrator.']);
    exit(0);
}

$settingsPath = $siteDirectory.'/settings.php';
$database = load_database_config($settingsPath);
if (! $database) {
    echo json_encode(['identity' => null, 'message' => 'Không đọc được cấu hình database Drupal.']);
    exit(0);
}

$passwordInc = $projectPath.'/includes/password.inc';
if (! is_file($passwordInc)) {
    echo json_encode(['identity' => null, 'message' => 'Thiếu includes/password.inc trong project Drupal.']);
    exit(0);
}

require_once $passwordInc;

$pdo = build_pdo($database);
$user = find_user($pdo, $database['prefix'] ?? '', $payload);
if (! $user || (int) ($user['status'] ?? 0) !== 1) {
    echo json_encode(['identity' => null]);
    exit(0);
}

if (($payload['mode'] ?? '') === 'account') {
    if (! function_exists('user_check_password') || ! user_check_password((string) ($payload['password'] ?? ''), (object) $user)) {
        echo json_encode(['identity' => null]);
        exit(0);
    }
}

if (! is_admin($pdo, $database['prefix'] ?? '', (int) $user['uid'])) {
    echo json_encode(['identity' => null]);
    exit(0);
}

echo json_encode([
    'identity' => [
        'drupal_uid' => (int) $user['uid'],
        'name' => trim((string) ($user['name'] ?? '')),
        'email' => trim((string) ($user['mail'] ?? '')),
        'site_key' => $siteKey,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP, [
            $setup->drupal_project_path,
            $setup->auth_site_domain,
            base64_encode(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ], 25);

        return is_array($payload) ? $payload : ['identity' => null];
    }

    private function runPhpScript(SetupConfiguration $setup, string $phpScript, array $args = [], int $timeout = 20): array
    {
        $shellScript = <<<'SH'
set -eu
TMP_FILE="$(mktemp)"
cleanup() {
  rm -f "$TMP_FILE"
}
trap cleanup EXIT
cat > "$TMP_FILE" <<'__WINMAP_PHP__'
__PHP_SCRIPT__
__WINMAP_PHP__
PHP_OUTPUT="$(php "$TMP_FILE" "$@")"
printf '__WINMAP_JSON_BEGIN__\n%s\n__WINMAP_JSON_END__\n' "$PHP_OUTPUT"
SH;

        $shellScript = str_replace('__PHP_SCRIPT__', $phpScript, $shellScript);
        $result = $this->runShellScript($setup, $shellScript, $args, $timeout);
        $stdout = trim((string) ($result['stdout'] ?? ''));
        if (! preg_match('/__WINMAP_JSON_BEGIN__\R(.*)\R__WINMAP_JSON_END__/s', $stdout, $matches)) {
            $snippet = trim($stdout."\n".($result['stderr'] ?? ''));
            throw new RuntimeException('Remote PHP script did not return valid JSON. '.$this->shortenDiagnostic($snippet));
        }

        $payload = json_decode(trim((string) $matches[1]), true);

        if (! is_array($payload)) {
            $snippet = trim($stdout."\n".($result['stderr'] ?? ''));
            throw new RuntimeException('Remote PHP script returned malformed JSON. '.$this->shortenDiagnostic($snippet));
        }

        return $payload;
    }

    private function runShellScript(SetupConfiguration $setup, string $script, array $args = [], int $timeout = 20): array
    {
        $this->assertConfigured($setup);

        $command = [
            'setsid',
            'ssh',
            '-o',
            'StrictHostKeyChecking=no',
            '-o',
            'UserKnownHostsFile=/dev/null',
            '-o',
            'ConnectTimeout=8',
            '-o',
            'LogLevel=ERROR',
            '-p',
            (string) ($setup->server_port ?: 22),
        ];

        $environment = [];
        $askPassFile = null;
        if (filled($setup->server_password)) {
            $command = array_merge($command, [
                '-o',
                'PreferredAuthentications=password,keyboard-interactive',
                '-o',
                'PubkeyAuthentication=no',
                '-o',
                'NumberOfPasswordPrompts=1',
            ]);

            $askPassFile = tempnam(sys_get_temp_dir(), 'winmap-askpass-');
            if ($askPassFile === false) {
                throw new RuntimeException('Không tạo được file SSH_ASKPASS tạm thời.');
            }

            file_put_contents($askPassFile, "#!/bin/sh\nprintf '%s\\n' \"\$WINMAP_SSH_PASSWORD\"\n");
            @chmod($askPassFile, 0700);

            $environment = [
                'DISPLAY' => 'winmap-admin:0',
                'SSH_ASKPASS' => $askPassFile,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'WINMAP_SSH_PASSWORD' => (string) $setup->server_password,
            ];
        }

        $command[] = sprintf('%s@%s', $setup->server_username, $setup->server_host);
        $command[] = 'sh';
        $command[] = '-s';
        $command[] = '--';
        foreach ($args as $arg) {
            $command[] = (string) $arg;
        }

        try {
            $process = new Process($command, null, $environment, $script, (float) $timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                $details = trim($process->getErrorOutput()."\n".$process->getOutput());
                throw new RuntimeException('SSH command failed. '.($this->shortenDiagnostic($details) ?: 'No diagnostic output.'));
            }

            return [
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
                'exit_code' => $process->getExitCode(),
            ];
        } finally {
            if ($askPassFile && is_file($askPassFile)) {
                @unlink($askPassFile);
            }
        }
    }

    private function assertConfigured(SetupConfiguration $setup): void
    {
        if (! filled($setup->server_host) || ! filled($setup->server_username) || ! filled($setup->drupal_project_path)) {
            throw new RuntimeException('Thiếu cấu hình server hoặc path Drupal để chạy lệnh từ xa.');
        }
    }

    private function shortenDiagnostic(string $message): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? '');

        if ($clean === '') {
            return '';
        }

        return Str::limit($clean, 500);
    }
}
