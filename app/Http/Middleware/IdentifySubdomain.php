<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Subdomain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class IdentifySubdomain
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $centralDomain = config('app.central_domain', 'pim.test');

        // Force the URL to use the current request's scheme and host
        URL::forceRootUrl($request->getSchemeAndHttpHost());

        // Set asset URL to current scheme and host
        config(['app.asset_url' => $request->getSchemeAndHttpHost()]);

        // Extract subdomain from host
        $subdomain = str_replace('.'.$centralDomain, '', $host);

        // If accessing central domain, allow only specific routes
        if ($subdomain === $centralDomain || empty($subdomain)) {
            // Block all Filament routes on central domain
            if ($request->is('login') || $request->is('logout') || $request->is('sso/*') || str_starts_with($request->path(), 'filament')) {
                abort(404);
            }

            return $next($request);
        }

        // Find subdomain in database
        $subdomainModel = Subdomain::where('subdomain', $subdomain)->first();

        if (! $subdomainModel) {
            return redirect()->away('http://'.$centralDomain.'/');
        }

        // Store in container as instance
        app()->instance('current_subdomain', $subdomainModel);
        app(PermissionRegistrar::class)->setPermissionsTeamId($subdomainModel->id);

        return $next($request);
    }
}
