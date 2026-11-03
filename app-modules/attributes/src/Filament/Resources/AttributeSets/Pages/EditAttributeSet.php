<?php

declare(strict_types=1);

namespace Modules\Attributes\Filament\Resources\AttributeSets\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Attributes\Filament\Resources\AttributeSets\AttributeSetResource;

class EditAttributeSet extends EditRecord
{
    protected static string $resource = AttributeSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
