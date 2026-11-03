<?php

declare(strict_types=1);

namespace Modules\Channels\Providers;

use Illuminate\Support\ServiceProvider;

class ChannelsServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
