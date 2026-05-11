# Winmap Admin

Laravel + React admin panel for monitoring storage usage across Drupal 7 multisite websites.

## Main capabilities

- Administrator-only login.
- MySQL-backed website quota management.
- Auto-discovery of Drupal multisite websites from configured local codebase roots.
- Reads current website usage from each Drupal site usage endpoint.
- Pushes quota, warning threshold, and enforcement state back to each Drupal site.
- Stores usage snapshots for audit/history.
- Reads server disk usage with PHP `disk_total_space` / `disk_free_space`.
- Provides a guarded web terminal with command allowlist, cwd allowlist, timeout, output limit, and command audit logs.

## Required Drupal endpoint

Each monitored Drupal site should expose one of these endpoints from the `winmap_site_usage` module:

```text
/application/site-usage/json
/application/site-usage/all/json
/application/site-usage/quota/json
/application/site-usage/quota/config
```

If the endpoint uses a key, add it in the website form. The Laravel client sends it as:

```text
X-Winmap-Site-Usage-Key: <key>
```

When a site exceeds quota, the Drupal module blocks normal HTML pages and normal APIs. Only the quota/usage management endpoints remain open so this admin panel can still unlock the site by raising quota.

## Setup

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
```

Configure MySQL in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=winmap_admin
DB_USERNAME=root
DB_PASSWORD=
```

Configure the first admin account:

```env
ADMIN_NAME="Administrator"
ADMIN_EMAIL=admin@winmap.local
ADMIN_PASSWORD=change-this-password
```

Configure Drupal multisite discovery:

```env
DRUPAL_DISCOVERY_ROOTS="/var/www/winmap|/var/www/winmap_clone"
DRUPAL_SITE_SCHEME=https
DEFAULT_WARNING_THRESHOLD_PERCENT=85
```

Run migrations and seed the administrator:

```bash
php artisan migrate --seed
npm run build
```

On each Drupal multisite codebase:

1. Enable `winmap_site_usage`.
2. Run `update.php` so quota variables are initialized.
3. Set `winmap_site_usage_api_key` if you want the admin panel to read usage and push quota securely.
4. Use the admin panel button `Quét multisite` to import all discovered websites.

Run locally:

```bash
php artisan serve
npm run dev
```

## Terminal security

Default terminal commands are intentionally limited:

```env
TERMINAL_ALLOWED_COMMANDS=pwd,ls,df,du,uptime,whoami,date
TERMINAL_ALLOWED_ROOTS="/var/www|/home|/Users/macbook/Desktop/app winmap"
TERMINAL_TIMEOUT=12
TERMINAL_MAX_OUTPUT_BYTES=60000
```

Do not add dangerous commands such as `rm`, `sh`, `bash`, `php`, `python`, or package managers unless this admin panel is isolated behind VPN/IP allowlist and you accept the risk.
