# Product Edit — Attributes as a Dynamic Left Sub-Navigation Entry

## Context

This is **not** a new module or model. Per the `planning-filament` skill's
"New vs Update Plans" format, this document is:

- **Update Resource: `Modules\Products\Filament\Resources\Products\ProductResource`**
  (`app-modules/products/src/Filament/Resources/Products/ProductResource.php`)
- **New Page: `Modules\Products\Filament\Resources\Products\Pages\ManageProductAttributes`**
- **Update Page: `Modules\Products\Filament\Resources\Products\Pages\EditProduct`**
- **Update Page: `Modules\Products\Filament\Resources\Products\Pages\CreateProduct`**

**Revision note**: this document **supersedes and reverts**
`blueprints/product-attribute-sidebar.md` (implemented at commit `9291c65`
on this branch), which wrapped `ProductResource::form()` in a
`Filament\Schemas\Components\Flex` layout (attribute-set picker + dynamic
attribute fields as a fixed-width left column, core fields as a growing
right column, both in one form). **That approach is superseded and must be
fully reverted** — the user decided, after seeing it, that attribute editing
should instead live in the same left-hand **resource sub-navigation** that
already has Edit, Images, Related Products, etc. (i.e. the pattern
`blueprints/product-images-tab.md` established for Images), not an in-form
split. Do not leave any `Flex`/`Group` usage behind in `ProductResource.php`.

**Decision (confirmed with the user, two-part)**:
1. Attribute editing becomes its own page in the existing sub-navigation
   list, following the exact `ManageProductImages` precedent (extends
   `Filament\Resources\Pages\EditRecord`, own instance `form()` override,
   registered in `getPages()` and `getRecordSubNavigation()`).
2. That sub-navigation entry must be **dynamic**: present only when the
   current product already has an `attribute_set_id` assigned; hidden
   otherwise. `Product` has exactly one `AttributeSet` (`belongsTo` via
   `attribute_set_id`) — confirmed in
   `app-modules/products/src/Models/Product.php`, no many-to-many, no data
   model change here. Because of this, **the `attribute_set_id` picker
   itself stays on the always-visible main `ProductResource::form()`** (used
   by both `CreateProduct` and `EditProduct`) — it must NOT move to the new
   page, or there would be no way to ever assign a first attribute set (the
   page containing the picker would stay permanently hidden until one was
   already assigned). Only the dynamically-built attribute **value** fields
   (today's `attribute_values.{code}` fields, built from the selected set's
   attributes) move to the new page.

**Verified Filament mechanics** (filament/filament@4.16.0, confirmed by
reading installed vendor source in this checkout, not assumed):
- `Filament\Resources\Pages\Concerns\InteractsWithRecord::getRecord(): Model`
  (non-nullable) is available on every page class this resource's
  sub-navigation uses (`EditRecord`, `ManageRelatedRecords` all use/extend
  this concern) — confirmed at
  `vendor/filament/filament/src/Resources/Pages/Concerns/InteractsWithRecord.php:44`
  and the `EditRecord`/`ManageRelatedRecords`/`ViewRecord` overrides of it.
  Both the page instance passed into `getRecordSubNavigation(Page $page)`
  and the new page itself can therefore read the bound `Product` directly —
  no extra query needed.
- `Filament\Pages\Concerns\HasSubNavigation::generateNavigationItems(array $components): array`
  (`vendor/filament/filament/src/Pages/Concerns/HasSubNavigation.php:170`)
  simply iterates whatever `array<class-string<Page>>` it is given and
  checks each class's own `shouldRegisterNavigation()`/`canAccess()`. There
  is no dedicated "conditional sub-nav item" API — conditionally omitting a
  class from the array passed to it, in plain PHP, is the documented-by-
  example mechanism and is sufficient and correct.
- `Filament\Schemas\Components\Text::make(string | Htmlable | Closure | null $content): static`
  confirmed at `vendor/filament/schemas/src/Components/Text.php:50,55` — used
  below for the "no attribute set assigned" placeholder message.

