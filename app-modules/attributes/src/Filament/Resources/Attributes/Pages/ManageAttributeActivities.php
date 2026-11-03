<?php

declare(strict_types=1);

namespace Modules\Attributes\Filament\Resources\Attributes\Pages;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Modules\Attributes\Filament\Resources\Attributes\AttributeResource;

class ManageAttributeActivities extends ManageRelatedRecords
{
    protected static string $resource = AttributeResource::class;

    protected static string $relationship = 'activitiesAsSubject';

    protected static ?string $relatedResource = ActivityLogResource::class;

    protected static ?string $navigationLabel = 'History';

    protected static ?string $breadcrumb = 'History';

    protected static ?string $title = 'History';
}
