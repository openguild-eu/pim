<?php

declare(strict_types=1);

namespace Modules\Attributes\Filament\Resources\AttributeSets;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Modules\Attributes\Filament\Resources\AttributeSets\Pages\CreateAttributeSet;
use Modules\Attributes\Filament\Resources\AttributeSets\Pages\EditAttributeSet;
use Modules\Attributes\Filament\Resources\AttributeSets\Pages\ListAttributeSets;
use Modules\Attributes\Filament\Resources\AttributeSets\Pages\ManageAttributeSetActivities;
use Modules\Attributes\Filament\Resources\AttributeSets\RelationManagers\AttributesRelationManager;
use Modules\Attributes\Models\AttributeSet;

class AttributeSetResource extends Resource
{
    protected static ?string $model = AttributeSet::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

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
                    ->scopedUnique(ignoreRecord: true)
                    ->helperText('Used in URLs. Lowercase letters, numbers, and dashes only.'),
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

                TextColumn::make('attributes_count')
                    ->counts('attributes')
                    ->label('Attributes')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
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
            AttributesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributeSets::route('/'),
            'create' => CreateAttributeSet::route('/create'),
            'edit' => EditAttributeSet::route('/{record}/edit'),
            'activities' => ManageAttributeSetActivities::route('/{record}/activities'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditAttributeSet::class,
            ManageAttributeSetActivities::class,
        ]);
    }
}
