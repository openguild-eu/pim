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

class ManageCrossSellProducts extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'crossSellProducts';

    protected static ?string $navigationLabel = 'Cross-sells';

    protected static ?string $breadcrumb = 'Cross-sells';

    protected static ?string $title = 'Cross-sells';

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
