<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalogs\Pages;

use App\Filament\Resources\Catalogs\CatalogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCatalog extends CreateRecord
{
    protected static string $resource = CatalogResource::class;
}
