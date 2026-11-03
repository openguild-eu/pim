<?php

declare(strict_types=1);

namespace Modules\Channels\Filament\Resources\Channels\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Channels\Filament\Resources\Channels\ChannelResource;

class CreateChannel extends CreateRecord
{
    protected static string $resource = ChannelResource::class;
}