**Scope**: revert `ProductResource::form()` to a flat single-column form
(same seven core fields + the `attribute_set_id` picker, no `Flex`/`Section`-
as-sidebar/`Group` wrapping, no dynamic attribute-value fields inline);
remove the now-dead `Flex`/`Group`/`Get`/`Section`/`DatePicker`/`Toggle`/
`Field`/`BelongsToMany`/`Attribute`/`AttributeSet` imports from
`ProductResource.php` that only existed to support the reverted structure;
add one new page; make `getPages()`/`getRecordSubNavigation()` include that
page conditionally; strip the now-dead `PersistsAttributeValues` wiring out
of `EditProduct`/`CreateProduct` and move it, unchanged, onto the new page.
**Not covered**: `ManageProductImages` and the Images sub-navigation entry —
untouched by this document.

---

## Resources

### Update Resource: `ProductResource`

No scaffold command.

**Imports** — remove six that only existed for the Flex/dynamic-attribute
structure being reverted, add one for the new page:

Remove:
```php
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
```
(All ten of these were used exclusively by the dynamic attribute-value
closure and its `Flex`/`Section`/`Group` wrapping, which move to/are
replaced on the new page. Verify none of these ten is used anywhere else in
`ProductResource.php` — e.g. `table()`, `getRelations()` — before removing;
as of this plan's writing, they are not.)

Add:
```php
use Modules\Products\Filament\Resources\Products\Pages\ManageProductAttributes;
```

Keep unchanged: `Filament\Forms\Components\Select`, `TextInput`, `Textarea`,
`Filament\Schemas\Components\Utilities\Set` (still used by the name→slug
`afterStateUpdated` callback), `Filament\Schemas\Schema`, `Illuminate\Support\Str`,
and all other existing imports.

**Replace `form()` entirely** with the flat structure (reverting the Flex
split, and dropping the dynamic attribute-value `Section`/closure — those
fields now live only on `ManageProductAttributes`). Also drop `->live()`
from `attribute_set_id`: nothing in this schema reacts to it any more (the
`Section` it used to drive no longer lives in this form) — see "Flagged
assumptions" below.

```php
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

            Select::make('categories')
                ->relationship(name: 'categories', titleAttribute: 'name')
                ->multiple()
                ->searchable()
                ->preload(),

            Select::make('attribute_set_id')
                ->relationship(name: 'attributeSet', titleAttribute: 'name')
                ->label('Attribute set')
                ->preload()
                ->searchable(),
        ]);
}
```

**`getPages()`** — add one entry, placed right after `'images'` and before
`'related-products'`:

```php
public static function getPages(): array
{
    return [
        'index' => ListProducts::route('/'),
        'create' => CreateProduct::route('/create'),
        'edit' => EditProduct::route('/{record}/edit'),
        'images' => ManageProductImages::route('/{record}/images'),
        'attributes' => ManageProductAttributes::route('/{record}/attributes'),
        'related-products' => ManageRelatedProducts::route('/{record}/related-products'),
        'cross-sells' => ManageCrossSellProducts::route('/{record}/cross-sells'),
        'up-sells' => ManageUpSellProducts::route('/{record}/up-sells'),
        'activities' => ManageProductActivities::route('/{record}/activities'),
    ];
}
```

**`getRecordSubNavigation()`** — this is the one genuinely new mechanism:
build the array of page classes in plain PHP, conditionally including
`ManageProductAttributes::class` only when the bound record currently has an
`attribute_set_id`, then pass the resulting array to the existing
`generateNavigationItems()` call exactly as today:

```php
public static function getRecordSubNavigation(Page $page): array
{
    $items = [
        EditProduct::class,
        ManageProductImages::class,
    ];

    if (filled($page->getRecord()->attribute_set_id)) {
        $items[] = ManageProductAttributes::class;
    }

    $items[] = ManageRelatedProducts::class;
    $items[] = ManageCrossSellProducts::class;
    $items[] = ManageUpSellProducts::class;
    $items[] = ManageProductActivities::class;

    return $page->generateNavigationItems($items);
}
```

`$page->getRecord()` returns a non-nullable `Model` here (confirmed above) —
no null-safe operator needed on the record itself, only on reading its
`attribute_set_id` column via Laravel's `filled()` helper (already used
elsewhere in this file for the same null/blank check pattern).

