<?php

declare(strict_types=1);

namespace Modules\Channels\Filament\Resources\Channels\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ApiKeysRelationManager extends RelationManager
{
    protected static string $relationship = 'apiKeys';

    protected static ?string $title = 'API Keys';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Toggle::make('is_active')
                    ->default(true),

                DateTimePicker::make('expires_at'),

                TagsInput::make('allowed_ips')
                    ->helperText('Leave empty to allow any IP address.'),
            ]);
    }

    public function table(Table $table): Table
    {
        $plainTextKey = null;

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_used_at')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data) use (&$plainTextKey): array {
                        $plainTextKey = Str::random(64);
                        $data['key_hash'] = hash('sha256', $plainTextKey);

                        return $data;
                    })
                    ->after(function () use (&$plainTextKey): void {
                        Notification::make()
                            ->title('API key created')
                            ->body("Copy this key now — it will not be shown again:\n\n{$plainTextKey}")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
