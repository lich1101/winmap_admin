<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\MonitoredWebsite;
use App\Models\SetupConfiguration;
use App\Services\DrupalAuthenticationService;
use App\Services\DrupalSiteDiscoveryService;
use App\Services\RemoteServerService;
use App\Models\WebsiteProvisionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebsiteQuotaControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_discover_multisite_websites_from_configured_roots(): void
    {
        $root = storage_path('framework/testing/drupal-discovery');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/sites/enter.winmap.vn');
        File::ensureDirectoryExists($root.'/sites/demo.internal');

        File::put($root.'/sites/enter.winmap.vn/settings.php', "<?php\n\$databases = array();\n\$conf = array();\n");
        File::put($root.'/sites/demo.internal/settings.php', "<?php\n\$databases = array();\n\$conf = array();\n");
        File::put($root.'/sites/sites.php', "<?php\n\$sites = array(\n  'enter.winmap.vn' => 'sites/enter.winmap.vn',\n);\n");

        Config::set('winmap_admin.discovery.roots', [$root]);

        $service = app(DrupalSiteDiscoveryService::class);
        $sites = $service->discover();
        $byDomain = collect($sites)->keyBy('domain');

        $this->assertCount(2, $sites);
        $this->assertTrue($byDomain->has('enter.winmap.vn'));
        $this->assertSame('https://enter.winmap.vn/application/site-usage/quota/config', $byDomain['enter.winmap.vn']['config_endpoint_url']);
    }

    public function test_admin_store_syncs_quota_to_drupal_site(): void
    {
        Http::fake([
            'https://enter.winmap.vn/application/site-usage/quota/config' => Http::response([
                'status' => 'success',
                'quota' => [
                    'is_blocked' => false,
                    'is_warning' => false,
                ],
            ], 200),
        ]);

        SetupConfiguration::query()->create([
            'is_completed' => true,
            'server_host' => '10.10.10.10',
            'server_port' => 22,
            'server_username' => 'root',
            'server_password' => 'secret',
            'drupal_project_path' => '/srv/www/winmap',
            'drupal_site_scheme' => 'https',
            'auth_site_domain' => 'enter.winmap.vn',
        ]);

        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/websites', [
            'name' => 'Enter',
            'domain' => 'enter.winmap.vn',
            'usage_endpoint_url' => 'https://enter.winmap.vn/application/site-usage/json',
            'config_endpoint_url' => 'https://enter.winmap.vn/application/site-usage/quota/config',
            'quota_gb' => 10,
            'warning_threshold_percent' => 90,
            'enabled' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.domain', 'enter.winmap.vn')
            ->assertJsonPath('data.last_sync_status', 'ok');

        Http::assertSentCount(1);
    }

    public function test_admin_can_refresh_all_websites_usage(): void
    {
        SetupConfiguration::query()->create([
            'is_completed' => true,
            'server_host' => '10.10.10.10',
            'server_port' => 22,
            'server_username' => 'root',
            'server_password' => 'secret',
            'drupal_project_path' => '/srv/www/winmap',
            'drupal_site_scheme' => 'https',
            'auth_site_domain' => 'enter.winmap.vn',
        ]);

        MonitoredWebsite::query()->create([
            'name' => 'Enter',
            'domain' => 'enter.winmap.vn',
            'usage_endpoint_url' => 'https://enter.winmap.vn/application/site-usage/json',
            'config_endpoint_url' => 'https://enter.winmap.vn/application/site-usage/quota/config',
            'enabled' => true,
            'quota_bytes' => 1024 * 1024 * 1024,
            'warning_threshold_percent' => 85,
        ]);

        MonitoredWebsite::query()->create([
            'name' => 'Demo',
            'domain' => 'demo.winmap.vn',
            'usage_endpoint_url' => 'https://demo.winmap.vn/application/site-usage/json',
            'config_endpoint_url' => 'https://demo.winmap.vn/application/site-usage/quota/config',
            'enabled' => true,
            'quota_bytes' => 1024 * 1024 * 1024,
            'warning_threshold_percent' => 85,
        ]);

        Http::fake([
            'https://enter.winmap.vn/application/site-usage/json' => Http::response([
                'totals' => [
                    'disk_bytes' => 100,
                    'database_bytes' => 50,
                    'project_bytes' => 150,
                ],
                'quota' => [
                    'is_blocked' => false,
                    'is_warning' => false,
                ],
            ], 200),
            'https://demo.winmap.vn/application/site-usage/json' => Http::response([
                'totals' => [
                    'disk_bytes' => 200,
                    'database_bytes' => 75,
                    'project_bytes' => 275,
                ],
                'quota' => [
                    'is_blocked' => false,
                    'is_warning' => false,
                ],
            ], 200),
        ]);

        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/websites/refresh-all');

        $response->assertOk()
            ->assertJsonPath('summary.count', 2)
            ->assertJsonPath('summary.success', 2)
            ->assertJsonPath('summary.errors', 0);

        $this->assertSame(150, MonitoredWebsite::query()->where('domain', 'enter.winmap.vn')->value('last_project_bytes'));
        $this->assertSame(275, MonitoredWebsite::query()->where('domain', 'demo.winmap.vn')->value('last_project_bytes'));
    }

    public function test_admin_can_login_with_account_name_like_web_login(): void
    {
        User::factory()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret-123'),
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'account' => 'administrator',
            'password' => 'secret-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com');
    }

    public function test_login_uses_drupal_administrator_when_drupal_auth_is_configured(): void
    {
        $mock = Mockery::mock(DrupalAuthenticationService::class);
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('authenticateAdministrator')->once()->with('administrator', 'secret-123')->andReturn([
            'drupal_uid' => 1,
            'name' => 'administrator',
            'email' => 'admin@drupal.local',
            'site_key' => 'enter.winmap.vn',
        ]);
        $mock->shouldReceive('upsertShadowAdministrator')->once()->andReturnUsing(function (array $identity): User {
            return User::factory()->create([
                'name' => $identity['name'],
                'email' => $identity['email'],
                'role' => 'administrator',
                'is_active' => true,
                'auth_source' => 'drupal',
                'drupal_uid' => $identity['drupal_uid'],
                'drupal_site' => $identity['site_key'],
            ]);
        });
        $this->app->instance(DrupalAuthenticationService::class, $mock);

        $response = $this->postJson('/api/login', [
            'account' => 'administrator',
            'password' => 'secret-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.auth_source', 'drupal')
            ->assertJsonPath('user.drupal_uid', 1);
    }

    public function test_local_password_is_not_used_when_drupal_auth_is_configured(): void
    {
        User::factory()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret-123'),
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $mock = Mockery::mock(DrupalAuthenticationService::class);
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('authenticateAdministrator')->once()->with('administrator', 'secret-123')->andReturn(null);
        $this->app->instance(DrupalAuthenticationService::class, $mock);

        $response = $this->postJson('/api/login', [
            'account' => 'administrator',
            'password' => 'secret-123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('account');
    }

    public function test_setup_status_is_available_before_setup_is_completed(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/setup/status');

        $response->assertOk()
            ->assertJsonPath('completed', false)
            ->assertJsonPath('config.server_port', 22);
    }

    public function test_dashboard_is_blocked_until_setup_is_completed(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/dashboard');

        $response->assertStatus(428)
            ->assertJsonPath('setup_required', true);
    }

    public function test_admin_can_preview_setup_discovery_with_mocked_remote_service(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $remote = Mockery::mock(RemoteServerService::class);
        $remote->shouldReceive('discoverSites')->once()->andReturn([
            [
                'name' => 'enter.winmap.vn',
                'domain' => 'enter.winmap.vn',
                'usage_endpoint_url' => 'https://enter.winmap.vn/application/site-usage/json',
                'config_endpoint_url' => 'https://enter.winmap.vn/application/site-usage/quota/config',
                'discovery_root' => '/srv/www/winmap',
                'discovery_conf_path' => 'sites/enter.winmap.vn',
            ],
        ]);
        $remote->shouldReceive('serverSummary')->once()->andReturn([
            'remote_host' => '10.10.10.10',
            'path' => '/srv/www',
            'used_percent' => 42,
            'used_human' => '42 GB',
            'free_human' => '58 GB',
            'total_human' => '100 GB',
        ]);
        $this->app->instance(RemoteServerService::class, $remote);

        $response = $this->actingAs($admin)->postJson('/api/setup/discover', [
            'server_host' => '10.10.10.10',
            'server_port' => 22,
            'server_username' => 'root',
            'server_password' => 'secret',
            'drupal_project_path' => '/srv/www/winmap',
            'drupal_site_scheme' => 'https',
        ]);

        $response->assertOk()
            ->assertJsonPath('server.remote_host', '10.10.10.10')
            ->assertJsonPath('sites.0.domain', 'enter.winmap.vn');
    }

    public function test_setup_discovery_returns_validation_error_when_remote_execution_fails(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $remote = Mockery::mock(RemoteServerService::class);
        $remote->shouldReceive('discoverSites')
            ->once()
            ->andThrow(new RuntimeException('SSH command failed. Permission denied.'));
        $remote->shouldNotReceive('serverSummary');
        $this->app->instance(RemoteServerService::class, $remote);

        $response = $this->actingAs($admin)->postJson('/api/setup/discover', [
            'server_host' => '10.10.10.10',
            'server_port' => 22,
            'server_username' => 'root',
            'server_password' => 'secret',
            'drupal_project_path' => '/srv/www/winmap',
            'drupal_site_scheme' => 'https',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'SSH command failed. Permission denied.');
    }

    public function test_admin_can_complete_setup_with_shared_default_credential(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/setup/complete', [
            'server_host' => '10.10.10.10',
            'server_port' => 22,
            'server_username' => 'root',
            'server_password' => 'secret',
            'drupal_project_path' => '/srv/www/winmap',
            'drupal_site_scheme' => 'https',
            'auth_site_domain' => 'enter.winmap.vn',
            'default_website_username' => 'administrator',
            'default_website_password' => 'shared-secret',
            'websites' => [
                [
                    'name' => 'Enter',
                    'domain' => 'enter.winmap.vn',
                    'usage_endpoint_url' => 'https://enter.winmap.vn/application/site-usage/json',
                    'config_endpoint_url' => 'https://enter.winmap.vn/application/site-usage/quota/config',
                    'credential_override' => false,
                    'enabled' => true,
                    'warning_threshold_percent' => 85,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('config.auth_site_domain', 'enter.winmap.vn');

        $setup = SetupConfiguration::query()->first();
        $website = MonitoredWebsite::query()->where('domain', 'enter.winmap.vn')->first();

        $this->assertNotNull($setup);
        $this->assertTrue((bool) $setup->is_completed);
        $this->assertSame('10.10.10.10', $setup->server_host);
        $this->assertSame('administrator', $setup->default_website_username);
        $this->assertTrue($setup->has_default_website_password);
        $this->assertNotNull($website);
        $this->assertNull($website->website_username);
        $this->assertFalse($website->has_website_password);
        $this->assertTrue($website->uses_default_credentials);
    }

    public function test_setup_can_mix_shared_default_credential_with_site_override(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/setup/complete', [
            'server_host' => '10.10.10.10',
            'server_port' => 22,
            'server_username' => 'root',
            'server_password' => 'secret',
            'drupal_project_path' => '/srv/www/winmap',
            'drupal_site_scheme' => 'https',
            'auth_site_domain' => 'aec.winmap.vn',
            'default_website_username' => 'administrator',
            'default_website_password' => 'shared-secret',
            'websites' => [
                [
                    'name' => 'AEC',
                    'domain' => 'aec.winmap.vn',
                    'usage_endpoint_url' => 'https://aec.winmap.vn/application/site-usage/json',
                    'config_endpoint_url' => 'https://aec.winmap.vn/application/site-usage/quota/config',
                    'credential_override' => false,
                    'enabled' => true,
                    'warning_threshold_percent' => 85,
                ],
                [
                    'name' => 'Autopex',
                    'domain' => 'autopex.winmap.vn',
                    'usage_endpoint_url' => 'https://autopex.winmap.vn/application/site-usage/json',
                    'config_endpoint_url' => 'https://autopex.winmap.vn/application/site-usage/quota/config',
                    'credential_override' => true,
                    'website_username' => 'administrator',
                    'website_password' => 'override-secret',
                    'enabled' => true,
                    'warning_threshold_percent' => 90,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('completed', true);

        $shared = MonitoredWebsite::query()->where('domain', 'aec.winmap.vn')->first();
        $override = MonitoredWebsite::query()->where('domain', 'autopex.winmap.vn')->first();

        $this->assertNotNull($shared);
        $this->assertNotNull($override);
        $this->assertTrue($shared->uses_default_credentials);
        $this->assertNull($shared->website_username);
        $this->assertSame('administrator', $override->website_username);
        $this->assertTrue($override->has_website_password);
        $this->assertFalse($override->uses_default_credentials);
    }

    public function test_admin_can_create_website_provision_run_step_by_step(): void
    {
        SetupConfiguration::query()->create([
            'is_completed' => true,
            'server_host' => '10.10.10.10',
            'server_port' => 22,
            'server_username' => 'root',
            'server_password' => 'secret',
            'drupal_project_path' => '/srv/www/winmap',
            'drupal_site_scheme' => 'https',
            'auth_site_domain' => 'enter.winmap.vn',
        ]);

        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/website-provision/runs', [
            'subdomain' => 'newcode',
            'source_database' => 'inventory',
            'website_username' => 'administrator',
            'website_password' => 'site-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.full_domain', 'newcode.winmap.vn')
            ->assertJsonPath('data.steps.0.key', 'create_subdomain')
            ->assertJsonCount(5, 'data.steps');
    }

    public function test_admin_can_run_all_provisioning_steps_and_register_website(): void
    {
        SetupConfiguration::query()->create([
            'is_completed' => true,
            'server_host' => '10.10.10.10',
            'server_port' => 22,
            'server_username' => 'root',
            'server_password' => 'secret',
            'drupal_project_path' => '/srv/www/winmap',
            'drupal_site_scheme' => 'https',
            'auth_site_domain' => 'enter.winmap.vn',
        ]);

        $admin = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $run = WebsiteProvisionRun::query()->create([
            'user_id' => $admin->id,
            'subdomain' => 'newcode',
            'parent_domain' => 'winmap.vn',
            'full_domain' => 'newcode.winmap.vn',
            'www_root' => 'httpdocs_inventory',
            'system_user' => 'ftp_winmap.vn',
            'source_database' => 'inventory',
            'mysql_password_file' => '/root/.mysql_pass',
            'ssl_registration_email' => 'admin@winmap.vn',
            'website_username' => 'administrator',
            'website_password' => 'site-secret',
            'status' => 'pending',
            'steps' => [
                ['key' => 'create_subdomain', 'label' => 'Tạo subdomain Plesk', 'status' => 'pending', 'output' => '', 'description' => '', 'command_preview' => 'a'],
                ['key' => 'install_ssl', 'label' => 'Cấp SSL', 'status' => 'pending', 'output' => '', 'description' => '', 'command_preview' => 'b'],
                ['key' => 'copy_directories', 'label' => 'Copy sites/init', 'status' => 'pending', 'output' => '', 'description' => '', 'command_preview' => 'c'],
                ['key' => 'modify_settings', 'label' => 'Sửa settings.php', 'status' => 'pending', 'output' => '', 'description' => '', 'command_preview' => 'd'],
                ['key' => 'create_and_clone_database', 'label' => 'Tạo DB và clone dữ liệu', 'status' => 'pending', 'output' => '', 'description' => '', 'command_preview' => 'e'],
            ],
        ]);

        $remote = Mockery::mock(RemoteServerService::class);
        $remote->shouldReceive('runManagedShellScript')->times(5)->andReturn([
            'stdout' => 'ok',
            'stderr' => '',
            'exit_code' => 0,
        ]);
        $this->app->instance(RemoteServerService::class, $remote);

        $response = $this->actingAs($admin)->postJson("/api/website-provision/runs/{$run->id}/run-all");

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $website = MonitoredWebsite::query()->where('domain', 'newcode.winmap.vn')->first();

        $this->assertNotNull($website);
        $this->assertSame('administrator', $website->website_username);
        $this->assertTrue($website->has_website_password);
    }
}
