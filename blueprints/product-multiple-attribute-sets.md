# Products Can Have Multiple Attribute Sets (many-to-many + per-set sub-nav pages)

## Context

This is a **real schema change** — per the `planning-filament` skill's
"New vs Update Plans" format, this document is:

- **New migration**: `attribute_set_product` pivot table (mirrors
  `app-modules/products/database/migrations/2026_11_03_020002_create_category_product_table.php`
  exactly — same module, same shape).
- **Update Model**: `Modules\Products\Models\Product` — new
  `attributeSets(): BelongsToMany` relation.
- **Update Resource**: `ProductResource` — new multi-select form field,
  changed route signature, rewritten `getRecordSubNavigation()`.
- **Update Page**: `ManageProductAttributes` — now parameterized by a
  specific `AttributeSet`, not "whatever the record's single legacy set
  is."
- **Update Trait**: `PersistsAttributeValues` — `persistAttributeValues()`
  signature changes to take the specific `AttributeSet` explicitly.

**Decision (confirmed with the user, two-part)**:
1. A product must be assignable to **multiple** attribute sets at once —
   the current `attribute_set_id` (`belongsTo`, one set only) cannot
   represent this; it becomes a many-to-many relationship, following the
   exact precedent `Product::categories()`/`Product::channels()` already
   establish in this same model (`belongsToMany` + a dedicated pivot table,
   named `{other_model}_{this_model}` alphabetically — `category_product`,
   `channel_product`, so `attribute_set_product`).
