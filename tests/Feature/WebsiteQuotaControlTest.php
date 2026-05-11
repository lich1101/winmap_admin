<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DrupalAuthenticationService;
use App\Services\DrupalSiteDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Mockery;
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
}