No other change to `table()`, `getRelations()`,
`getGloballySearchableAttributes()`, or
`getRecordRouteBindingEloquentQuery()`.

### New Page: `ManageProductAttributes`

```
Location: Modules\Products\Filament\Resources\Products\Pages\ManageProductAttributes
extends: Filament\Resources\Pages\EditRecord
```

Exact file content:

```php
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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Attributes\Models\Attribute;
use Modules\Products\Filament\Resources\Products\Pages\Concerns\PersistsAttributeValues;
use Modules\Products\Filament\Resources\Products\ProductResource;

class ManageProductAttributes extends EditRecord
{
    use PersistsAttributeValues;

    protected static string $resource = ProductResource::class;

    protected static ?string $navigationLabel = 'Attributes';

    protected static ?string $breadcrumb = 'Attributes';

    protected static ?string $title = 'Attributes';

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
```

Notes:
- `form()` is an **instance** override (not `ProductResource::form()`),
  exactly like `ManageProductImages`, because it needs `$this->record` —
  confirmed safe: `EditRecord::mount()` resolves and assigns `$this->record`
  before building/filling the form, so `$this->record` is always populated
  by the time `form()` runs (same lifecycle `ManageProductImages` already
  relies on implicitly, just without needing the record itself there).
- The `match` closure and type-mapping are copied **verbatim** from the
  reverted `ProductResource::form()` dynamic `Section` — no logic changes,
  only its source (`$get('attribute_set_id')` → resolved `AttributeSet` via
  a live field) becomes `$this->record->attributeSet` (resolved from the
  persisted record directly, since this page has no `attribute_set_id`
  field of its own to read).
- `mutateFormDataBeforeFill()` / `mutateFormDataBeforeSave()` / `afterSave()`
  and the `PersistsAttributeValues` trait usage are moved **verbatim** from
  `EditProduct` (see below) — same hydrate/stash/persist calls, same
  guard behavior (`persistAttributeValues()`'s existing
  `if (! $record->attribute_set_id) return;` guard makes a save on this page
  with no attribute set assigned a safe no-op).
- No `getHeaderActions()` override — matches `ManageProductImages`; no
  Delete/Restore/ForceDelete duplication here either.
- Route model binding and tenant scoping (`CatalogScope` via
  `ProductResource::getEloquentQuery()`, and the soft-deleted-record
  override) apply automatically, identically to every other sub-navigation
  page on this resource, because this page shares
  `$resource = ProductResource::class` — no separate tenant check needed
  (same reasoning `blueprints/product-images-tab.md` already documents).

### Update: `EditProduct`

Remove the `PersistsAttributeValues` trait and its three hook overrides —
`attribute_values` is no longer part of this page's form (that Select stays,
but the dynamic value fields it used to gate are gone), so hydrating/
stripping/persisting them here is now dead code. `getHeaderActions()` is
unchanged.

Exact file content (full replacement):

```php
<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Products\Filament\Resources\Products\ProductResource;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RestoreAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
```

### Update: `CreateProduct`

Same reasoning — `attribute_values` is never part of the Create form (it
never was; only `attribute_set_id` is, and that has no accompanying value
fields to strip/persist until the record exists and a dedicated
`ManageProductAttributes` visit happens). Remove the trait and its two hook
overrides.

Exact file content (full replacement):

```php
<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Products\Filament\Resources\Products\ProductResource;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;
}
```

---

## Authorization

```
Resource: ProductResource (ManageProductAttributes page)
  Authorization: No change from today's boundary. A user who can already
  reach /default-catalog/products/{id}/edit (per blueprints/pim.md's
  CatalogScope + User::canAccessTenant() convention, no Policy class) can
  equally reach /default-catalog/products/{id}/attributes and read/write
  that product's attribute values — identical authorization boundary to
  today's inline fields, just relocated. No new Policy, no new gate. This
  must be covered by a required test (see Tests) confirming the existing
  ModelNotFoundException-on-cross-tenant-access behavior still holds on the
  new page, matching the equivalent test ManageProductImages already has.
```