2. The **old `attribute_set_id` column is kept**, not dropped — the user
   explicitly chose to retain it as a "primary attribute set" marker,
   alongside the new many-to-many list. **Important, explicit scope
   decision**: the legacy `attribute_set_id` field keeps existing exactly
   as-is (same column, same form field, just relabeled "Primary attribute
   set" for clarity) but **no longer drives any navigation or
   page-availability logic**. The sub-navigation and
   `ManageProductAttributes` pages are now driven **entirely** by the new
   `attributeSets` many-to-many list — this is the only way the "every
   attribute set gets its own page" requirement is satisfiable once there
   can be more than one. If the user later wants the "primary" set
   highlighted differently in the sidebar (e.g. sorted first, bolded), that
   is an explicit follow-up, not inferred here.

**Verified Filament/Laravel mechanics** (filament/filament@4.16.0,
confirmed by reading installed vendor source in this checkout):
- `Filament\Resources\Pages\EditRecord::mount(int | string $record): void`
  (`vendor/filament/filament/src/Resources/Pages/EditRecord.php:96-105`) is
  the exact default body being overridden below — confirmed line-by-line:
  `$this->record = $this->resolveRecord($record); $this->authorizeAccess();
  $this->fillForm(); $this->previousUrl = url()->previous();`. Adding a
  second route parameter to a resource page's `mount()` is the documented
  pattern for the `InteractsWithRecord` trait (Docs 03 Resources 12 Custom
  Pages "Using a resource record": "The `mount()` method should resolve the
  record from the URL and store it in `$this->record`.. To add the record
  to the route as a parameter, you must define `{record}` in
  `getPages()`.") — the same applies to any additional named route segment;
  Livewire resolves `mount()` parameters by matching route-segment names.
- Per Filament's own documented Livewire-model-restoration security
  guidance (Docs 09 Advanced 06 Security, "Protecting your own model
  properties"): **do not** store an Eloquent model you resolve yourself as
  a public Livewire property — "store a locked record ID instead, then
  query and authorize the model when you need it... You may also re-query
  and authorize a restored model before using it." This plan follows that
  exact pattern: a `#[Locked]` scalar `attributeSetKey` property, re-queried
  **scoped to the record's own `attributeSets()` relationship** (so a
  tampered/arbitrary ID can never resolve to a set the product isn't
  actually attached to — `findOrFail` throws `ModelNotFoundException`
  otherwise, the same mechanism this suite's existing cross-tenant tests
  already rely on for `{record}` itself).
- `Filament\Pages\Concerns\HasSubNavigation::generateNavigationItems()`
  (confirmed in earlier work on this branch) maps **one page class to one
  navigation item** — it has no mechanism for "N items from one class with
  different parameters." For a genuinely dynamic, per-record list (the
  product's current `attributeSets`), `ProductResource::getRecordSubNavigation()`
  must **manually construct one `Filament\Navigation\NavigationItem` per
  attached `AttributeSet`**, each with its own label (the set's `name`) and
  its own URL (`ManageProductAttributes::getUrl(['record' => ..., 'attributeSet' => ...])`),
  and splice them into the array alongside the `generateNavigationItems()`-built
  items for the other (still one-class-one-item) pages. This is a
  necessary generalization of the existing mechanism, not a departure from
  it — Filament's own docs example for "Adding sub-navigation" lists each
  page as a literal, named class precisely because its examples are all
  static; ours has a dynamic list where the *set of pages itself* varies
  per record.
- `Filament\Navigation\NavigationItem::isActiveWhen(?Closure $callback):
  static` (confirmed to exist in `vendor/filament/filament/src/Navigation/NavigationItem.php:141`)
  is used to give each manually-built item its own active-state check,
  since all of them share one route *name* (`products.attributes`) and
  only differ by the `{attributeSet}` route parameter — `routeIs()` alone
  (what the auto-generated items use) cannot distinguish between them.

**Scope**: one migration, one model relation, `ProductResource.php`
(`form()`, `getPages()`, `getRecordSubNavigation()`),
`ManageProductAttributes.php` (near-complete rewrite),
`PersistsAttributeValues.php` (signature change on one method), and a
substantial rewrite of the attribute-related tests in
`ProductResourceTest.php`. **Not covered**: Images, Related Products,
Cross-sells, Up-sells, Activities — untouched.

---

## Models

### New migration

Location: `app-modules/products/database/migrations/2026_11_11_120000_create_attribute_set_product_table.php`
(module and naming convention confirmed against the existing
`category_product`/`channel_product` migrations, both also under
`app-modules/products/database/migrations/`).

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_set_product', function (Blueprint $table): void {
            $table->foreignId('attribute_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unique(['attribute_set_id', 'product_id']);
        });

        // Backfill: every product that already has a legacy attribute_set_id
        // gets one corresponding row in the new pivot, so it doesn't
        // silently disappear from the new multi-select / sub-navigation
        // immediately after this migration runs. attribute_set_id itself
        // is read-only here and is never modified or dropped.
        DB::table('products')
            ->whereNotNull('attribute_set_id')
            ->orderBy('id')
            ->select('id', 'attribute_set_id')
            ->chunkById(500, function ($products): void {
                DB::table('attribute_set_product')->insert(
                    $products->map(fn ($product): array => [
                        'attribute_set_id' => $product->attribute_set_id,
                        'product_id' => $product->id,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_set_product');
    }
};
```

No change to the existing `products` table migration or the
`attribute_set_id` column/foreign key — both stay exactly as they are.

### Update Model: `Product`

File: `app-modules/products/src/Models/Product.php`. Add one relation,
immediately after the existing `attributeSet(): BelongsTo` method (line
66-69), matching the no-PHPDoc style already used by the adjacent
`categories(): BelongsToMany` method:

```php
public function attributeSets(): BelongsToMany
{
    return $this->belongsToMany(AttributeSet::class, 'attribute_set_product');
}
```

`BelongsToMany` and `AttributeSet` are already imported in this file (used
by `categories()` and `attributeSet()` respectively) — no new imports
needed. No change to `$fillable`, `attributeSet()`, `categories()`, or any
other method.

---

## Resources

### Update Resource: `ProductResource`

**Imports** — add two:
```php
use Filament\Navigation\NavigationItem;
use Modules\Attributes\Models\AttributeSet;
```
(`AttributeSet` was removed from this file's imports in the previous
"dynamic sub-navigation" document — it's needed again here for the
`fn (AttributeSet $attributeSet): NavigationItem` closure type-hint in
`getRecordSubNavigation()` below.)

**`form()`** — relabel the existing `attribute_set_id` field, and add one
new multi-select field immediately after it, following the exact
`categories` field's pattern already in this same form:

```php
Select::make('attribute_set_id')
    ->relationship(name: 'attributeSet', titleAttribute: 'name')
    ->label('Primary attribute set')
    ->preload()
    ->searchable(),

Select::make('attributeSets')
    ->relationship(name: 'attributeSets', titleAttribute: 'name')
    ->label('Attribute sets')
    ->multiple()
    ->searchable()
    ->preload(),
```

(The field's state key, `attributeSets`, matches the relation method name
exactly — the same convention `categories` already uses — so Filament's
built-in relationship-save hook handles create/update/sync automatically;
no custom `mutateFormDataBeforeSave`/`afterSave` logic is needed for this
field, exactly like `categories` needs none today.)

**`getPages()`** — the `'attributes'` route gains a second required
segment:

```php
'attributes' => ManageProductAttributes::route('/{record}/attributes/{attributeSet}'),
```

**`getRecordSubNavigation()`** — replace entirely. The static items (Edit,
Images, Related Products, Cross-sells, Up-sells, Activities) still go
through `generateNavigationItems()` unchanged; the attribute-set items are
now built manually, one per entry in `$record->attributeSets`, ordered
alphabetically by name (cosmetic, flagged below):

```php
public static function getRecordSubNavigation(Page $page): array
{
    $record = $page->getRecord();

    $attributeSetItems = $record->attributeSets
        ->sortBy('name')
        ->map(fn (AttributeSet $attributeSet): NavigationItem => NavigationItem::make($attributeSet->name)
            ->url(ManageProductAttributes::getUrl(['record' => $record, 'attributeSet' => $attributeSet]))
            ->isActiveWhen(fn (): bool => (string) original_request()->route('attributeSet') === (string) $attributeSet->getKey()))
        ->values()
        ->all();

    return [
        ...$page->generateNavigationItems([
            EditProduct::class,
            ManageProductImages::class,
        ]),
        ...$attributeSetItems,
        ...$page->generateNavigationItems([
            ManageRelatedProducts::class,
            ManageCrossSellProducts::class,
            ManageUpSellProducts::class,
            ManageProductActivities::class,
        ]),
    ];
}
```

Add `use function Filament\Support\original_request;` to this file's
imports (confirmed real, namespaced helper — see Context; it is **not** a
bare global function, contrary to an earlier draft of a prior document on
this branch, which an implementing agent corrected when the uncorrected
version failed at runtime).

No other change to `table()`, `getRelations()`,
`getGloballySearchableAttributes()`, or
`getRecordRouteBindingEloquentQuery()`.

### Rewrite Page: `ManageProductAttributes`

Full replacement content:

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

    public function mount(int|string $record, int|string $attributeSet): void
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
```

Notes on what changed from the previous version and why:
- **No more "no attribute set assigned" placeholder.** That state is no
  longer reachable: this page's route requires a concrete `{attributeSet}`
  segment, and `mount()` 404s immediately if it doesn't resolve to a set
  actually attached to the record. The `Filament\Schemas\Components\Text`
  placeholder and its import are removed entirely — this is dead code now,
  not a behavior regression (nothing could ever navigate here with no set,
  since the sub-navigation only ever links to sets that exist on the
  record, and a stale/forged URL now correctly 404s instead of rendering a
  message).
- **`$attributeSetKey` is a `#[Locked]` scalar, not a stored model.** Per
  the Filament security guidance quoted in Context — the model itself is
  re-resolved, scoped and re-validated, every time `getAttributeSet()` is
  called (on every request, including the AJAX `save` call), rather than
  trusting a Livewire-restored Eloquent property that bypassed the scoping
  query on a later request.
- **`getNavigationLabel()`/`getNavigationItems()` static overrides from the
  previous document are removed.** This page is no longer registered via
  `generateNavigationItems()` at all — `ProductResource::getRecordSubNavigation()`
  now builds every attribute-set `NavigationItem` manually (see above), so
  nothing on this page class is ever consulted for sidebar label purposes.
  `getTitle()`/`getBreadcrumb()` remain (page-level header/breadcrumb, not
  sidebar-related) and are simplified — no more `?? 'Attributes'` fallback,
  since `getAttributeSet()` always returns a resolved instance or throws.
- `mutateFormDataBeforeFill()` is unchanged from the previous document —
  still hydrates **every** stored `AttributeValue` row for the record
  regardless of which specific set this page instance is scoped to (same
  "old rows are never touched" decision this suite's existing tests already
  lock in); the page's own `form()` only ever *displays* fields for the
  current `getAttributeSet()`'s own attributes, so values for other sets
  are simply present in form state but not rendered — harmless, matches
  existing behavior exactly.

### Update Trait: `PersistsAttributeValues`

File:
`app-modules/products/src/Filament/Resources/Products/Pages/Concerns/PersistsAttributeValues.php`.
`persistAttributeValues()` now takes the specific `AttributeSet` explicitly
instead of inferring it from `$record->attributeSet` — it has exactly one
call site (`ManageProductAttributes::afterSave()` above; `EditProduct`/
`CreateProduct` stopped using this trait in the previous document on this
branch, confirmed — safe to change this signature freely).

Replace the whole method:

```php
protected function persistAttributeValues(Product $record, AttributeSet $attributeSet): void
{
    foreach ($this->attributeValuesData as $code => $value) {
        if ($value === null) {
            continue;
        }

        /** @var Attribute|null $attribute */
        $attribute = $attributeSet->attributes()
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
```

(The old `if (! $record->attribute_set_id) { return; }` guard is removed —
it's no longer meaningful; the caller always passes a concretely resolved,
attached `AttributeSet`, guaranteed by `ManageProductAttributes::mount()`'s
`findOrFail`.) Add `use Modules\Attributes\Models\AttributeSet;` to this
file's imports. `stashAndStripAttributeValues()`, `valueColumns()`, and
`hydrateValue()` are unchanged.

---

## Authorization

No change to the authorization *mechanism* — same `CatalogScope`/
`User::canAccessTenant()` convention, no Policy class, same as every other
sub-navigation page on this resource. **New enforcement point**: visiting
`ManageProductAttributes` for an `AttributeSet` that is not attached to the
given product now throws `ModelNotFoundException` via the scoped
`findOrFail()` in `mount()` — this is a genuinely new guard this document
introduces (the single-set version had no equivalent check, since there was
only ever one possible set to resolve). Required test below.

---

## Tests

All changes are to
`app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`.

**Remove these three tests entirely** (each describes a state that is no
longer reachable, or is superseded by a better test below):

```php
it('falls back to a generic title and breadcrumb on the Attributes page when no attribute set is assigned', function () { /* ... */ });

it('loads the Attributes page without error when no attribute set is assigned', function () { /* ... */ });

it('hides the Attributes sub-navigation entry when no attribute set is assigned', function () { /* ... */ });

it('labels the Attributes sub-navigation entry with the assigned attribute set\'s own name', function () { /* ... */ });
```

**Replace** the required-attribute-pivot test (same two-Products structure,
now attaching via the pivot and passing the `attributeSet` route
parameter):

```php
it('required attribute fields on the Attributes page reflect that attribute set\'s own pivot is_required value', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material']);
    $setRequiring = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setNotRequiring = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setRequiring->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);
    $setNotRequiring->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    $productRequiring = Product::factory()->create(['catalog_id' => $catalog->id]);
    $productRequiring->attributeSets()->attach($setRequiring->id);

    $productNotRequiring = Product::factory()->create(['catalog_id' => $catalog->id]);
    $productNotRequiring->attributeSets()->attach($setNotRequiring->id);

    Livewire::test(ManageProductAttributes::class, ['record' => $productRequiring->getRouteKey(), 'attributeSet' => $setRequiring->getRouteKey()])
        ->assertFormFieldExists('attribute_values.material', fn (Field $field): bool => $field->isRequired());

    Livewire::test(ManageProductAttributes::class, ['record' => $productNotRequiring->getRouteKey(), 'attributeSet' => $setNotRequiring->getRouteKey()])
        ->assertFormFieldExists('attribute_values.material', fn (Field $field): bool => ! $field->isRequired());
});
```

**Replace** the switch-sets-roundtrip test — its premise ("switch a single
set over time") no longer matches the architecture (a product can simply
have both sets attached simultaneously, each with its own independent
page). New test, same underlying persistence rule, more directly exercising
what "multiple" actually means:

```php
it('persists independent attribute values for each attribute set attached to the same product', function () {
    $catalog = actingAsCatalogUser();
    $colorAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);
    $materialAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material']);
    $setA = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Color Set']);
    $setB = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Material Set']);
    $setA->attributes()->attach($colorAttribute->id, ['position' => 0, 'is_required' => false]);
    $setB->attributes()->attach($materialAttribute->id, ['position' => 0, 'is_required' => false]);

    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $product->attributeSets()->attach([$setA->id, $setB->id]);

    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey(), 'attributeSet' => $setA->getRouteKey()])
        ->fillForm(['attribute_values' => ['color' => 'red']])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey(), 'attributeSet' => $setB->getRouteKey()])
        ->fillForm(['attribute_values' => ['material' => 'cotton']])
        ->call('save')
        ->assertHasNoFormErrors();

    $colorValue = AttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $colorAttribute->id)->first();
    $materialValue = AttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $materialAttribute->id)->first();

    expect($colorValue?->value_text)->toBe('red')
        ->and($materialValue?->value_text)->toBe('cotton');

    // Re-visiting set A's page afterwards still shows only its own value,
    // untouched by set B's save.
    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey(), 'attributeSet' => $setA->getRouteKey()])
        ->assertSchemaStateSet(function (array $state): void {
            expect($state['attribute_values']['color'])->toBe('red');
        });
});
```

**Replace** the "silently skips unknown code" test (pivot attach +
route parameter):

```php
it('silently skips an attribute_values code that does not belong to the current attribute set and still saves known values', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $product->attributeSets()->attach($attributeSet->id);

    Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey(), 'attributeSet' => $attributeSet->getRouteKey()])
        ->fillForm([
            'attribute_values' => ['color' => 'red', 'nonexistent_code' => 'tampered'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('attribute_values', ['product_id' => $product->id, 'value_text' => 'red']);
    $this->assertDatabaseMissing('attribute_values', ['product_id' => $product->id, 'value_text' => 'tampered']);
});
```

**Replace** the cross-tenant test (pivot attach + route parameter; same
mechanism, `ModelNotFoundException` now comes from `{record}` resolution as
before — this test doesn't exercise the new not-attached guard, see below
for that):

```php
it('does not write attribute values to a product in a different catalog when the attributes page form is submitted directly', function () {
    $otherCatalog = Catalog::factory()->create();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $otherCatalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $otherCatalog->id, 'code' => 'color']);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);
    $product = Product::factory()->create(['catalog_id' => $otherCatalog->id]);
    $product->attributeSets()->attach($attributeSet->id);

    actingAsCatalogUser();

    expect(fn () => Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey(), 'attributeSet' => $attributeSet->getRouteKey()])
        ->fillForm(['attribute_values' => ['color' => 'intrusion']])
        ->call('save'))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseMissing('attribute_values', ['product_id' => $product->id, 'value_text' => 'intrusion']);
});
```

**Replace** the title test (pivot attach + route parameter):

```php
it('titles the Attributes page with the assigned attribute set\'s own name', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Clothing']);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $product->attributeSets()->attach($attributeSet->id);

    $test = Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey(), 'attributeSet' => $attributeSet->getRouteKey()]);

    expect($test->instance()->getTitle())->toBe('Clothing')
        ->and($test->instance()->getBreadcrumb())->toBe('Clothing');
});
```

**Add these new tests**:

The one genuinely new, must-test behavior — multiple sets each get their
own sub-navigation entry, and the count scales with however many are
attached (a plausible wrong implementation that only ever shows one item
would pass a "contains" check but fail this count check):

```php
it('adds one sub-navigation entry per attribute set attached to the product', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    $baselineCount = count(ProductResource::getRecordSubNavigation(
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->instance()
    ));

    $setA = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Color Set']);
    $setB = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Material Set']);
    $product->attributeSets()->attach([$setA->id, $setB->id]);

    $test = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
    $test->assertOk();

    $subNavigationLabels = collect(
        ProductResource::getRecordSubNavigation($test->instance())
    )->map(fn ($item) => $item->getLabel())->all();

    expect($subNavigationLabels)->toContain('Color Set', 'Material Set')
        ->and($subNavigationLabels)->toHaveCount($baselineCount + 2);
});
```

The new not-attached guard introduced by this document:

```php
it('throws a not found exception when visiting the Attributes page for a set not attached to this product', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    // $attributeSet is deliberately never attached to $product.

    expect(fn () => Livewire::test(ManageProductAttributes::class, [
        'record' => $product->getRouteKey(),
        'attributeSet' => $attributeSet->getRouteKey(),
    ]))->toThrow(ModelNotFoundException::class);
});
```

The direct, literal "select multiple" requirement, mirroring the existing
`categories` persist/replace tests exactly (same mechanism, new relation):

```php
it('persists multiple attribute set assignment on create', function () {
    $catalog = actingAsCatalogUser();
    $setA = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setB = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'sku' => 'SKU-1',
            'name' => 'Boots',
            'slug' => 'boots',
            'status' => 'draft',
            'attributeSets' => [$setA->id, $setB->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('sku', 'SKU-1')->first();

    expect($product->attributeSets()->pluck('attribute_sets.id')->sort()->values()->all())
        ->toBe([$setA->id, $setB->id]);
});

it('replaces attribute set assignment on edit rather than merging it', function () {
    $catalog = actingAsCatalogUser();
    $setA = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setB = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $product->attributeSets()->attach($setA->id);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['attributeSets' => [$setB->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->attributeSets()->pluck('attribute_sets.id')->all())->toBe([$setB->id]);
});
```

**Leave unchanged**: every other test — dataset-driven field validation,
sku/slug uniqueness, slug-derivation, category persistence, table/filter/
sort/search/soft-delete/restore tests, and all Images-related tests.

**Test prerequisites**: same stack already in use — Pest, Filament's
Livewire testing helpers, no new package required. Running the migration
during tests happens automatically via the test suite's normal
`RefreshDatabase`-equivalent setup — no manual step needed.

Run after implementing:
```
php artisan migrate --no-interaction
vendor/bin/pest app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php
```
then the full module suite:
```
vendor/bin/pest app-modules/products/tests
```
then `vendor/bin/pint --dirty --format agent`.

---

## Summary of flagged assumptions

1. **Attribute-set sub-navigation items are ordered alphabetically by
   name**, with no icon set (the auto-generated items get Filament's
   default icons via their own `getNavigationIcon()`; the manually-built
   ones currently get none). Both are cosmetic and trivially changed —
   ordering by `sortBy('name')` → e.g. `sortBy('id')`/pivot `created_at`,
   or adding `->icon(...)` to the manual `NavigationItem::make()` call.
2. **The legacy `attribute_set_id`/"Primary attribute set" field no longer
   drives the sub-navigation at all** — only the new `attributeSets` list
   does. If the user wants the primary set's page to be distinguished
   somehow (sorted first, a badge, etc.) among the sub-navigation entries,
   that's a follow-up, not inferred here, per the explicit scope decision
   in Context.
3. **Backfill runs once, at migration time, from `attribute_set_id` into
   the new pivot** — it does not keep the two in sync going forward (e.g.
   changing "Primary attribute set" on the Edit form does not auto-attach
   it to the `attributeSets` list, and vice versa). They are two
   independent pieces of data from this point on, exactly as decided.
