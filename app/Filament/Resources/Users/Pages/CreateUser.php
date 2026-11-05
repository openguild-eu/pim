<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * `is_admin` is not dehydrated into `$data`, so it is read from the raw
     * form state and applied as the `admin` role after the User exists.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $isAdmin = (bool) ($this->data['is_admin'] ?? false);

        $user = static::getModel()::create($data);

        $user->syncRoles($isAdmin ? ['admin'] : []);

        return $user;
    }
}
