<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class CatalogScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $currentTenant = Filament::getTenant();

        if ($currentTenant && $model->getTable() !== 'catalogs') {
            $builder->where($model->qualifyColumn('catalog_id'), $currentTenant->getKey());
        }
    }
}
