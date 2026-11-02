<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class SubdomainUserProvider extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = $this->newModelQuery();

        $currentSubdomain = app('current_subdomain');
        if ($currentSubdomain) {
            $query->whereRaw('subdomain_id = ?', [$currentSubdomain->id]);
        }

        foreach ($credentials as $key => $value) {
            if (! is_string($key) || ! str_contains($key, 'password')) {
                $query->where($key, $value);
            }
        }

        $user = $query->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $currentSubdomain = app('current_subdomain');
        if ($currentSubdomain && isset($user->subdomain_id) && $user->subdomain_id !== $currentSubdomain->id) {
            return false;
        }

        return parent::validateCredentials($user, $credentials);
    }
}
