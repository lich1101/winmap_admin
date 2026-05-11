<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DrupalAuthenticationService
{
    private const CONNECTION_NAME = 'drupal_auth_runtime';

    private ?array $resolvedConnection = null;

    private string|array $tablePrefix = '';

    public function isConfigured(): bool
    {
        $config = $this->config();

        return filled($config['settings_path'] ?? null)
            || filled($config['site_key'] ?? null)
            || filled($config['database'] ?? null);
    }

    public function authenticateAdministrator(string $account, string $password): ?array
    {
        $account = trim($account);
        $password = (string) $password;

        if ($account === '' || trim($password) === '') {
            return null;
        }

        $record = $this->findUserByAccount($account);
        if (! $record || ! $this->passwordMatches($password, $record)) {
            return null;
        }

        return $this->normalizeAdministrator($record);
    }

    public function synchronizeShadowUser(User $user): bool
    {
        if (($user->auth_source ?? 'local') !== 'drupal' || empty($user->drupal_uid)) {
            return $user->isAdministrator();
        }

        $identity = $this->findAdministratorByUid((int) $user->drupal_uid);
        if (! $identity) {
            $user->role = 'viewer';
            $user->is_active = false;
            $user->save();

            return false;
        }

        $this->fillShadowUser($user, $identity);
        $user->save();

        return true;
    }

    public function upsertShadowAdministrator(array $identity): User
    {
        $user = User::query()
            ->where('auth_source', 'drupal')
            ->where('drupal_site', $identity['site_key'])
            ->where('drupal_uid', $identity['drupal_uid'])
            ->first();

        if (! $user && filled($identity['email'])) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($identity['email'])])
                ->first();
        }

        $user ??= new User();

        $this->fillShadowUser($user, $identity);

        if (blank($user->password)) {
            $user->password = Str::random(40);
        }

        $user->save();

        return $user->fresh();
    }

    private function fillShadowUser(User $user, array $identity): void
    {
        $user->name = $identity['name'];
        $user->email = $identity['email'] ?: sprintf(
            'drupal-%s-%d@local.invalid',
            Str::slug($identity['site_key']),
            $identity['drupal_uid']
        );
        $user->role = 'administrator';
        $user->is_active = true;
        $user->auth_source = 'drupal';
        $user->drupal_uid = $identity['drupal_uid'];
        $user->drupal_site = $identity['site_key'];
        $user->email_verified_at ??= now();
    }

    private function findAdministratorByUid(int $uid): ?array
    {
        $record = $this->connection()
            ->table($this->table('users'))
            ->select(['uid', 'name', 'mail', 'pass', 'status'])
            ->where('uid', $uid)
            ->where('status', 1)
            ->first();

        return $record ? $this->normalizeAdministrator((array) $record) : null;
    }

    private function findUserByAccount(string $account): ?array
    {
        $connection = $this->connection();
        $normalized = mb_strtolower($account);

        $record = $connection
            ->table($this->table('users'))
            ->select(['uid', 'name', 'mail', 'pass', 'status'])
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->where('status', 1)
            ->first();

        if (! $record) {
            $record = $connection
                ->table($this->table('users'))
                ->select(['uid', 'name', 'mail', 'pass', 'status'])
                ->whereRaw('LOWER(mail) = ?', [$normalized])
                ->where('status', 1)
                ->first();
        }

        return $record ? (array) $record : null;
    }

    private function normalizeAdministrator(array $record): ?array
    {
        $uid = (int) ($record['uid'] ?? 0);

        if ($uid < 1 || ! $this->userIsAdministrator($uid)) {
            return null;
        }

        return [
            'drupal_uid' => $uid,
            'name' => trim((string) ($record['name'] ?? '')),
            'email' => trim((string) ($record['mail'] ?? '')),
            'site_key' => $this->siteKey(),
        ];
    }

    private function userIsAdministrator(int $uid): bool
    {
        if ($uid === 1) {
            return true;
        }

        return $this->connection()
            ->table($this->table('users_roles').' as ur')
            ->join($this->table('role_permission').' as rp', 'rp.rid', '=', 'ur.rid')
            ->where('ur.uid', $uid)
            ->where('rp.permission', 'administer users')
            ->exists();
    }

    private function passwordMatches(string $password, array $record): bool
    {
        $path = $this->passwordIncPath();
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Drupal password.inc not found at: %s', $path));
        }

        require_once $path;

        if (! function_exists('user_check_password')) {
            throw new RuntimeException('Drupal user_check_password() is not available.');
        }

        return user_check_password($password, (object) $record);
    }

    private function connection()
    {
        if ($this->resolvedConnection !== null) {
            return DB::connection(self::CONNECTION_NAME);
        }

        [$connection, $prefix] = $this->resolveConnectionDefinition();

        Config::set('database.connections.'.self::CONNECTION_NAME, $connection);
        DB::purge(self::CONNECTION_NAME);

        $this->resolvedConnection = $connection;
        $this->tablePrefix = $prefix;

        return DB::connection(self::CONNECTION_NAME);
    }

    private function resolveConnectionDefinition(): array
    {
        $config = $this->config();

        $settingsPath = $this->resolvedSettingsPath();
        if ($settingsPath !== null) {
            return $this->connectionFromSettings($settingsPath);
        }

        if (blank($config['database'] ?? null)) {
            throw new RuntimeException('Drupal auth is not configured.');
        }

        return [[
            'driver' => 'mysql',
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (string) ($config['port'] ?? '3306'),
            'database' => (string) $config['database'],
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            'unix_socket' => (string) ($config['socket'] ?? ''),
            'charset' => (string) ($config['charset'] ?? 'utf8mb4'),
            'collation' => (string) ($config['collation'] ?? 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
        ], (string) ($config['prefix'] ?? '')];
    }

    private function connectionFromSettings(string $settingsPath): array
    {
        if (! is_file($settingsPath) || ! is_readable($settingsPath)) {
            throw new RuntimeException(sprintf('Drupal settings.php not readable: %s', $settingsPath));
        }

        $databases = [];
        $db_url = null;
        $db_prefix = '';
        include $settingsPath;

        if (! empty($databases['default']['default'])) {
            $database = $databases['default']['default'];
            $prefix = $database['prefix'] ?? $db_prefix ?? '';

            return [[
                'driver' => 'mysql',
                'host' => (string) ($database['host'] ?? '127.0.0.1'),
                'port' => (string) ($database['port'] ?? '3306'),
                'database' => (string) ($database['database'] ?? ''),
                'username' => (string) ($database['username'] ?? $database['user'] ?? ''),
                'password' => (string) ($database['password'] ?? ''),
                'unix_socket' => (string) ($database['unix_socket'] ?? ''),
                'charset' => (string) ($database['charset'] ?? 'utf8mb4'),
                'collation' => (string) ($database['collation'] ?? 'utf8mb4_unicode_ci'),
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => false,
                'engine' => null,
            ], $prefix];
        }

        if (! empty($db_url)) {
            $parts = parse_url((string) $db_url);
            if ($parts === false) {
                throw new RuntimeException('Drupal db_url is invalid.');
            }

            return [[
                'driver' => 'mysql',
                'host' => (string) ($parts['host'] ?? '127.0.0.1'),
                'port' => (string) ($parts['port'] ?? '3306'),
                'database' => ltrim((string) ($parts['path'] ?? ''), '/'),
                'username' => urldecode((string) ($parts['user'] ?? '')),
                'password' => urldecode((string) ($parts['pass'] ?? '')),
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => false,
                'engine' => null,
            ], $db_prefix];
        }

        throw new RuntimeException('Drupal settings.php does not define a supported database connection.');
    }

    private function passwordIncPath(): string
    {
        $settingsPath = $this->resolvedSettingsPath() ?? '';
        if ($settingsPath !== '') {
            return dirname(dirname(dirname($settingsPath))).DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'password.inc';
        }

        return (string) ($this->config()['password_inc_path'] ?? '');
    }

    private function siteKey(): string
    {
        $siteKey = trim((string) ($this->config()['site_key'] ?? ''));
        if ($siteKey !== '') {
            return $siteKey;
        }

        $settingsPath = $this->resolvedSettingsPath() ?? '';
        if ($settingsPath !== '') {
            return basename(dirname($settingsPath));
        }

        return trim((string) ($this->config()['database'] ?? 'drupal'));
    }

    private function table(string $base): string
    {
        if (is_array($this->tablePrefix)) {
            if (isset($this->tablePrefix[$base])) {
                return (string) $this->tablePrefix[$base];
            }

            return (string) ($this->tablePrefix['default'] ?? '').$base;
        }

        return (string) $this->tablePrefix.$base;
    }

    private function config(): array
    {
        return (array) config('winmap_admin.drupal_auth', []);
    }

    private function resolvedSettingsPath(): ?string
    {
        $settingsPath = trim((string) ($this->config()['settings_path'] ?? ''));
        if ($settingsPath !== '' && is_readable($settingsPath)) {
            return $settingsPath;
        }

        $siteKey = trim((string) ($this->config()['site_key'] ?? ''));
        foreach ((array) config('winmap_admin.discovery.roots', []) as $root) {
            $resolved = $this->findSettingsPathInRoot((string) $root, $siteKey);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function findSettingsPathInRoot(string $root, string $siteKey): ?string
    {
        $sitesRoot = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sites';
        if (! is_dir($sitesRoot)) {
            return null;
        }

        if ($siteKey !== '') {
            $candidates = array_unique(array_filter([
                $sitesRoot.DIRECTORY_SEPARATOR.$siteKey.DIRECTORY_SEPARATOR.'settings.php',
                $sitesRoot.DIRECTORY_SEPARATOR.$this->resolveSiteDirectoryAlias($root, $siteKey).DIRECTORY_SEPARATOR.'settings.php',
            ]));

            foreach ($candidates as $candidate) {
                if (is_readable($candidate)) {
                    return $candidate;
                }
            }
        }

        $default = $sitesRoot.DIRECTORY_SEPARATOR.'default'.DIRECTORY_SEPARATOR.'settings.php';

        return is_readable($default) ? $default : null;
    }

    private function resolveSiteDirectoryAlias(string $root, string $siteKey): string
    {
        $file = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sites'.DIRECTORY_SEPARATOR.'sites.php';
        if ($siteKey === '' || ! is_readable($file)) {
            return '';
        }

        $sites = [];
        include $file;

        $directory = trim((string) ($sites[$siteKey] ?? ''), '/');
        if (str_starts_with($directory, 'sites/')) {
            $directory = substr($directory, 6);
        }

        return $directory;
    }
}
