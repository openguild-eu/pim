<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subdomain;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubdomainLookupController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $host = $request->getHost();
        $centralDomain = config('app.central_domain', 'pim.test');
        $subdomain = str_replace('.'.$centralDomain, '', $host);

        if ($host !== $centralDomain && ! empty($subdomain)) {
            $subdomainExists = Subdomain::query()
                ->where('subdomain', $subdomain)
                ->exists();

            if (! $subdomainExists) {
                return redirect()->away('http://'.$centralDomain.'/');
            }

            return $this->redirectIntoPanel($request);
        }

        return view('subdomain-lookup');
    }

    /**
     * The "/" route on a real subdomain never reaches the admin panel: the
     * panel only registers "/login", "/new" (tenant registration), and
     * tenant-prefixed routes, never an unprefixed root. So this decides
     * where an authenticated visitor belongs: their first Catalog tenant on
     * this Subdomain, the tenant registration page if they have none, or
     * the login page if they are a guest.
     */
    protected function redirectIntoPanel(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->to('/login');
        }

        $panel = Filament::getPanel('admin');

        $tenant = $user->getTenants($panel)->first();

        if ($tenant) {
            return redirect()->to('/'.rawurlencode($tenant->getAttributeValue($panel->getTenantSlugAttribute())));
        }

        if ($panel->hasTenantRegistration()) {
            return redirect()->to($panel->getTenantRegistrationUrl());
        }

        return redirect()->to('/login');
    }

    public function lookup(Request $request): RedirectResponse
    {
        $request->validate([
            'subdomain' => 'required|string|max:255',
        ]);

        $subdomain = Subdomain::where('subdomain', $request->input('subdomain'))->first();

        if (! $subdomain) {
            return back()->withErrors([
                'subdomain' => 'Subdomain not found. Please check and try again.',
            ])->withInput();
        }

        $centralDomain = config('app.central_domain', 'pim.test');
        $url = 'http://'.$subdomain->subdomain.'.'.$centralDomain.'/login';

        return redirect()->away($url);
    }
}
