<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'domain',
    'usage_endpoint_url',
    'config_endpoint_url',
    'api_key',
    'website_username',
    'website_password',
    'quota_bytes',
    'warning_threshold_percent',
    'enabled',
    'last_status',
    'last_sync_status',
    'last_error',
    'last_sync_error',
    'last_disk_bytes',
    'last_database_bytes',
    'last_project_bytes',
    'last_usage_percent',
    'last_is_blocked',
    'last_is_warning',
    'last_checked_at',
    'last_synced_at',
    'notes',
    'discovery_root',
    'discovery_conf_path',
])]
#[Hidden(['api_key', 'website_password'])]
class MonitoredWebsite extends Model
{
    protected $appends = ['has_api_key', 'has_website_password', 'uses_default_credentials'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'quota_bytes' => 'integer',
            'warning_threshold_percent' => 'integer',
            'last_disk_bytes' => 'integer',
            'last_database_bytes' => 'integer',
            'last_project_bytes' => 'integer',
            'last_usage_percent' => 'float',
            'last_is_blocked' => 'boolean',
            'last_is_warning' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'api_key' => 'encrypted',
            'website_password' => 'encrypted',
        ];
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(UsageSnapshot::class);
    }

    public function getHasApiKeyAttribute(): bool
    {
        return ! empty($this->api_key);
    }

    public function getHasWebsitePasswordAttribute(): bool
    {
        return ! empty($this->website_password);
    }

    public function getUsesDefaultCredentialsAttribute(): bool
    {
        return empty($this->website_username) && empty($this->website_password);
    }
}
