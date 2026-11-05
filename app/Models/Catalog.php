<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Database\Factories\CatalogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
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
        'slug',
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

            if (! $catalog->slug) {
                $catalog->slug = static::resolveUniqueSlug((string) $catalog->name, $catalog->subdomain_id);
            }
        });
    }

    /**
     * Derive a collision-safe slug from $name within the given Subdomain,
     * appending -2, -3, ... until it no longer collides. Used both here
     * (auto-derivation for new Catalogs, e.g. via RegisterCatalog's
     * name-only form) and by the slug-backfill migration, so the
     * collision-resolution algorithm only exists in one place.
     */
    public static function resolveUniqueSlug(string $name, ?int $subdomainId, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (
            static::query()->withoutGlobalScopes()
                ->where('subdomain_id', $subdomainId)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
