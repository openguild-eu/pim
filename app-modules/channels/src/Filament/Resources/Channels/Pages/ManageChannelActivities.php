<?php

declare(strict_types=1);

namespace Modules\Channels\Filament\Resources\Channels\Pages;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Modules\Channels\Filament\Resources\Channels\ChannelResource;

class ManageChannelActivities extends ManageRelatedRecords
{
    protected static string $resource = ChannelResource::class;

    protected static string $relationship = 'activitiesAsSubject';

    protected static ?string $relatedResource = ActivityLogResource::class;

    protected static ?string $navigationLabel = 'History';

    protected static ?string $breadcrumb = 'History';

    protected static ?string $title = 'History';
}
