<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'monitored_website_id',
    'status',
    'disk_bytes',
    'disk_allocated_bytes',
    'database_bytes',
    'project_bytes',
    'usage_percent',
    'error',
    'payload',
    'checked_at',
])]
class UsageSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'disk_bytes' => 'integer',
            'disk_allocated_bytes' => 'integer',
            'database_bytes' => 'integer',
            'project_bytes' => 'integer',
            'usage_percent' => 'float',
            'payload' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(MonitoredWebsite::class, 'monitored_website_id');
    }
}
