<?php

declare(strict_types=1);

namespace Modules\Attributes\Providers;

use Illuminate\Support\ServiceProvider;

class AttributesServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
