<?php

declare(strict_types=1);

namespace Modules\Attributes\Filament\Resources\AttributeSets\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Attributes\Filament\Resources\AttributeSets\AttributeSetResource;

class ListAttributeSets extends ListRecords
{
    protected static string $resource = AttributeSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
