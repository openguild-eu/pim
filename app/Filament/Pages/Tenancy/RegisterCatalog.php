<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Catalog;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class RegisterCatalog extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register catalog';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        modifyRuleUsing: function (Unique $rule): Unique {
                            return $rule->where('subdomain_id', app('current_subdomain')->id);
                        },
                    ),
            ]);
    }

    protected function handleRegistration(array $data): Catalog
    {
        $catalog = Catalog::create($data);

        $catalog->users()->attach(auth()->user());

        return $catalog;
    }
}
