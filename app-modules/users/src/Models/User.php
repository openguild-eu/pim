<?php

declare(strict_types=1);

namespace Modules\Users\Models;

use App\Models\Catalog;
use App\Models\Scopes\TenantScope;
use App\Models\Subdomain;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Modules\Users\Database\Factories\UserFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class User extends Authenticatable implements FilamentUser, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'subdomain_id',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * @return BelongsTo<Subdomain, $this>
     */
    public function subdomain(): BelongsTo
    {
        return $this->belongsTo(Subdomain::class, 'subdomain_id');
    }

    /**
     * @return BelongsToMany<Catalog, $this>
     */
    public function catalogs(): BelongsToMany
    {
        return $this->belongsToMany(Catalog::class, 'catalog_user');
    }

    /**
     * @return Collection<int, Catalog>
     */
    public function getTenants(Panel $panel): Collection
    {
        if (app()->has('current_subdomain')) {
            return $this->catalogs()->whereRaw('catalogs.subdomain_id = ?', [app('current_subdomain')->id])->get();
        }

        return $this->catalogs()->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->catalogs()->whereKey($tenant)->exists();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (User $user): void {
            if (! $user->subdomain_id && app()->has('current_subdomain')) {
                $user->subdomain_id = app('current_subdomain')->id;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => "User {$eventName}");
    }

    public function getActivityLogTitle(): string
    {
        return (string) ($this->name ?: $this->email ?: "User #{$this->getKey()}");
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
