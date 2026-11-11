<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Products\Filament\Resources\Products\ProductResource;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;
}
