<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogs;

use AlizHarb\ActivityLog\Resources\ActivityLogs\ActivityLogResource as BaseActivityLogResource;
use AlizHarb\ActivityLog\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\Catalog;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Categories\Models\Category;
use Modules\Channels\Models\Channel;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class ActivityLogResource extends BaseActivityLogResource
{
    protected static bool $isScopedToTenant = false;

    /**
     * The vendor table's "prune" header action is global by default — it
     * would otherwise also appear on every per-record History tab (which
     * reuses this same table via `ManageRelatedRecords`), not just the main
     * `/activity-logs` list. Pruning by date only makes sense from the main
     * list, so it's hidden everywhere else.
     */
    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        foreach ($table->getHeaderActions() as $action) {
            if ($action->getName() === 'prune') {
                $action->hidden(fn ($livewire): bool => ! $livewire instanceof ListActivityLogs);
            }
        }

        return $table;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Catalog) {
            return $query;
        }

        return static::scopeEloquentQueryToTenant($query, $tenant);
    }

    /**
     * Every model that gets `LogsActivity` must be added here, with a shape
     * depending on how it relates to a Catalog — see `blueprints/pim.md`
     * "Activity History Convention" for the three shapes this can take.
     */
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        if (! $tenant instanceof Catalog) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($tenant): void {
            $query->whereHasMorph('subject', [Catalog::class], function (Builder $catalogQuery) use ($tenant): void {
                $catalogQuery->whereKey($tenant->getKey());
            })
                ->orWhereHasMorph('subject', [User::class], function (Builder $userQuery) use ($tenant): void {
                    $userQuery->whereHas('catalogs', function (Builder $catalogsQuery) use ($tenant): void {
                        $catalogsQuery->whereKey($tenant->getKey());
                    });
                })
                ->orWhereHasMorph('subject', [Attribute::class], function (Builder $attributeQuery) use ($tenant): void {
                    $attributeQuery->where('catalog_id', $tenant->getKey());
                })
                ->orWhereHasMorph('subject', [AttributeSet::class], function (Builder $attributeSetQuery) use ($tenant): void {
                    $attributeSetQuery->where('catalog_id', $tenant->getKey());
                })
                ->orWhereHasMorph('subject', [Category::class], function (Builder $categoryQuery) use ($tenant): void {
                    $categoryQuery->where('catalog_id', $tenant->getKey());
                })
                ->orWhereHasMorph('subject', [Product::class], function (Builder $productQuery) use ($tenant): void {
                    $productQuery->where('catalog_id', $tenant->getKey());
                })
                ->orWhereHasMorph('subject', [Channel::class], function (Builder $channelQuery) use ($tenant): void {
                    $channelQuery->where('catalog_id', $tenant->getKey());
                });
        });
    }
}
