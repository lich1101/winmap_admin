# Winmap Admin

Laravel + React admin panel for monitoring storage usage across Drupal 7 multisite websites.

## Main capabilities

- Administrator-only login.
- First-run setup wizard after login.
- Stores SSH server access and Drupal multisite project path in MySQL.
- Discovers Drupal 7 multisite websites over SSH from the configured remote project.
- Stores per-website credentials in MySQL for later operational actions.
- Supports step-by-step website provisioning over SSH from an existing Plesk + Drupal multisite template flow.
- MySQL-backed website quota management.
- Auto-discovery of Drupal multisite websites from configured local codebase roots.
- Reads current website usage from each Drupal site usage endpoint.
- Pushes quota, warning threshold, and enforcement state back to each Drupal site.
- Stores usage snapshots for audit/history.
- Reads target server disk usage over SSH.
- Provides a guarded web terminal that runs over SSH on the configured server, with command allowlist, timeout, output limit, and audit logs.

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

Configure the fallback local admin account:

```env
ADMIN_NAME="Administrator"
ADMIN_EMAIL=admin@winmap.local
ADMIN_PASSWORD=change-this-password
```

The local Laravel administrator is still needed for bootstrap or recovery, because the normal operational flow is:

1. Login to `winmap_admin`.
2. Complete the setup wizard.
3. Let the setup wizard save SSH server access, Drupal project path, and per-site credentials.
4. After setup is completed, the panel can use Drupal administrator verification from the configured auth site.

If you want to bypass the setup wizard and use direct Drupal auth from environment variables, the minimum config is:

```env
DRUPAL_AUTH_SITE_KEY="enter.winmap.vn"
```

The service will try to resolve one of these automatically:

- `sites/enter.winmap.vn/settings.php`
- alias from `sites/sites.php`
- `sites/default/settings.php` as a final fallback

If you want to pin the exact Drupal site, point it directly to `settings.php`:

```env
DRUPAL_AUTH_SETTINGS_PATH="/var/www/winmap/sites/enter.winmap.vn/settings.php"
DRUPAL_AUTH_SITE_KEY="enter.winmap.vn"
```

Or configure the Drupal auth database directly:

```env
DRUPAL_AUTH_DB_HOST=127.0.0.1
DRUPAL_AUTH_DB_PORT=3306
DRUPAL_AUTH_DB_DATABASE=drupal_database
DRUPAL_AUTH_DB_USERNAME=drupal_user
DRUPAL_AUTH_DB_PASSWORD=secret
DRUPAL_AUTH_DB_PREFIX=
DRUPAL_AUTH_PASSWORD_INC_PATH="/var/www/winmap/includes/password.inc"
```

When `DRUPAL_AUTH_SETTINGS_PATH` or `DRUPAL_AUTH_DB_DATABASE` is configured, login uses the Drupal `users` table and checks the same administrator rule as the Drupal web:

- `uid = 1`, or
- the user has the Drupal permission `administer users`

The local Laravel administrator remains only as a fallback for development, bootstrap, or setup recovery when Drupal auth is not configured yet.

Configure Drupal multisite discovery only if you want local filesystem fallback without the setup wizard:

```env
DRUPAL_DISCOVERY_ROOTS="/var/www/winmap|/var/www/winmap_clone"
DRUPAL_SITE_SCHEME=https
DEFAULT_WARNING_THRESHOLD_PERCENT=85
```

Run migrations and seed the fallback administrator:

```bash
php artisan migrate --seed
npm run build
```

On each Drupal multisite codebase:

1. Enable `winmap_site_usage`.
2. Run `update.php` so quota variables are initialized.
3. Set `winmap_site_usage_api_key` if you want the admin panel to read usage and push quota securely.
4. Use the setup wizard or the admin panel button `Quét multisite` to import all discovered websites.

Run locally:

```bash
php artisan serve
npm run dev
```

## Docker

This repo now includes a Docker stack for the Laravel admin:

- `app`: PHP 8.3 + Composer + Node.js
- `mysql`: MySQL 8.4 for the admin database

The Docker stack also mounts the Drupal codebase read-only at `/workspace/winmap_new` so quota discovery and Drupal password verification can read:

- `sites/.../settings.php`
- `includes/password.inc`

### First run

From [winmap_admin](/Users/macbook/Desktop/app%20winmap/winmap_admin):

```bash
cp .env.docker.example .env
docker compose up --build
```

If you already have a local non-Docker `.env`, do not reuse it. Copy the Docker example over it or create a dedicated Docker env first, because the local file may still point to `sqlite` or host-only paths.

Open:

- admin UI: [http://localhost:8088](http://localhost:8088)
- MySQL forward port: `33067`

### Docker auth config for Drupal admin login

If you want direct environment-based Drupal auth without using the setup wizard, minimum config inside `.env` is:

```env
DRUPAL_DISCOVERY_ROOTS="/workspace/winmap_new"
DRUPAL_AUTH_SITE_KEY="enter.winmap.vn"
DRUPAL_AUTH_PASSWORD_INC_PATH="/workspace/winmap_new/includes/password.inc"
```

If the Drupal `settings.php` inside the mounted codebase already points to a DB host reachable from the `app` container, this is enough.

### Important Docker caveat

If the Drupal `settings.php` uses `localhost` or `127.0.0.1` for the Drupal database, the `app` container will usually not be able to reach that DB. In that case, override the Drupal auth DB explicitly:

```env
DRUPAL_AUTH_DB_HOST=host.docker.internal
DRUPAL_AUTH_DB_PORT=3306
DRUPAL_AUTH_DB_DATABASE=drupal_database
DRUPAL_AUTH_DB_USERNAME=drupal_user
DRUPAL_AUTH_DB_PASSWORD=drupal_password
DRUPAL_AUTH_DB_PREFIX=
```

When `DRUPAL_AUTH_DB_DATABASE` is set, Docker auth will prefer these explicit DB values over the host from `settings.php`.

### Preferred operational flow in Docker

For the new setup wizard flow, you normally do **not** need to fill `DRUPAL_AUTH_*` first.

Instead:

1. Bring the stack up with Docker.
2. Login using the bootstrap Laravel administrator.
3. In the setup wizard, enter:
   - SSH host / port / username / password
   - Drupal 7 multisite project path on that server
   - website scheme (`http` or `https`)
4. Click `Quét multisite`.
5. Fill website credentials for each discovered site.
6. Select one website as the Drupal admin authentication site for this panel.
7. Complete setup and start managing quota.

This wizard-backed setup is now the primary flow.

## Step-by-step website provisioning

The dashboard now includes a dedicated action `Khởi tạo website` separate from manual `Thêm website`.

This provisioning flow is modeled after the current shell script and runs each step independently over SSH:

1. Create subdomain in Plesk
2. Issue SSL
3. Copy `sites/init` and `sites/private/init`
4. Replace placeholders in `settings.php`
5. Create destination database and import from a source database

Each provisioning run:

- stores full step status in MySQL
- keeps per-step output logs
- allows rerunning a failed step
- allows running all remaining steps
- automatically registers the new website into monitored websites after all steps succeed

Inputs required in the provisioning drawer:

- `subdomain`
- `parent_domain`
- `www_root`
- `system_user`
- `source_database`
- `mysql_password_file`
- `ssl_registration_email`
- optional website credential to attach to the monitored website record

### Useful Docker commands

```bash
docker compose up --build -d
docker compose logs -f app
docker compose exec app php artisan test
docker compose exec app php artisan migrate
docker compose down
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
