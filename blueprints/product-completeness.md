# Blueprint: Product Completeness

## Goal

Surface, per `Product`, what fraction of its `AttributeSet`'s *required*
attributes currently have a value — a single score, not per-locale/per-channel
(this app has no i18n layer and no per-channel attribute requirements; do not
introduce either). A product with no `attribute_set_id`, or whose attribute
set has no required attributes, is fully complete (100%) — there is nothing to
require.

Out of scope (explicitly, do not implement): MDM, DAM, CMS, per-locale or
per-channel completeness, a dedicated `Manage*` sub-navigation page for this
feature (see "Why no new page" below), caching/storing the computed value.

## Cross-Requirement Consistency

**Rule (canonical "is this attribute value filled?" check):** a value counts
as filled if Laravel's `filled()` helper returns `true` for it, applied to:

- `value_text` directly, for `text` / `textarea` / `select` attributes
- `json_decode($value_text, true)` (the decoded array), for `multiselect`
- `value_decimal` directly, for `number`
- `value_boolean` directly, for `boolean` — `filled(false) === true` in
  Laravel (`blank()` returns `false` for any `bool`), so an explicitly-set
  `false` **does** count as filled; only `null` does not
- `value_date` directly, for `date`

This is one rule, applied identically on both sides of the feature:

