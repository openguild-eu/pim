<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Database\Factories\CatalogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Users\Models\User;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Catalog extends Model
{
    /** @use HasFactory<CatalogFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'subdomain_id',
        'name',
    ];

    /**
     * @return BelongsTo<Subdomain, $this>
     */
    public function subdomain(): BelongsTo
    {
        return $this->belongsTo(Subdomain::class, 'subdomain_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'catalog_user');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('catalog')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => "Catalog {$eventName}");
    }

    public function getActivityLogTitle(): string
    {
        return (string) ($this->name ?: "Catalog #{$this->getKey()}");
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Catalog $catalog) {
            if (! $catalog->subdomain_id && app()->has('current_subdomain')) {
                $catalog->subdomain_id = app('current_subdomain')->id;
            }
        });
    }
}
