<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Modules\Products\Filament\Resources\Products\ProductResource;

class ManageProductActivities extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'activitiesAsSubject';

    protected static ?string $relatedResource = ActivityLogResource::class;

    protected static ?string $navigationLabel = 'History';

    protected static ?string $breadcrumb = 'History';

    protected static ?string $title = 'History';
}