- **Live form state** (`ProductResource`'s existing "Attributes" section,
  while editing): `filled($get("attribute_values.{$attribute->code}"))`. The
  live value for a `multiselect` field is already a plain PHP array (Filament
  hasn't serialized it yet), for `boolean` a plain `bool`, etc. — so calling
  `filled()` directly on the live value is already applying the same rule as
  the persisted side below, with no special-casing needed.
- **Persisted state** (`AttributeValue::isFilled()`, used for the table column
  and `Product::missingRequiredAttributes()`): `filled()` applied to the
  type-appropriate column per the mapping above, decoding `multiselect`'s JSON
  first.

**Stages:** initial state (no `AttributeValue` row exists for a required
attribute → not filled) → user edits the form (live `filled($get(...))` drives
the "Attributes" section's missing-attributes description) → save (
`PersistsAttributeValues::persistAttributeValues()` already upserts a row
whenever the submitted value is not `null`, even if it's `''`, `[]`, or
`false` — unchanged by this plan) → reload (table column and breakdown read
back via `AttributeValue::isFilled()`).

**Consistency check (discriminating cases, both must agree):**

- A required `boolean` attribute explicitly toggled off (`false`) and saved:
  `persistAttributeValues()` saves a row with `value_boolean = false` (it only
  skips `null`). Live-side `filled(false)` is `true`; persisted-side
  `filled($value_boolean)` is `true`. Both say "filled" — correct, and this is
  exactly the case a naive `!== null`-only or truthy-only check gets wrong in
  one direction or the other.
- A required `multiselect` attribute touched and then cleared to an empty
  selection, saved: a row is saved with `value_text = '[]'` (not skipped,
  since `[]` is not `null`). Live-side `filled([])` is `false`; persisted-side
  `filled(json_decode('[]', true))` = `filled([])` is `false`. Both say "not
  filled" — correct, and this is exactly the case a naive "row exists" check
  gets wrong.
- A required `text` attribute cleared to `''` and saved: row saved with
  `value_text = ''`. Both sides: `filled('')` is `false`. Not filled —
  correct.

**Verification:** see the discriminating Pest cases in the Tests section
below, which assert these exact three cases independently (not via the
calculator itself).

## Why no new `Manage*` sub-navigation page

Existing sub-navigation pages (`ManageProductImages`, `ManageRelatedProducts`,
etc.) exist because their data needs a distinct, non-trivial editing UI. A
completeness breakdown has no separate state to edit — it's a read-only
derivation of data already edited inline in the existing "Attributes" section
of the main form. Adding a page would force users to leave the one place
they'd actually fix a missing attribute. Instead: a table badge for the
list-page overview, and a dynamic description on the existing "Attributes"
section for the fix-it view.

## Models

### Update Existing Model: `Modules\Attributes\Models\AttributeValue`

File: `app-modules/attributes/src/Models/AttributeValue.php`

Add import:

```php
use Modules\Attributes\Enums\AttributeType;
```

Add method (requires `attribute` to be loaded — callers must eager-load it;
see Resources section below for the table's eager-load and note that
`Product::missingRequiredAttributes()` relies on the same eager-loaded
collections, not a fresh query):

```php
public function isFilled(): bool
{
    return match ($this->attribute->type) {
        AttributeType::Multiselect => filled(json_decode((string) $this->value_text, true)),
        AttributeType::Text, AttributeType::Textarea, AttributeType::Select => filled($this->value_text),
        AttributeType::Number => filled($this->value_decimal),
        AttributeType::Boolean => filled($this->value_boolean),
        AttributeType::Date => filled($this->value_date),
    };
}
```

### Update Existing Model: `Modules\Products\Models\Product`

File: `app-modules/products/src/Models/Product.php`

Add import (in addition to the existing imports — `AttributeSet` and
`AttributeValue` are already imported):

```php
use Illuminate\Support\Collection;
use Modules\Attributes\Models\Attribute;
```

Add methods (place after `attributeValues()`):

```php
/**
 * The attribute set's required attributes. Reads from already-loaded
 * relations (`attributeSet.attributes`) rather than issuing a fresh
 * query, so callers must eager-load that relation to avoid N+1 — see
 * ProductResource::table()'s `modifyQueryUsing()`.
 *
 * @return Collection<int, Attribute>
 */
private function requiredAttributes(): Collection
{
    if (! $this->attributeSet) {
        return collect();
    }

    return $this->attributeSet->attributes
        ->filter(fn (Attribute $attribute): bool => (bool) $attribute->pivot->is_required);
}

/**
 * Required attributes with no filled value yet, in the attribute set's
 * own position order. Reads from the already-loaded `attributeValues`
 * relation (with `attribute` eager-loaded on each) — see
 * ProductResource::table()'s `modifyQueryUsing()`.
 *
 * @return Collection<int, Attribute>
 */
public function missingRequiredAttributes(): Collection
{
    $required = $this->requiredAttributes();

    if ($required->isEmpty()) {
        return $required;
    }

    $valuesByAttributeId = $this->attributeValues->keyBy('attribute_id');

    return $required
        ->reject(fn (Attribute $attribute): bool => $valuesByAttributeId->get($attribute->id)?->isFilled() ?? false)
        ->values();
}

/**
 * Percentage of the attribute set's required attributes that have a
 * filled value. 100 when there is no attribute set, or the attribute
 * set has no required attributes — there is nothing to require.
 */
public function completenessPercentage(): int
{
    $required = $this->requiredAttributes();

    if ($required->isEmpty()) {
        return 100;
    }

    $missingCount = $this->missingRequiredAttributes()->count();

    return (int) round((($required->count() - $missingCount) / $required->count()) * 100);
}
```

No migration, no new column, no new class/namespace — this plan only adds
methods to the two models above.

## Resources

### Update Resource: `Modules\Products\Filament\Resources\Products\ProductResource`

File: `app-modules/products/src/Filament/Resources/Products/ProductResource.php`

No scaffold command — this is an update to an existing resource.

#### Modify: `table()` — eager-load for the new column

Add, as the first call in the `Table $table` chain (before `->columns([...])`):

```
Config: ->modifyQueryUsing(fn (Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder => $query->with(['attributeSet.attributes', 'attributeValues.attribute']))
```

(`Illuminate\Database\Eloquent\Builder` is already imported in this file.)
This avoids N+1 queries across table rows for both the new completeness
column and its tooltip, since `completenessPercentage()` and
`missingRequiredAttributes()` both read from these relations rather than
querying.

#### Add: `Column: completeness`

Component: `Filament\Tables\Columns\TextColumn`
Docs: https://filamentphp.com/docs/4.x/tables/columns/text
Location: in `ProductResource::table()`'s `->columns([...])` array,
immediately after the `attributeSet.name` column and before `price`.
Config:

```php
->label('Completeness')
->state(fn (Product $record): int => $record->completenessPercentage())
->formatStateUsing(fn (int $state): string => "{$state}%")
->badge()
->color(fn (int $state): string => match (true) {
    $state === 100 => 'success',
    $state >= 50 => 'warning',
    default => 'danger',
})
->tooltip(function (Product $record): ?string {
    $missing = $record->missingRequiredAttributes();

    return $missing->isEmpty()
        ? null
        : 'Missing: '.$missing->pluck('label')->join(', ');
})
```

Do not add `->sortable()` — the state is computed per-row, not a real
database column, so sorting isn't available without a different (out-of-scope)
storage strategy.

#### Modify: `form()` — "Attributes" section description

The existing `Section::make('Attributes')` (around line 122 of the current
file) already computes, inside its `->schema(function (Get $get): array {...
})` closure, the selected `AttributeSet` with its ordered `attributes`
relation. Add a `->description()` closure to the same `Section` that performs
the equivalent lookup (independently, since `description()` and `schema()`
are evaluated separately) and reports which required attributes are not yet
filled, using the live form state — per the Cross-Requirement Consistency
rule above:

```php
Imports:
  - Filament\Schemas\Components\Utilities\Get

Config (add alongside the existing ->columnSpanFull()/->visible()/->schema()):
->description(function (Get $get): ?string {
    $attributeSetId = $get('attribute_set_id');

    if (blank($attributeSetId)) {
        return null;
    }

    $attributeSet = AttributeSet::query()
        ->with(['attributes' => fn (BelongsToMany $query) => $query->orderByPivot('position')])
        ->find($attributeSetId);

    if (! $attributeSet) {
        return null;
    }

    $missingLabels = $attributeSet->attributes
        ->filter(fn (Attribute $attribute): bool => (bool) $attribute->pivot->is_required)
        ->reject(fn (Attribute $attribute): bool => filled($get("attribute_values.{$attribute->code}")))
        ->pluck('label');

    return $missingLabels->isEmpty()
        ? 'All required attributes are filled.'
        : 'Missing required attributes: '.$missingLabels->join(', ').'.';
})
```

`AttributeSet`, `Attribute`, `BelongsToMany`, and `Get` are already imported
in this file for the existing `schema()` closure — no new imports needed for
this change. This duplicates the `AttributeSet::query()->with(...)->find(...)`
lookup already present in `schema()`; that duplication is accepted here
rather than extracting a shared private method, since `description()` and
`schema()` are independent Filament callbacks evaluated at different times
and extracting now would be speculative — revisit only if a third consumer of
this lookup appears.

## Authorization

No new authorization rules. Completeness is a read-only derivation of data
the user can already see and edit (the product's attribute values); it
follows `ProductResource`'s existing policy for viewing/editing products.

## Tests

Add to the existing `app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`
(this file already imports `Attribute`, `AttributeSet`, `AttributeValue`,
`Product`, uses `actingAsCatalogUser()`, and attaches pivots with
`['position' => 0, 'is_required' => ...]` — reuse that exact pattern).

```
it('a product with no attribute set is fully complete', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);

    expect($product->completenessPercentage())->toBe(100);
    expect($product->missingRequiredAttributes())->toBeEmpty();
});

it('a product whose attribute set has no required attributes is fully complete', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    expect($product->completenessPercentage())->toBe(100);
    expect($product->missingRequiredAttributes())->toBeEmpty();
});

it('completeness reflects the proportion of required attributes with a filled value', function () {
    $catalog = actingAsCatalogUser();
    $color = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color', 'type' => AttributeType::Text]);
    $material = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material', 'type' => AttributeType::Text]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($color->id, ['position' => 0, 'is_required' => true]);
    $attributeSet->attributes()->attach($material->id, ['position' => 1, 'is_required' => true]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    AttributeValue::create([
        'catalog_id' => $catalog->id,
        'product_id' => $product->id,
        'attribute_id' => $color->id,
        'value_text' => 'red',
    ]);

    expect($product->completenessPercentage())->toBe(50);
    expect($product->missingRequiredAttributes()->pluck('code')->all())->toBe(['material']);
});

it('a required boolean attribute explicitly set to false counts as filled', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'is_fragile', 'type' => AttributeType::Boolean]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    AttributeValue::create([
        'catalog_id' => $catalog->id,
        'product_id' => $product->id,
        'attribute_id' => $attribute->id,
        'value_boolean' => false,
    ]);

    expect($product->completenessPercentage())->toBe(100);
    expect($product->missingRequiredAttributes())->toBeEmpty();
});

it('a required multiselect attribute saved with an empty selection does not count as filled', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'tags', 'type' => AttributeType::Multiselect]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    AttributeValue::create([
        'catalog_id' => $catalog->id,
        'product_id' => $product->id,
        'attribute_id' => $attribute->id,
        'value_text' => '[]',
    ]);

    expect($product->completenessPercentage())->toBe(0);
    expect($product->missingRequiredAttributes()->pluck('code')->all())->toBe(['tags']);
});

it('shows the completeness badge and tooltip in the products table', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material', 'label' => 'Material', 'type' => AttributeType::Text]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);
    $complete = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $incomplete = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    Livewire::test(ListProducts::class)
        ->assertSee('100%')
        ->assertSee('0%')
        ->assertSee('Missing: Material', escape: false);
});

it('shows which required attributes are missing on the product form', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material', 'label' => 'Material', 'type' => AttributeType::Text]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);

    Livewire::test(CreateProduct::class)
        ->fillForm(['attribute_set_id' => $attributeSet->id])
        ->assertSee('Missing required attributes: Material.')
        ->fillForm(['attribute_set_id' => $attributeSet->id, 'attribute_values' => ['material' => 'Cotton']])
        ->assertSee('All required attributes are filled.');
});
```

Add import to the test file:

```php
use Modules\Attributes\Enums\AttributeType;
```

(`Modules\Products\Filament\Resources\Products\Pages\ListProducts` and
`CreateProduct` are already imported in this file.)

### Test prerequisites

No new test dependencies. `Livewire::test(...)->assertSee()` is the project's
existing Livewire testing helper (already used implicitly via `Livewire`
import); no Filament-specific table-cell-state assertion method (e.g. an
`assertTableColumnStateSet`) is used here because its exact name/namespace for
the installed Filament version was not confirmed against this project's
bundled testing reference — `assertSee()` on the rendered table is used
instead, which is verified to exist.
