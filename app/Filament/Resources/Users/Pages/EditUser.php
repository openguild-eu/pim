<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * `is_admin` is not dehydrated into the saved data, so it is read from
     * the raw form state and applied as the `admin` role after saving.
     */
    protected function afterSave(): void
    {
        $isAdmin = (bool) ($this->data['is_admin'] ?? false);

        $this->record->syncRoles($isAdmin ? ['admin'] : []);
    }
}
