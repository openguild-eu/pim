<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ImportAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Products\Enums\ProductStatus;
use Modules\Products\Filament\Exports\ProductExporter;
use Modules\Products\Filament\Imports\ProductImporter;
use Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use Modules\Products\Filament\Resources\Products\Pages\ListProducts;
use Modules\Products\Filament\Resources\Products\Pages\ManageCrossSellProducts;
use Modules\Products\Filament\Resources\Products\Pages\ManageProductActivities;
use Modules\Products\Filament\Resources\Products\Pages\ManageRelatedProducts;
use Modules\Products\Filament\Resources\Products\Pages\ManageUpSellProducts;
use Modules\Products\Filament\Resources\Products\RelationManagers\ChannelsRelationManager;
use Modules\Products\Models\Product;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 4;

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

                TextInput::make('sku')
                    ->required()
                    ->maxLength(255)
                    ->scopedUnique(ignoreRecord: true)
                    ->helperText('Unique stock-keeping unit code for this catalog.'),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->scopedUnique(ignoreRecord: true)
                    ->helperText('Used in URLs. Lowercase letters, numbers, and dashes only.'),

                Select::make('status')
                    ->required()
                    ->options(ProductStatus::class)
                    ->default(ProductStatus::Draft),

                TextInput::make('price')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('cents')
                    ->helperText('Stored as integer cents, e.g. 1999 = $19.99.'),

                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),

                SpatieMediaLibraryFileUpload::make('images')
                    ->collection('images')
                    ->conversion('thumb')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->columnSpanFull(),

                Select::make('categories')
                    ->relationship(name: 'categories', titleAttribute: 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),

                Select::make('attribute_set_id')
                    ->relationship(name: 'attributeSet', titleAttribute: 'name')
                    ->label('Attribute set')
                    ->live()
                    ->preload()
                    ->searchable(),

                Section::make('Attributes')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => filled($get('attribute_set_id')))
                    ->schema(function (Get $get): array {
                        $attributeSetId = $get('attribute_set_id');

                        if (blank($attributeSetId)) {
                            return [];
                        }

                        $attributeSet = AttributeSet::query()
                            ->with(['attributes' => fn (BelongsToMany $query) => $query->orderByPivot('position')])
                            ->find($attributeSetId);

                        if (! $attributeSet) {
                            return [];
                        }

                        return $attributeSet->attributes
                            ->map(function (Attribute $attribute): Field {
                                $name = "attribute_values.{$attribute->code}";
                                $isRequired = (bool) $attribute->pivot->is_required;

                                $field = match ($attribute->type->value) {
                                    'text' => TextInput::make($name),
                                    'textarea' => Textarea::make($name),
                                    'number' => TextInput::make($name)->numeric(),
                                    'boolean' => Toggle::make($name),
                                    'date' => DatePicker::make($name),
                                    'select' => Select::make($name)
                                        ->options(collect($attribute->options)->pluck('label', 'value')),
                                    'multiselect' => Select::make($name)
                                        ->multiple()
                                        ->options(collect($attribute->options)->pluck('label', 'value')),
                                };

                                return $field
                                    ->label($attribute->label)
                                    ->required($isRequired);
                            })
                            ->all();
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('images')
                    ->collection('images')
                    ->conversion('thumb')
                    ->limit(1),

                TextColumn::make('sku')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('attributeSet.name')
                    ->label('Attribute Set')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('price')
                    ->money('USD', divideBy: 100)
                    ->sortable(),

                TextColumn::make('categories')
                    ->label('Categories')
                    ->state(fn (Product $record): string => $record->categories->pluck('name')->join(', ')),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('status')
                    ->options(ProductStatus::class),
            ])
            ->headerActions([
                ImportAction::make()
                    ->importer(ProductImporter::class),
                ExportAction::make()
                    ->exporter(ProductExporter::class)
                    ->formats([ExportFormat::Csv, ExportFormat::Xlsx]),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    ExportBulkAction::make()
                        ->exporter(ProductExporter::class),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChannelsRelationManager::class,
        ];
    }

    /**
     * Without this, a trashed Product's Edit/related-record routes 404
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
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
            'related-products' => ManageRelatedProducts::route('/{record}/related-products'),
            'cross-sells' => ManageCrossSellProducts::route('/{record}/cross-sells'),
            'up-sells' => ManageUpSellProducts::route('/{record}/up-sells'),
            'activities' => ManageProductActivities::route('/{record}/activities'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku', 'slug'];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditProduct::class,
            ManageRelatedProducts::class,
            ManageCrossSellProducts::class,
            ManageUpSellProducts::class,
            ManageProductActivities::class,
        ]);
    }
}
