<?php

declare(strict_types=1);

namespace Modules\Channels\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Channels\Database\Factories\ApiKeyFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'channel_id',
        'name',
        'key_hash',
        'is_active',
        'last_used_at',
        'expires_at',
        'allowed_ips',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'allowed_ips' => 'array',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('api_key')
            ->logOnly(['name', 'is_active', 'last_used_at', 'expires_at', 'allowed_ips'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->name;
    }
}
