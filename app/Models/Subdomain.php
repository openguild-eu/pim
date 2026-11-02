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
    ];

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
