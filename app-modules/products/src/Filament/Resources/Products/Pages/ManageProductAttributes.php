<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Livewire\Attributes\Locked;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Products\Filament\Resources\Products\Pages\Concerns\PersistsAttributeValues;
use Modules\Products\Filament\Resources\Products\ProductResource;

class ManageProductAttributes extends EditRecord
{
    use PersistsAttributeValues;

    protected static string $resource = ProductResource::class;

    #[Locked]
    public int|string $attributeSetKey;

    public function mount(int|string $record, int|string|null $attributeSet = null): void
    {
        $this->record = $this->resolveRecord($record);

        $this->authorizeAccess();

        // Scoped lookup: throws ModelNotFoundException (→ 404) unless this
        // AttributeSet is actually attached to this product. This is the
        // only guard needed — there is no separate tenant check, same
        // reasoning already documented for every other sub-navigation page
        // on this resource (blueprints/product-images-tab.md).
        $this->attributeSetKey = $this->record->attributeSets()->findOrFail($attributeSet)->getKey();

        $this->fillForm();

        $this->previousUrl = url()->previous();
    }

    protected function getAttributeSet(): AttributeSet
    {
        return $this->record->attributeSets()
            ->with(['attributes' => fn (BelongsToMany $query) => $query->orderByPivot('position')])
            ->findOrFail($this->attributeSetKey);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(
                $this->getAttributeSet()->attributes
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

    public function getTitle(): string|Htmlable
    {
        return $this->getAttributeSet()->name;
    }

    public function getBreadcrumb(): string
    {
        return $this->getAttributeSet()->name;
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
        $this->persistAttributeValues($this->record, $this->getAttributeSet());
    }
}
