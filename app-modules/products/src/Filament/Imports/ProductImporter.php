<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Imports;

use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Str;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;

class ProductImporter extends Importer
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku')
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            ImportColumn::make('slug')
                ->rules(['nullable', 'max:255'])
                ->fillRecordUsing(function (Product $record, ?string $state, array $data): void {
                    $record->slug = filled($state) ? $state : Str::slug((string) $data['name']);
                }),

            ImportColumn::make('status')
                ->rules(['required', 'in:draft,published']),

            ImportColumn::make('price')
                ->numeric(decimalPlaces: 0)
                ->rules(['nullable', 'numeric'])
                ->helperText('Whole cents, not dollars — e.g. 1999 for $19.99.'),

            ImportColumn::make('description')
                ->rules(['nullable']),

            ImportColumn::make('category_slugs')
                ->rules(['nullable'])
                // `category_slugs` is not a real column on `products` — its
                // value is read from $this->data in afterSave() instead.
                // Without an explicit fillRecordUsing(), ImportColumn::fillRecord()
                // falls through to `$record->category_slugs = $state`, which
                // sets a non-existent attribute that then gets inserted as a
                // phantom column and fails at the database. A no-op closure
                // is the only way to keep this column mapped/validated while
                // never touching the record.
                ->fillRecordUsing(fn (): null => null),
        ];
    }

    public function resolveRecord(): ?Product
    {
        return Product::firstOrNew([
            'sku' => $this->data['sku'],
        ]);
    }

    protected function afterSave(): void
    {
        $slugsCell = $this->data['category_slugs'] ?? null;

        if (blank($slugsCell)) {
            return;
        }

        $slugs = array_values(array_filter(array_map('trim', explode(',', (string) $slugsCell))));

        if ($slugs === []) {
            return;
        }

        $categories = Category::query()->whereIn('slug', $slugs)->get();

        $this->record->categories()->sync($categories->pluck('id'));
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your product import has completed and '
            .number_format($import->successful_rows).' '
            .str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '
                .str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
