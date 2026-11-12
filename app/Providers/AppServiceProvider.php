<?php

namespace App\Providers;

use App\Auth\SubdomainUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Users\Models\User;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\OpenIDConnect\Provider as OpenIDConnectProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // This app has no `App\Models\User`; it uses the modular
        // `Modules\Users\Models\User`. Filament's `Export::user()`
        // relationship (and other first-party packages) resolve the
        // authenticatable model via this binding, per
        // vendor/filament/actions/docs/12-export.md "Using a custom user model".
        $this->app->bind(Authenticatable::class, User::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('subdomain', function ($app, array $config) {
            return new SubdomainUserProvider($app['hash'], $config['model']);
        });

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('openidconnect', OpenIDConnectProvider::class);
        });
    }
}
