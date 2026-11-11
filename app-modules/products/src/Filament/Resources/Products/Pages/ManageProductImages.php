<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Modules\Products\Filament\Resources\Products\ProductResource;

class ManageProductImages extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected static ?string $navigationLabel = 'Images';

    protected static ?string $breadcrumb = 'Images';

    protected static ?string $title = 'Images';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                SpatieMediaLibraryFileUpload::make('images')
                    ->collection('images')
                    ->conversion('thumb')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->columnSpanFull(),
            ]);
    }
}
