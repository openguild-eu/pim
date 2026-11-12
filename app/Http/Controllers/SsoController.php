<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subdomain;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Modules\Users\Models\User;
use Symfony\Component\HttpFoundation\Response;

class SsoController extends Controller
{
    public function redirect(): Response
    {
        $subdomain = $this->currentSsoConfiguredSubdomain();

        $this->configureDriver($subdomain);

        return Socialite::driver('openidconnect')->redirect();
    }

    public function callback(): RedirectResponse
    {
        $subdomain = $this->currentSsoConfiguredSubdomain();

        $this->configureDriver($subdomain);

        try {
            $oauthUser = Socialite::driver('openidconnect')->user();
        } catch (\Throwable) {
            return redirect()
                ->route('filament.admin.auth.login')
                ->with('sso_error', __('Unable to sign in with SSO. Please try again or use your password.'));
        }

        $emailVerified = (bool) ($oauthUser->user['email_verified'] ?? false);

        if (blank($oauthUser->getEmail()) || ! $emailVerified) {
            return redirect()
                ->route('filament.admin.auth.login')
                ->with('sso_error', __('Your identity provider did not confirm a verified email address.'));
        }

        $user = User::query()
            ->where('email', $oauthUser->getEmail())
            ->where('subdomain_id', $subdomain->id)
            ->first();

        if (! $user) {
            return redirect()
                ->route('filament.admin.auth.login')
                ->with('sso_error', __('No account was found for your email address.'));
        }

        Auth::guard('web')->login($user);

        request()->session()->regenerate();

        // Filament::getUrl() (not a hardcoded route()) because the `admin`
        // panel is tenant-scoped (->tenant(Catalog::class, ...)): its
        // dashboard route requires a {tenant:slug} parameter, which
        // Filament::getUrl() resolves from the user's default tenant. This
        // is the exact mechanism Filament's own LoginResponse uses after a
        // successful password login (see
        // Filament\Auth\Http\Responses\LoginResponse::toResponse()).
        return redirect()->intended(Filament::getUrl());
    }

    private function currentSsoConfiguredSubdomain(): Subdomain
    {
        abort_unless(app()->has('current_subdomain'), 404);

        /** @var Subdomain $subdomain */
        $subdomain = app('current_subdomain');

        abort_unless($subdomain->hasSsoConfigured(), 404);

        return $subdomain;
    }

    /**
     * Dynamic, per-tenant Socialite config. Safe under standard PHP-FPM
     * (fresh container per request). Would need revisiting under Octane,
     * where a cached driver instance could leak one tenant's secret into
     * another tenant's request.
     */
    private function configureDriver(Subdomain $subdomain): void
    {
        config([
            'services.openidconnect' => [
                'base_url' => $subdomain->sso_issuer,
                'client_id' => $subdomain->sso_client_id,
                'client_secret' => $subdomain->sso_client_secret,
                'redirect' => route('sso.callback'),
            ],
        ]);
    }
}
