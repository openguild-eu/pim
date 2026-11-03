<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Carbon;
use Modules\Products\Enums\ProductStatus;
use Modules\Products\Models\Product;

class ProductExporter extends Exporter
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('sku'),

            ExportColumn::make('name'),

            ExportColumn::make('status')
                // `status` is cast to the ProductStatus enum on the model
                // (Product::casts()), so the column's state is an enum
                // instance, not the raw string.
                ->formatStateUsing(fn (ProductStatus|string $state): string => ucfirst($state instanceof ProductStatus ? $state->value : $state)),

            ExportColumn::make('price')
                ->formatStateUsing(fn (int|float|null $state): string => $state === null ? '' : number_format($state / 100, 2)),

            ExportColumn::make('attributeSet.name')
                ->label('Attribute Set'),

            ExportColumn::make('categories')
                ->label('Categories')
                ->state(fn (Product $record): string => $record->categories->pluck('name')->join(', ')),

            ExportColumn::make('created_at')
                ->formatStateUsing(fn (Carbon $state): string => $state->format('Y-m-d H:i')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your product export has completed and '
            .number_format($export->successful_rows).' '
            .str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '
                .str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
