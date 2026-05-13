<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'monitored_website_id',
    'domain',
    'subdomain',
    'parent_domain',
    'project_path',
    'system_user',
    'database_name',
    'status',
    'current_step',
    'steps',
    'steps_payload',
    'last_error',
    'started_at',
    'completed_at',
])]
class WebsiteDeletionRun extends Model
{
    protected $appends = ['steps'];

    protected $hidden = ['steps_payload'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(MonitoredWebsite::class, 'monitored_website_id');
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
}