---

## Tests

All changes are to
`app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`.
Current line numbers below are as of this plan's writing (573 lines) —
re-verify before editing, since earlier removals shift later line numbers.

**Add import**:
```php
use Modules\Products\Filament\Resources\Products\Pages\ManageProductAttributes;
```

**Remove import** `use Filament\Schemas\Components\Section;` — only used by
the test being removed below; confirm no other remaining test references it
before deleting.

**Remove import** `use Filament\Forms\Components\Select;` — only used by the
test being removed below (`configures the attribute_set_id select as
live...`); confirm no other remaining test references it before deleting.

**Remove this test** (obsolete — `attribute_set_id` no longer carries
`->live()`, and there is no in-form reactive section left to drive):
```php
it('configures the attribute_set_id select as live so the attributes section reacts to changes', function () {
    actingAsCatalogUser();

    Livewire::test(CreateProduct::class)
        ->assertSchemaComponentExists(
            'attribute_set_id',
            checkComponentUsing: fn (Select $component): bool => $component->isLive(),
        );
});
```

**Remove this test** (obsolete — the Flex sidebar it verifies no longer
exists):
```php
it('keeps the Attributes sidebar fixed-width while the product details column grows', function () {
    actingAsCatalogUser();

    $test = Livewire::test(CreateProduct::class);

    $section = collect($test->instance()->getSchema('form')->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Section && $component->getHeading() === 'Attributes');

    expect($section)->not->toBeNull()
        ->and($section->canGrow())->toBeFalse();
});
```

**Replace this test** (currently targets `CreateProduct` directly with live
`attribute_set_id` switching — no longer possible, since `CreateProduct`'s
form has no attribute-value fields at all now; the behavior moves to
`ManageProductAttributes`, keyed by the record's already-persisted set, so
it needs two separate Products instead of one Livewire instance switching
sets):

```php
it('required attribute fields reflect that attribute set\'s own pivot is_required value', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material']);
    $setRequiring = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setNotRequiring = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setRequiring->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);
    $setNotRequiring->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    Livewire::test(CreateProduct::class)
        ->fillForm(['attribute_set_id' => $setRequiring->id])
        ->assertFormFieldExists('attribute_values.material', fn (Field $field): bool => $field->isRequired())
        ->fillForm(['attribute_set_id' => $setNotRequiring->id])
        ->assertFormFieldExists('attribute_values.material', fn (Field $field): bool => ! $field->isRequired());
});
```

with:

```php
it('required attribute fields on the Attributes page reflect that attribute set\'s own pivot is_required value', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material']);
    $setRequiring = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setNotRequiring = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setRequiring->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);
    $setNotRequiring->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    $productRequiring = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $setRequiring->id]);
    $productNotRequiring = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $setNotRequiring->id]);

    Livewire::test(ManageProductAttributes::class, ['record' => $productRequiring->getRouteKey()])
        ->assertFormFieldExists('attribute_values.material', fn (Field $field): bool => $field->isRequired());

    Livewire::test(ManageProductAttributes::class, ['record' => $productNotRequiring->getRouteKey()])
        ->assertFormFieldExists('attribute_values.material', fn (Field $field): bool => ! $field->isRequired());
});
```

**Replace this test** (currently a single `CreateProduct` call with
`attribute_set_id` + `attribute_values` together, then live-switches sets
within one `EditProduct` instance — neither form carries `attribute_values`
any more, so the sequence must now span `CreateProduct` → `EditProduct` →
`ManageProductAttributes` across separate page visits, matching the actual
new navigation flow):

