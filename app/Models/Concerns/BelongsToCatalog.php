<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\CatalogScope;

trait BelongsToCatalog
{
    protected static function bootBelongsToCatalog(): void
    {
        static::addGlobalScope(new CatalogScope);
    }
}
