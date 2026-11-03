<?php

declare(strict_types=1);

namespace Modules\Attributes\Filament\Resources\Attributes\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Attributes\Filament\Resources\Attributes\AttributeResource;

class CreateAttribute extends CreateRecord
{
    protected static string $resource = AttributeResource::class;
}
