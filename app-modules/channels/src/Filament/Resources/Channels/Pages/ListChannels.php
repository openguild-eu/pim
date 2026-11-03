<?php

declare(strict_types=1);

namespace Modules\Channels\Filament\Resources\Channels\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Channels\Filament\Resources\Channels\ChannelResource;

class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
