<?php

declare(strict_types=1);

namespace Modules\Attributes\Filament\Resources\Attributes;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Attributes\Enums\AttributeType;
use Modules\Attributes\Filament\Resources\Attributes\Pages\CreateAttribute;
use Modules\Attributes\Filament\Resources\Attributes\Pages\EditAttribute;
use Modules\Attributes\Filament\Resources\Attributes\Pages\ListAttributes;
use Modules\Attributes\Filament\Resources\Attributes\Pages\ManageAttributeActivities;
use Modules\Attributes\Models\Attribute;

class AttributeResource extends Resource
{
    protected static ?string $model = Attribute::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->scopedUnique(ignoreRecord: true)
                    ->helperText('Machine name used internally (e.g. by the Products form). Lowercase letters, numbers, and dashes only.'),

                TextInput::make('label')
                    ->required()
                    ->maxLength(255),

                Select::make('type')
                    ->required()
                    ->options(AttributeType::class)
                    ->native(false)
                    ->live()
                    ->disabled(fn (?Attribute $record): bool => $record && $record->values()->exists())
                    ->helperText(fn (?Attribute $record): ?string => ($record && $record->values()->exists())
                        ? 'Cannot change the type once a product has a value stored for this attribute — its existing values would land in the wrong value_* column. Create a new Attribute instead.'
                        : null),

                Repeater::make('options')
                    ->schema([
                        TextInput::make('value')->required()->maxLength(255),
                        TextInput::make('label')->required()->maxLength(255),
                    ])
                    ->columns(2)
                    ->addActionLabel('Add option')
                    ->visible(fn (Get $get): bool => static::typeRequiresOptions($get('type')))
                    ->required(fn (Get $get): bool => static::typeRequiresOptions($get('type')))
                    ->dehydrateStateUsing(fn (Get $get, ?array $state): ?array => static::typeRequiresOptions($get('type')) ? $state : null),

                Toggle::make('is_filterable')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge(),

                IconColumn::make('is_filterable')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(AttributeType::class),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributes::route('/'),
            'create' => CreateAttribute::route('/create'),
            'edit' => EditAttribute::route('/{record}/edit'),
            'activities' => ManageAttributeActivities::route('/{record}/activities'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'label'];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditAttribute::class,
            ManageAttributeActivities::class,
        ]);
    }

    /**
     * `Select::options(AttributeType::class)` casts the field's form state
     * to an `AttributeType` instance (not the raw string), so a plain
     * `in_array($get('type'), ['select', 'multiselect'])` against string
     * literals never matches. Normalize before comparing.
     */
    private static function typeRequiresOptions(AttributeType|string|null $type): bool
    {
        $value = $type instanceof AttributeType ? $type->value : $type;

        return in_array($value, ['select', 'multiselect'], true);
    }
}
