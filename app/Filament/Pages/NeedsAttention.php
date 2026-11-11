<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Products\Enums\ProductIssue;
use Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use Modules\Products\Models\Product;

class NeedsAttention extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Needs Attention';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Needs Attention';

    protected string $view = 'filament.pages.needs-attention';

    /**
     * One pass over every tenant product, computing issues per product.
     * Memoized per request — both the base table query and the issue-type
     * filter need this map, and it must only run once. Same eager-load list
     * Product::issues() depends on (see its docblock).
     *
     * @var array<string, Collection<int, int>>|null issue value => product IDs
     */
    protected ?array $productIdsByIssueCache = null;

    /**
     * @return array<string, Collection<int, int>>
     */
    protected function productIdsByIssue(): array
    {
        if ($this->productIdsByIssueCache !== null) {
            return $this->productIdsByIssueCache;
        }

        $map = [];

        foreach (ProductIssue::cases() as $issue) {
            $map[$issue->value] = collect();
        }

        Product::query()
            ->with(['media', 'categories', 'attributeSet.attributes', 'attributeValues.attribute', 'channels'])
            ->get()
            ->each(function (Product $product) use (&$map): void {
                foreach ($product->issues() as $issue) {
                    $map[$issue->value]->push($product->id);
                }
            });

        return $this->productIdsByIssueCache = $map;
    }

    protected function allProductIdsWithIssues(): Collection
    {
        return collect($this->productIdsByIssue())
            ->flatten()
            ->unique()
            ->values();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Product::query()
                ->with(['media', 'categories', 'attributeSet.attributes', 'attributeValues.attribute', 'channels'])
                ->whereIn('id', $this->allProductIdsWithIssues()))
            ->columns([
                SpatieMediaLibraryImageColumn::make('images')
                    ->collection('images')
                    ->conversion('thumb')
                    ->limit(1),

                TextColumn::make('sku')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('issues')
                    ->label('Issues')
                    ->state(fn (Product $record): array => $record->issues()->all())
                    ->badge()
                    ->formatStateUsing(fn (ProductIssue $state): string => $state->getLabel())
                    ->color(fn (ProductIssue $state): string => $state->getColor())
                    ->icon(fn (ProductIssue $state): string|\BackedEnum => $state->getIcon()),
            ])
            ->filters([
                Filter::make('issue')
                    ->schema([
                        Select::make('types')
                            ->label('Issue type')
                            ->multiple()
                            ->options(ProductIssue::class),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $selectedTypes = $data['types'] ?? [];

                        if (empty($selectedTypes)) {
                            return $query;
                        }

                        $byIssue = $this->productIdsByIssue();

                        $ids = collect($selectedTypes)
                            ->flatMap(fn (string $type): Collection => $byIssue[$type] ?? collect())
                            ->unique();

                        return $query->whereIn('id', $ids);
                    }),
            ])
            ->recordUrl(fn (Product $record): string => EditProduct::getUrl(['record' => $record]));
    }
}
