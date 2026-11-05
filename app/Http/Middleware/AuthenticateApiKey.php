<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Scopes\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Modules\Channels\Models\ApiKey;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Api-Key') ?? $request->bearerToken();

        if (! $token) {
            return $this->unauthorized();
        }

        $apiKey = ApiKey::query()
            ->where('key_hash', hash('sha256', $token))
            ->with(['channel.catalog' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)])
            ->first();

        if (! $apiKey || ! $apiKey->is_active) {
            return $this->unauthorized();
        }

        if ($apiKey->expires_at && $apiKey->expires_at->isPast()) {
            return $this->unauthorized();
        }

        $allowedIps = $apiKey->allowed_ips ?? [];

        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            return $this->unauthorized();
        }

        if (! app()->has('current_subdomain')) {
            return $this->unauthorized();
        }

        $channel = $apiKey->channel;

        if (! $channel || ! $channel->is_active) {
            return $this->unauthorized();
        }

        if (! $channel->catalog || $channel->catalog->subdomain_id !== app('current_subdomain')->id) {
            return $this->unauthorized();
        }

        $apiKey->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('channel', $channel);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Invalid or missing API key.'], Response::HTTP_UNAUTHORIZED);
    }
}
