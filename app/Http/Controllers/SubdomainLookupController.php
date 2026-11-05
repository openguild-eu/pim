<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subdomain;
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

            if ($subdomainExists) {
                return redirect()->to('/login');
            }

            return redirect()->away('http://'.$centralDomain.'/');
        }

        return view('subdomain-lookup');
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
