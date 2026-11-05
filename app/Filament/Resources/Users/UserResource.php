<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\CatalogsRelationManager;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Modules\Users\Models\User;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Users are isolated per Subdomain by their own TenantScope, not by
     * Filament's Catalog tenancy, so this resource must be reachable
     * without a Catalog being selected.
     */
    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->required()
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->confirmed()
                    ->minLength(8)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->visibleOn('create'),

                TextInput::make('password_confirmation')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(false)
                    ->visibleOn('create'),

                Toggle::make('is_admin')
                    ->label('Administrator')
                    ->helperText('Can manage Users and Catalogs for this subdomain.')
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Toggle $component, ?User $record): void {
                        $component->state($record?->hasRole('admin') ?? false);
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles.name')
                    ->badge()
                    ->label('Role')
                    ->placeholder('—'),

                TextColumn::make('catalogs_count')
                    ->counts('catalogs')
                    ->label('Catalogs')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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

    public static function getRelations(): array
    {
        return [
            CatalogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function canViewAny(): bool
    {
        return static::currentUserIsAdmin();
    }

    public static function canView(Model $record): bool
    {
        return static::currentUserIsAdmin();
    }

    public static function canCreate(): bool
    {
        return static::currentUserIsAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return static::currentUserIsAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return static::currentUserIsAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return static::currentUserIsAdmin();
    }

    /**
     * The single authorization rule for this resource: the authenticated
     * user holds the `admin` role in the current permissions team
     * (Subdomain).
     */
    protected static function currentUserIsAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole('admin');
    }
}
