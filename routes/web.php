<?php

use App\Http\Controllers\SsoController;
use App\Http\Controllers\SubdomainLookupController;
use Illuminate\Support\Facades\Route;

Route::domain(config('app.central_domain', 'pim.test'))->group(function (): void {
    Route::get('/', [SubdomainLookupController::class, 'show'])->name('subdomain.show');
    Route::post('/lookup', [SubdomainLookupController::class, 'lookup'])->name('subdomain.lookup');
});

// Tenant-subdomain SSO routes. `IdentifySubdomain` is already appended to the
// global `web` middleware group (see bootstrap/app.php), so these plain
// routes get subdomain resolution, URL::forceRootUrl(), and the
// `current_subdomain` container binding automatically — no extra middleware
// needed here. They must stay outside the Route::domain(...) central-domain
// group above, since they only make sense on a tenant subdomain.
Route::get('/sso/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
Route::get('/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
