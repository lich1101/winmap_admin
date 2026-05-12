<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'subdomain',
    'parent_domain',
    'full_domain',
    'www_root',
    'system_user',
    'source_database',
    'mysql_password_file',
    'ssl_registration_email',
    'website_username',
    'website_password',
    'status',
    'current_step',
    'steps',
    'steps_payload',
    'last_error',
    'started_at',
    'completed_at',
])]
class WebsiteProvisionRun extends Model
{
    protected $appends = ['steps', 'has_website_password'];

    protected $hidden = ['website_password', 'steps_payload'];

    protected function casts(): array
    {
        return [
            'website_password' => 'encrypted',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getStepsAttribute(): array
    {
        $decoded = json_decode((string) $this->steps_payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function setStepsAttribute(array $steps): void
    {
        $this->attributes['steps_payload'] = json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getHasWebsitePasswordAttribute(): bool
    {
        return ! empty($this->website_password);
    }
}
