<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'status',
    'cwd',
    'command',
    'exit_code',
    'output',
    'duration_ms',
    'ip_address',
    'user_agent',
])]
class CommandLog extends Model
{
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
