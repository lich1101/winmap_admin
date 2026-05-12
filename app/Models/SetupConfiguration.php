<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'is_completed',
    'server_host',
    'server_port',
    'server_username',
    'server_password',
    'drupal_project_path',
    'drupal_site_scheme',
    'auth_site_domain',
    'default_website_username',
    'default_website_password',
])]
#[Hidden(['server_password', 'default_website_password'])]
class SetupConfiguration extends Model
{
    protected $appends = ['has_server_password', 'has_default_website_password'];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'server_port' => 'integer',
            'server_password' => 'encrypted',
            'default_website_password' => 'encrypted',
        ];
    }

    public function getHasServerPasswordAttribute(): bool
    {
        return ! empty($this->server_password);
    }

    public function getHasDefaultWebsitePasswordAttribute(): bool
    {
        return ! empty($this->default_website_password);
    }
}
