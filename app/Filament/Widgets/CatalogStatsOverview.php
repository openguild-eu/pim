<?php

namespace App\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Attributes\Models\AttributeSet;
use Modules\Categories\Models\Category;
use Modules\Channels\Models\Channel;
use Modules\Products\Models\Product;

class CatalogStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        return [
            Stat::make('Products', (string) Product::query()->count())
                ->icon(Heroicon::OutlinedCube),

            Stat::make('Categories', (string) Category::query()->count())
                ->icon(Heroicon::OutlinedRectangleGroup),

            Stat::make('Attribute Sets', (string) AttributeSet::query()->count())
                ->icon(Heroicon::OutlinedListBullet),

            Stat::make('Channels', (string) Channel::query()->count())
                ->icon(Heroicon::OutlinedGlobeAlt),
        ];
    }
}
