<?php

namespace App\Providers;

use App\Auth\SubdomainUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('subdomain', function ($app, array $config) {
            return new SubdomainUserProvider($app['hash'], $config['model']);
        });
    }
}
