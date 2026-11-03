<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Products\Filament\Resources\Products\ProductResource;

class ManageRelatedProducts extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'relatedProducts';

    protected static ?string $navigationLabel = 'Related Products';

    protected static ?string $breadcrumb = 'Related Products';

    protected static ?string $title = 'Related Products';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->reorderable('position')
            ->columns([
                TextColumn::make('sku')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('price')
                    ->money(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->recordSelectSearchColumns(['sku', 'name'])
                    ->recordSelectOptionsQuery(
                        fn (Builder $query): Builder => $query->whereKeyNot($this->getOwnerRecord()->getKey()),
                    ),
            ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
