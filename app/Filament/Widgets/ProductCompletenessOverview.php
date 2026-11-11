<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\NeedsAttention;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Products\Models\Product;

class ProductCompletenessOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        // Single eager-loaded pass — completenessPercentage() and
        // missingRequiredAttributes() both read from these relations rather
        // than querying (see product-completeness.md), so this avoids N+1
        // across every product on every widget render.
        $products = Product::query()
            ->with(['attributeSet.attributes', 'attributeValues.attribute'])
            ->get();

        $totalProducts = $products->count();

        $averageCompleteness = $totalProducts > 0
            ? (int) round($products->avg(fn (Product $product): int => $product->completenessPercentage()))
            : 100;

        $missingRequiredAttributes = $products
            ->filter(fn (Product $product): bool => $product->missingRequiredAttributes()->isNotEmpty())
            ->count();

        $missingImages = Product::query()
            ->whereDoesntHave('media', fn ($query) => $query->where('collection_name', 'images'))
            ->count();

        $missingCategories = Product::query()
            ->doesntHave('categories')
            ->count();

        $needsAttentionUrl = NeedsAttention::getUrl();

        return [
            Stat::make('Average Completeness', "{$averageCompleteness}%")
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color(match (true) {
                    $averageCompleteness === 100 => 'success',
                    $averageCompleteness >= 50 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Missing Required Attributes', (string) $missingRequiredAttributes)
                ->description("out of {$totalProducts} products")
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($missingRequiredAttributes > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                    'onclick' => "window.location='{$needsAttentionUrl}'",
                ]),

            Stat::make('Missing Images', (string) $missingImages)
                ->icon(Heroicon::OutlinedPhoto)
                ->color($missingImages > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                    'onclick' => "window.location='{$needsAttentionUrl}'",
                ]),

            Stat::make('Missing Categories', (string) $missingCategories)
                ->icon(Heroicon::OutlinedTag)
                ->color($missingCategories > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                    'onclick' => "window.location='{$needsAttentionUrl}'",
                ]),
        ];
    }
}
