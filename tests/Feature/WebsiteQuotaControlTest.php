<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DrupalSiteDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
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
}
