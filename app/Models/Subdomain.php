<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubdomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Users\Models\User;

class Subdomain extends Model
{
    /** @use HasFactory<SubdomainFactory> */
    use HasFactory;

    protected $fillable = [
        'subdomain',
        'name',
        'sso_enabled',
        'sso_issuer',
        'sso_client_id',
        'sso_client_secret',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'sso_client_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sso_enabled' => 'boolean',
            'sso_client_secret' => 'encrypted',
        ];
    }

    /**
     * Whether SSO is usable for this tenant right now. The single canonical
     * rule for "is SSO configured" — reused by the login page button
     * visibility, the `/sso/redirect` route guard, and the settings page, so
     * none of those ever re-derive this condition independently.
     */
    public function hasSsoConfigured(): bool
    {
        return $this->sso_enabled
            && filled($this->sso_issuer)
            && filled($this->sso_client_id)
            && filled($this->sso_client_secret);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'subdomain_id');
    }

    /**
     * @return HasMany<Catalog, $this>
     */
    public function catalogs(): HasMany
    {
        return $this->hasMany(Catalog::class, 'subdomain_id');
    }
}
