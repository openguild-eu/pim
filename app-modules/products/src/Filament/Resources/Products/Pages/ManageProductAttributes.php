<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Attributes\Models\Attribute;
use Modules\Products\Filament\Resources\Products\Pages\Concerns\PersistsAttributeValues;
use Modules\Products\Filament\Resources\Products\ProductResource;

use function Filament\Support\original_request;

class ManageProductAttributes extends EditRecord
{
    use PersistsAttributeValues;

    protected static string $resource = ProductResource::class;

    public function form(Schema $schema): Schema
    {
        $this->record->loadMissing([
            'attributeSet.attributes' => fn (BelongsToMany $query) => $query->orderByPivot('position'),
        ]);

        $attributeSet = $this->record->attributeSet;

        if (! $attributeSet) {
            return $schema
                ->columns(1)
                ->components([
                    Text::make('No attribute set is assigned to this product yet. Assign one from the Edit page.'),
                ]);
        }

        return $schema
            ->columns(1)
            ->components(
                $attributeSet->attributes
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
                    ->all()
            );
    }

    public static function getNavigationLabel(): string|Htmlable
    {
        return 'Attributes';
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->attributeSet?->name ?? 'Attributes';
    }

    public function getBreadcrumb(): string
    {
        return $this->record->attributeSet?->name ?? 'Attributes';
    }

    public static function getNavigationItems(array $urlParameters = []): array
    {
        $record = $urlParameters['record'] ?? null;
        $label = $record?->attributeSet?->name ?? static::getNavigationLabel();

        return [
            NavigationItem::make($label)
                ->key(static::class)
                ->group(static::getNavigationGroup())
                ->parentItem(static::getNavigationParentItem())
                ->icon(static::getNavigationIcon())
                ->activeIcon(static::getActiveNavigationIcon())
                ->activeWhen(fn (): bool => original_request()->routeIs(static::getNavigationItemActiveRoutePattern()))
                ->sort(static::getNavigationSort())
                ->badge(static::getNavigationBadge(), color: static::getNavigationBadgeColor())
                ->url(static::getNavigationUrl($urlParameters)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('attributeValues.attribute');

        foreach ($this->record->attributeValues as $attributeValue) {
            $data['attribute_values'][$attributeValue->attribute->code] =
                $this->hydrateValue($attributeValue->attribute->type->value, $attributeValue);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->stashAndStripAttributeValues($data);
    }

    protected function afterSave(): void
    {
        $this->persistAttributeValues($this->record);
    }
}
