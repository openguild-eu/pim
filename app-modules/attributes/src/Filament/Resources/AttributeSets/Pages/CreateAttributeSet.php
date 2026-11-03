<?php

declare(strict_types=1);

namespace Modules\Attributes\Filament\Resources\AttributeSets\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Attributes\Filament\Resources\AttributeSets\AttributeSetResource;

class CreateAttributeSet extends CreateRecord
{
    protected static string $resource = AttributeSetResource::class;
}
