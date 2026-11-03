<?php

declare(strict_types=1);

namespace Modules\Categories\Filament\Resources\Categories;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Modules\Categories\Filament\Resources\Categories\Pages\CreateCategory;
use Modules\Categories\Filament\Resources\Categories\Pages\EditCategory;
use Modules\Categories\Filament\Resources\Categories\Pages\ListCategories;
use Modules\Categories\Filament\Resources\Categories\Pages\ManageCategoryActivities;
use Modules\Categories\Filament\Resources\Categories\Pages\ManageSubcategories;
use Modules\Categories\Models\Category;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('parent_id')
                    ->label('Parent category')
                    ->relationship(
                        name: 'parent',
                        titleAttribute: 'name',
                        modifyQueryUsing: function (Builder $query, ?Category $record): Builder {
                            if (! $record) {
                                return $query;
                            }

                            return $query->whereKeyNot([$record->getKey(), ...$record->descendantIds()]);
                        },
                    )
                    ->searchable()
                    ->preload(),

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
                    ->scopedUnique(ignoreRecord: true)
                    ->helperText('Used in URLs. Lowercase letters, numbers, and dashes only.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('parent_id'))
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('children_count')
                    ->counts('children')
                    ->label('Subcategories')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->before(function (DeleteAction $action, Category $record): void {
                        if ($record->children()->exists()) {
                            Notification::make()
                                ->danger()
                                ->title('Cannot delete — this category still has subcategories')
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            $blocked = $records->filter(fn (Category $record): bool => $record->children()->exists());

                            if ($blocked->isNotEmpty()) {
                                Notification::make()
                                    ->danger()
                                    ->title('Cannot delete — the following categories still have subcategories')
                                    ->body($blocked->pluck('name')->join(', '))
                                    ->send();

                                $action->halt();
                            }
                        }),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Without this, a trashed Category's Edit/Subcategories routes 404
     * (the default query excludes soft-deleted rows), so RestoreAction
     * and ForceDeleteAction could never actually be reached.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
            'subcategories' => ManageSubcategories::route('/{record}/subcategories'),
            'activities' => ManageCategoryActivities::route('/{record}/activities'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditCategory::class,
            ManageSubcategories::class,
            ManageCategoryActivities::class,
        ]);
    }
}
