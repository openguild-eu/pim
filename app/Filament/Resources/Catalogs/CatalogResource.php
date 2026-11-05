<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalogs;

use App\Filament\Resources\Catalogs\Pages\CreateCatalog;
use App\Filament\Resources\Catalogs\Pages\EditCatalog;
use App\Filament\Resources\Catalogs\Pages\ListCatalogs;
use App\Models\Catalog;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Modules\Users\Models\User;

class CatalogResource extends Resource
{
    protected static ?string $model = Catalog::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Catalog is the panel's tenant model itself, so Filament's Catalog
     * tenancy cannot scope it. Catalogs are isolated per Subdomain by their
     * own TenantScope instead.
     */
    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                        if ($operation === 'create') {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule): Unique {
                            return $rule->where('subdomain_id', app('current_subdomain')->id);
                        },
                    )
                    ->helperText('Used in the catalog switcher URL. Lowercase letters, numbers, and dashes only.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogs::route('/'),
            'create' => CreateCatalog::route('/create'),
            'edit' => EditCatalog::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
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
