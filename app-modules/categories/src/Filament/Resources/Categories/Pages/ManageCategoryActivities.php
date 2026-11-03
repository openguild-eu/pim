<?php

declare(strict_types=1);

namespace Modules\Categories\Filament\Resources\Categories\Pages;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Modules\Categories\Filament\Resources\Categories\CategoryResource;

class ManageCategoryActivities extends ManageRelatedRecords
{
    protected static string $resource = CategoryResource::class;

    protected static string $relationship = 'activitiesAsSubject';

    protected static ?string $relatedResource = ActivityLogResource::class;

    protected static ?string $navigationLabel = 'History';

    protected static ?string $breadcrumb = 'History';

    protected static ?string $title = 'History';
}