```php
it('switching attribute sets away and back preserves the original set\'s saved values and keeps both sets\' data', function () {
    $catalog = actingAsCatalogUser();
    $colorAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);
    $materialAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material']);
    $setA = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setB = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setA->attributes()->attach($colorAttribute->id, ['position' => 0, 'is_required' => false]);
    $setB->attributes()->attach($materialAttribute->id, ['position' => 0, 'is_required' => false]);

    // Create under set A, filling A's attribute.
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'sku' => 'SKU-1',
            'name' => 'Boots',
            'slug' => 'boots',
            'status' => 'draft',
            'attribute_set_id' => $setA->id,
            'attribute_values' => ['color' => 'red'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('sku', 'SKU-1')->first();

    // Switch to set B and fill B's attribute.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'attribute_set_id' => $setB->id,
            'attribute_values' => ['material' => 'cotton'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // Both sets' AttributeValue rows must now exist, independently.
    $colorValue = AttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $colorAttribute->id)->first();
    $materialValue = AttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $materialAttribute->id)->first();

    expect($colorValue?->value_text)->toBe('red')
        ->and($materialValue?->value_text)->toBe('cotton');

    // Switching back to A re-hydrates A's original, untouched value.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['attribute_set_id' => $setA->id])
        ->assertSchemaStateSet(function (array $state): void {
            expect($state['attribute_values']['color'])->toBe('red');
        });
});
```

with:

```php
it('switching attribute sets away and back preserves the original set\'s saved values and keeps both sets\' data', function () {
    $catalog = actingAsCatalogUser();
    $colorAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);
    $materialAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material']);
    $setA = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setB = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setA->attributes()->attach($colorAttribute->id, ['position' => 0, 'is_required' => false]);
    $setB->attributes()->attach($materialAttribute->id, ['position' => 0, 'is_required' => false]);

    // Create under set A. attribute_set_id lives on the main Create form;
    // attribute_values no longer does.
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'sku' => 'SKU-1',
            'name' => 'Boots',
            'slug' => 'boots',
            'status' => 'draft',
            'attribute_set_id' => $setA->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('sku', 'SKU-1')->first();

    // Fill set A's attribute on the dedicated Attributes page.
    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey()])
        ->fillForm(['attribute_values' => ['color' => 'red']])
        ->call('save')
        ->assertHasNoFormErrors();

    // Switch to set B on the main Edit page (no attribute_values field there).
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['attribute_set_id' => $setB->id])
        ->call('save')
        ->assertHasNoFormErrors();

    // Fill set B's attribute on the (now set-B-scoped) Attributes page.
    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey()])
        ->fillForm(['attribute_values' => ['material' => 'cotton']])
        ->call('save')
        ->assertHasNoFormErrors();

    // Both sets' AttributeValue rows must now exist, independently.
    $colorValue = AttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $colorAttribute->id)->first();
    $materialValue = AttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $materialAttribute->id)->first();

    expect($colorValue?->value_text)->toBe('red')
        ->and($materialValue?->value_text)->toBe('cotton');

    // Switch back to A on the Edit page...
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['attribute_set_id' => $setA->id])
        ->call('save')
        ->assertHasNoFormErrors();

    // ...and the Attributes page (now set-A-scoped again) re-hydrates A's
    // original, untouched value.
    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey()])
        ->assertSchemaStateSet(function (array $state): void {
            expect($state['attribute_values']['color'])->toBe('red');
        });
});
```

**Replace this test** (currently submits a bogus `attribute_values` code
*and* a `name` change on `EditProduct` in one call — `name` and
`attribute_values` no longer coexist on the same form/page, so the
dual-purpose assertion must split: the "skip unknown code, still save known
ones" guard is purely `ManageProductAttributes`'s/`persistAttributeValues()`'s
concern now):

```php
it('silently skips an attribute_values code that does not belong to the current attribute set and still saves the product', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'name' => 'Renamed Product',
            'attribute_values' => ['nonexistent_code' => 'tampered'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Renamed Product']);
    $this->assertDatabaseMissing('attribute_values', ['product_id' => $product->id, 'value_text' => 'tampered']);
});
```

with:

```php
it('silently skips an attribute_values code that does not belong to the current attribute set and still saves known values', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    // "nonexistent_code" belongs to no Attribute on this (or any)
    // AttributeSet — persistAttributeValues()'s `if (! $attribute)
    // continue;` guard must skip it rather than erroring, while a real
    // code on the current set still saves correctly in the same submit.
    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'attribute_values' => ['color' => 'red', 'nonexistent_code' => 'tampered'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('attribute_values', ['product_id' => $product->id, 'value_text' => 'red']);
    $this->assertDatabaseMissing('attribute_values', ['product_id' => $product->id, 'value_text' => 'tampered']);
});
```

**Add these new tests** — the dynamic sub-navigation visibility is the one
genuinely new, must-test behavior this document introduces:

```php
it('hides the Attributes sub-navigation entry when no attribute set is assigned', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);

    $test = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
    $test->assertOk();

    $subNavigationLabels = collect(
        ProductResource::getRecordSubNavigation($test->instance())
    )->map(fn ($item) => $item->getLabel())->all();

    expect($subNavigationLabels)->not->toContain('Attributes');
});

it('shows the Attributes sub-navigation entry once an attribute set is assigned', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    $test = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
    $test->assertOk();

    $subNavigationLabels = collect(
        ProductResource::getRecordSubNavigation($test->instance())
    )->map(fn ($item) => $item->getLabel())->all();

    expect($subNavigationLabels)->toContain('Attributes');
});
```

**Add this new test** — required-test for the Authorization boundary on the
new page, mirroring the equivalent existing `ManageProductImages` test
exactly (same mechanism, new page):

```php
it('does not write attribute values to a product in a different catalog when the attributes page form is submitted directly', function () {
    $otherCatalog = Catalog::factory()->create();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $otherCatalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $otherCatalog->id, 'code' => 'color']);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);
    $product = Product::factory()->create(['catalog_id' => $otherCatalog->id, 'attribute_set_id' => $attributeSet->id]);

    actingAsCatalogUser();

    expect(fn () => Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey()])
        ->fillForm(['attribute_values' => ['color' => 'intrusion']])
        ->call('save'))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseMissing('attribute_values', ['product_id' => $product->id, 'value_text' => 'intrusion']);
});
```

**Add this new test** — minimal smoke coverage for the "no attribute set
assigned yet" placeholder path (the route exists even though the sub-nav
link is hidden; per `testing.md`'s "What Not to Test" this does not need
content-string assertions, just confirmation the page renders without
erroring):

```php
it('loads the Attributes page without error when no attribute set is assigned', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);

    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey()])
        ->assertOk();
});
```

**Leave unchanged**: every other test in the file — dataset-driven field
validation, sku/slug uniqueness, slug-derivation, category persistence,
table/filter/sort/search/soft-delete/restore tests, and **all** existing
Images-related tests (including the `'shows an Images sub-navigation
tab...'` test, which only asserts `toContain('Images', 'Related Products')`
and is unaffected by Attributes' dynamic presence/absence either way).

**Test prerequisites**: same stack already in use — Pest, Filament's
Livewire testing helpers, no new package required.

Run after implementing:
```
vendor/bin/pest app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php
```
then the full module suite to catch cross-file regressions:
```
vendor/bin/pest app-modules/products/tests
```
then `vendor/bin/pint --dirty --format agent`.

---

## Summary of flagged assumptions

1. **Sub-navigation position** — placed "Attributes" third, right after
   "Images" and before "Related Products" (when present). Not specified
   beyond "where edit, images, related products are"; trivially reordered
   in `getRecordSubNavigation()`/`getPages()`.
2. **`attribute_set_id` loses `->live()`** — nothing in `ProductResource::form()`
   reacts to it any more now that the dynamic section it drove has moved to
   its own page. If a future requirement needs the main form to react to
   attribute-set changes for some other reason, `->live()` is trivially
   re-added.
3. **Direct-URL access with no attribute set assigned shows a placeholder
   message, not a redirect.** Chosen for simplicity and because no
   authorization boundary is being enforced here (the page is merely empty
   of useful content, not forbidden) — consistent with "no new Policy/gate."
   If the user would rather redirect to Edit with a notification in that
   case, that's a follow-up UX request.
4. **No data model change** — confirmed and reiterated: `Product` keeps its
   single `attribute_set_id` `belongsTo`. The "dynamic" menu item reflects
   presence/absence of that one relationship, not a list of multiple
   attribute sets.
