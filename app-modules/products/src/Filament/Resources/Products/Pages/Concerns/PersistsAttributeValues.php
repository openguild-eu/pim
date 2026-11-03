<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages\Concerns;

use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeValue;
use Modules\Products\Models\Product;

trait PersistsAttributeValues
{
    /**
     * Submitted `attribute_values.{code} => value` pairs, keyed by
     * Attribute `code`, stashed between the form's
     * `mutateFormDataBeforeCreate()`/`mutateFormDataBeforeSave()` hook and
     * the `afterCreate()`/`afterSave()` hook that has access to
     * `$this->record->id`.
     *
     * @var array<string, mixed>
     */
    protected array $attributeValuesData = [];

    /**
     * `attribute_values` is not a column on `products`, so it must never
     * reach `Product::create()`/`$record->update()`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stashAndStripAttributeValues(array $data): array
    {
        $this->attributeValuesData = $data['attribute_values'] ?? [];
        unset($data['attribute_values']);

        return $data;
    }

    /**
     * Upserts one `AttributeValue` row per submitted code. Never deletes
     * `AttributeValue` rows belonging to a different AttributeSet.
     */
    protected function persistAttributeValues(Product $record): void
    {
        if (! $record->attribute_set_id) {
            return;
        }

        foreach ($this->attributeValuesData as $code => $value) {
            if ($value === null) {
                continue;
            }

            /** @var Attribute|null $attribute */
            $attribute = $record->attributeSet->attributes()
                ->where('code', $code)
                ->first();

            if (! $attribute) {
                continue;
            }

            AttributeValue::updateOrCreate(
                [
                    'product_id' => $record->id,
                    'attribute_id' => $attribute->id,
                ],
                [
                    'catalog_id' => $record->catalog_id,
                    ...$this->valueColumns($attribute->type->value, $value),
                ],
            );
        }
    }

    /**
     * Canonical type -> value_* column mapping (write direction). Keep in
     * sync with `hydrateValue()` below.
     *
     * @return array<string, mixed>
     */
    protected function valueColumns(string $type, mixed $value): array
    {
        return match ($type) {
            'text', 'textarea', 'select' => ['value_text' => (string) $value],
            'multiselect' => ['value_text' => json_encode($value)],
            'number' => ['value_decimal' => $value],
            'boolean' => ['value_boolean' => (bool) $value],
            'date' => ['value_date' => $value],
            default => [],
        };
    }

    /**
     * Canonical type -> value_* column mapping (read direction). Used by
     * `EditProduct::mutateFormDataBeforeFill()`. Must stay the exact
     * inverse of `valueColumns()`.
     */
    protected function hydrateValue(string $type, AttributeValue $attributeValue): mixed
    {
        return match ($type) {
            'text', 'textarea', 'select' => $attributeValue->value_text,
            'multiselect' => json_decode((string) $attributeValue->value_text, true) ?? [],
            'number' => $attributeValue->value_decimal,
            'boolean' => $attributeValue->value_boolean,
            'date' => $attributeValue->value_date,
            default => null,
        };
    }
}
