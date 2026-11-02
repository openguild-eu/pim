# Products Module

## Context

Core product catalog entries for the PIM. Builds on the foundations in
`blueprints/pim.md` §1 (two-layer multi-tenancy) and §2 (Activity History
convention), and follows the same scaffolding/format conventions established
in `blueprints/category.md` (the most recently finished sibling module).

**Scope of this document**: the `Product` model, its own CRUD resource
(`ProductResource`), and its integration with the Attributes module
(`attribute_set_id`, the dynamic per-attribute-set form section, and
`AttributeValue` persistence). This is one of six sibling module blueprints
being written in parallel for the same locked architecture (Attributes,
**Products** [this document], Media, Channels, Relations, Import/Export).
Each of the other five will separately document its own "Update Existing
Model: Product" addition on top of what's defined here — this document does
not anticipate those.

**Cross-module dependency (read before implementing)**: this blueprint
assumes the sibling `blueprints/attributes.md` defines, in
`Modules\Attributes\Models`:

- `AttributeSet` (`id, catalog_id, name, slug, timestamps`) —
  `belongsToMany(Attribute::class)->withPivot(['position', 'is_required'])`,
  relationship method `attributes()`, plus a `hasMany(Product::class)`.
- `Attribute` (`id, catalog_id, code, label, type, options, is_filterable,
  timestamps`) where `type` is one of
  `text|textarea|number|boolean|date|select|multiselect`.
- `AttributeValue` (`id, catalog_id, product_id, attribute_id, value_text,
  value_integer, value_decimal, value_boolean, value_date, timestamps`,
  unique `[product_id, attribute_id]`).

**This namespace (`Modules\Attributes\Models\*`) is an assumption, not
verified against the sibling document** — it was written in parallel and
this agent could not read it. Confirm the namespace when reconciling the two
blueprints; every `use Modules\Attributes\Models\...;` statement below may
need a namespace find-and-replace if `attributes.md` lands differently.

**Open question flagged for reconciliation with `attributes.md`**: the
`AttributeValue` schema has five `value_*` columns but `Attribute::type` has
seven cases. This document's canonical type→column mapping (used
identically for both reading and writing — see `PersistsAttributeValues`
trait below) is:

| `Attribute::type` | Column         | Note                                      |
| ------------------ | -------------- | ------------------------------------------ |
| `text`              | `value_text`     |                                             |
| `textarea`          | `value_text`     |                                             |
| `select`            | `value_text`     | stores the selected option's string value  |
| `multiselect`       | `value_text`     | **JSON-encoded** array of selected values — flagged assumption, see below |
| `number`            | `value_decimal`  | handles both integer and fractional input  |
| `boolean`           | `value_boolean`  |                                             |
| `date`              | `value_date`     |                                             |

`value_integer` is **never written by this mapping** — there is no `type`
case that maps to it given the fixed five-column/seven-type shape as
specified. Flag this to the `attributes.md` author; if they intended
`value_integer` for `number`, update both this table and the
`PersistsAttributeValues::valueColumns()`/`hydrateValue()` methods below
together (same canonical rule, both directions).

`multiselect` storing a JSON-encoded array into `value_text` is also an
assumption — `AttributeValue` has no array-capable column in the locked
schema. If `attributes.md` adds one, update the mapping table and both trait
methods together.

## Commands

1. Scaffold the module:
   ```
   php artisan make:module products --no-interaction
   ```
   Then edit the generated `app-modules/products/composer.json` to mirror
   `app-modules/users/composer.json` exactly in shape, with two extra direct
   dependencies since `Product` references models from both sibling
   modules:
   - package name `modules/products`
   - PSR-4: `Modules\Products\` => `src/`, `Modules\Products\Tests\` =>
     `tests/`, `Modules\Products\Database\Factories\` =>
     `database/factories/`, `Modules\Products\Database\Seeders\` =>
     `database/seeders/`
   - `require`: add `"modules/categories": "*"` and `"modules/attributes":
     "*"` alongside `filament/filament` and `laravel/framework` (Product's
     `categories()` and `attributeSet()`/`attributeValues()` relationships
     type-hint classes from those modules)
   - `extra.laravel.providers`: `Modules\Products\Providers\ProductsServiceProvider`

2. Add `"modules/products": "*"` to the root `composer.json` `require` block
   (next to `"modules/users": "*"`), then:
   ```
   composer update modules/products --no-interaction
   ```

3. **Do not** use `make:filament-resource` / `make:model` / `make:filament-page`
   for this module — their default path resolution targets `app/`, not this
   module's `src/` root. Author every file below directly at its listed path
   and namespace (same rule as `blueprints/category.md`).

4. Create `app-modules/products/src/Providers/ProductsServiceProvider.php`,
   matching `Modules\Users\Providers\UsersServiceProvider` exactly (empty
   `register()`, `boot()` calls
   `$this->loadMigrationsFrom(__DIR__.'/../../database/migrations')`).

5. In `app/Providers/Filament/AdminPanelProvider.php`, add a third resource
   discovery call (after the `categories` one added by
   `blueprints/category.md`):
   ```php
   ->discoverResources(in: base_path('app-modules/products/src/Filament/Resources'), for: 'Modules\Products\Filament\Resources')
   ```
   **Do not** add another `NavigationGroup::make()->label('Catalog')` call —
   that group already exists (added when `blueprints/category.md` was
   implemented); `ProductResource` just reuses it via its own `Navigation`
   config below.

6. In `app/Filament/Resources/ActivityLogs/ActivityLogResource.php`, extend
   `scopeEloquentQueryToTenant()`'s `where()` closure with a new branch
   (`Product` has a direct `catalog_id`, per the "Direct catalog_id" shape
   in `blueprints/pim.md` §2) — same pattern as the `Category` branch added
   by `blueprints/category.md`:
   ```php
   ->orWhereHasMorph('subject', [Product::class], function (Builder $productQuery) use ($tenant): void {
       $productQuery->where('catalog_id', $tenant->getKey());
   })
   ```
   (add `use Modules\Products\Models\Product;` to the imports)

7. After creating the migrations (below): `php artisan migrate`.
   **Migration ordering note**: `create_products_table` has foreign keys
   into both `attribute_sets` (from `attributes.md`) and `categories` (from
   `category.md`). Its migration filename timestamp must sort after both of
   those modules' `create_*_table` migrations, or `php artisan migrate` will
   fail with an undefined-table error. Likewise `create_category_product_table`
   (step below) must sort after `create_products_table` and
   `create_categories_table`. Verify actual timestamps once all three
   modules are implemented together — this can't be confirmed from this
   blueprint alone.

8. `vendor/bin/pint --dirty --format agent`

## Models

### New Model

```
Model: Product
  Namespace: Modules\Products\Models\Product
  Table: products
  Attributes:
    - id: bigint, primary
    - catalog_id: bigint, foreign(catalogs.id), required
    - attribute_set_id: bigint, foreign(attribute_sets.id), nullable
    - sku: string, required
    - name: string, required
    - slug: string, required
    - status: string, required, default:draft
    - description: text, nullable
    - price: integer, nullable
    - created_at: timestamp
    - updated_at: timestamp
    - deleted_at: timestamp, nullable
  Indexes:
    - unique: [catalog_id, sku]
    - unique: [catalog_id, slug]
    - index: [attribute_set_id]
  Relationships:
    - belongsTo: Catalog via catalog_id (method `catalog()` — required name,
      it's the Filament tenant ownership relationship)
    - belongsTo: Modules\Attributes\Models\AttributeSet via
      attribute_set_id (method `attributeSet()`)
    - belongsToMany: Modules\Categories\Models\Category via
      category_product (method `categories()`, plain pivot — no pivot
      columns, no `->withTimestamps()`)
    - hasMany: Modules\Attributes\Models\AttributeValue via product_id
      (method `attributeValues()`)
  Traits:
    - App\Models\Concerns\BelongsToCatalog
    - Illuminate\Database\Eloquent\SoftDeletes
    - Spatie\Activitylog\Models\Concerns\LogsActivity
  Factory: Modules\Products\Database\Factories\ProductFactory
```

Migration FK modifiers: `catalog_id` — `constrained('catalogs')` (no cascade,
same as `Category.catalog_id` — Catalog deletion isn't a defined feature).
`attribute_set_id` — `nullable()->constrained('attribute_sets')->nullOnDelete()`
(assumption, not specified by the locked design: if an AttributeSet is
deleted, affected Products simply lose their set assignment rather than
blocking the delete or cascading; flag this for confirmation against
`attributes.md`, which owns `AttributeSet` deletion behavior).

Price is stored as an integer number of cents, matching the convention
stated in the locked design — never store/accept a float.

### Enum: ProductStatus

```
Enum: ProductStatus
  Namespace: Modules\Products\Enums\ProductStatus
  Implements: HasLabel, HasColor
  Backed by: string
  Cases:
    - Draft = 'draft': label "Draft", color "gray"
    - Published = 'published': label "Published", color "success"
```

Cast on `Product`: `protected $casts = ['status' => ProductStatus::class];`
— this is what makes the table's `status` column's `->badge()` pick up the
correct label/color automatically (see Table section).

### Model body (exact — copy as-is)

```php
<?php

declare(strict_types=1);

namespace Modules\Products\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Attributes\Models\AttributeSet;
use Modules\Attributes\Models\AttributeValue;
use Modules\Categories\Models\Category;
use Modules\Products\Database\Factories\ProductFactory;
use Modules\Products\Enums\ProductStatus;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Product extends Model
{
    use BelongsToCatalog;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'catalog_id',
        'attribute_set_id',
        'sku',
        'name',
        'slug',
        'status',
        'description',
        'price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function attributeSet(): BelongsTo
    {
        return $this->belongsTo(AttributeSet::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('product')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->name;
    }
}
```

### Update Existing Model: Category

`blueprints/category.md` deliberately deferred product-category assignment
to this document. Add the inverse relationship to
`Modules\Categories\Models\Category`:

```
Migration: create_category_product_table
  Table: category_product
  Columns:
    - category_id: bigint, foreign(categories.id), required
    - product_id: bigint, foreign(products.id), required
  Indexes:
    - unique: [category_id, product_id]
  FK modifiers: both columns `->cascadeOnDelete()` — assumption (not
    specified by the locked design): this is a plain join table with no
    independent lifecycle of its own, so cleaning up pivot rows when either
    side is force-deleted is pure housekeeping, not a change to Category's
    or Product's own deletion rules (neither of which this document
    touches). Flag for confirmation if either sibling module wants
    different behavior.
  Location: this blueprint places the migration in
    app-modules/products/database/migrations/ (Products is the module
    introducing the relationship) — see the migration-ordering note in
    Commands step 7.

Add to Category (Modules\Categories\Models\Category):
  Relationship: belongsToMany: Modules\Products\Models\Product via
    category_product (method `products()`)
```

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Products\Models\Product;

public function products(): BelongsToMany
{
    return $this->belongsToMany(Product::class, 'category_product');
}
```

## Resources

```
Resource: ProductResource
  Command: (none — authored directly; see Commands step 3)
  Location: Modules\Products\Filament\Resources\Products\ProductResource
  Docs: https://filamentphp.com/docs/4.x/resources/overview
  RecordTitleAttribute: name
  GloballySearchableAttributes: [name, sku, slug]

  Navigation:
    Group: Catalog
    Icon: Filament\Support\Icons\Heroicon::OutlinedCube
    Sort: 2
```

(`Heroicon::OutlinedCube` confirmed to exist in the installed
`filament/support` package — `vendor/filament/support/src/Icons/Heroicon.php`
defines `case OutlinedCube = 'o-cube';`.)

### Form (used by Create and Edit pages — `Columns: 1`)

```
Imports:
  - Filament\Schemas\Components\Utilities\Get
  - Filament\Schemas\Components\Utilities\Set
  - Illuminate\Support\Str

Field: name
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255
  Config:
    ->required()
    ->maxLength(255)
    ->live(onBlur: true)
    ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
        if ($operation === 'create') {
            $set('slug', Str::slug((string) $state));
        }
    })
  Note: same auto-slug-on-create-only reasoning as Category's `name` field
  in blueprints/category.md — editing `name` on an existing Product never
  overwrites an already-saved slug, and each keystroke re-derives `slug`
  from the current `name`, not the original.

Field: sku
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255
  Config:
    ->required()
    ->maxLength(255)
    ->scopedUnique(ignoreRecord: true)
    ->helperText('Unique stock-keeping unit code for this catalog.')
  Note: `->scopedUnique()` (not Laravel's plain `unique` string rule) per
  blueprints/pim.md's multi-tenancy rule — plain `unique` does not respect
  CatalogScope and would leak collisions across catalogs.

Field: slug
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255, alpha_dash
  Config:
    ->required()
    ->maxLength(255)
    ->alphaDash()
    ->scopedUnique(ignoreRecord: true)
    ->helperText('Used in URLs. Lowercase letters, numbers, and dashes only.')

Field: status
  Component: Filament\Forms\Components\Select
  Docs: https://filamentphp.com/docs/4.x/forms/select
  Validation: required
  Config:
    ->required()
    ->options(Modules\Products\Enums\ProductStatus::class)
    ->default(Modules\Products\Enums\ProductStatus::Draft)

Field: price
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: nullable, integer, min:0
  Config:
    ->numeric()
    ->minValue(0)
    ->prefix('cents')
    ->helperText('Stored as integer cents, e.g. 1999 = $19.99.')
  Note: the locked design stores price as integer cents with no currency
  conversion UI specified. This blueprint does not add a dollars-displayed/
  cents-stored conversion layer — that would be a consequential UX decision
  outside this document's scope. Flagged as a known rough edge, not solved
  here.

Field: description
  Component: Filament\Forms\Components\Textarea
  Docs: https://filamentphp.com/docs/4.x/forms/textarea
  Validation: nullable
  Config: ->rows(4)->columnSpanFull()

Field: categories
  Component: Filament\Forms\Components\Select
  Docs: https://filamentphp.com/docs/4.x/forms/select
  Validation: nullable
  Config:
    ->relationship(name: 'categories', titleAttribute: 'name')
    ->multiple()
    ->searchable()
    ->preload()
  Note: not explicitly named as a form field in the locked design's field
  list, but required for the "category assignment persists through
  save/reload" test the locked design calls for, and for the table's
  `categories` column to ever show anything. Added as the straightforward
  default per relationships.md's belongsToMany guidance (Select-multiple,
  not CheckboxList/RelationManager — no pivot data, no need to interact
  with anything beyond the association). `->relationship()` scopes its
  options query through `CatalogScope` automatically (Category uses
  `BelongsToCatalog`), so no extra `modifyQueryUsing` is needed for tenant
  isolation here.

Field: attribute_set_id
  Component: Filament\Forms\Components\Select
  Docs: https://filamentphp.com/docs/4.x/forms/select
  Validation: nullable, scoped exists
  Config:
    ->relationship(name: 'attributeSet', titleAttribute: 'name')
    ->label('Attribute set')
    ->live()
    ->preload()
    ->searchable()
  Note: `->relationship()`'s underlying query is scoped by CatalogScope
  (AttributeSet uses BelongsToCatalog per attributes.md), which is
  Filament's actual tenant-isolation boundary for the submitted value — see
  the same reasoning Category's `parent_id` field documents in
  blueprints/category.md. `->live()` is mandatory here: it's what makes the
  "Attributes" Section below re-render when this value changes.
```

#### The "Attributes" Section (dynamic per-AttributeSet fields)

```
Section: Attributes
  Component: Filament\Schemas\Components\Section
  Docs: https://filamentphp.com/docs/4.x/schemas/sections
  ColumnSpan: full
  Config:
    ->visible(fn (Get $get): bool => filled($get('attribute_set_id')))
    ->schema(function (Get $get): array { ... })  — full closure below
```

**Imports for this closure**:
```php
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
```

**Exact closure (copy as-is into the Section's `->schema()`)**:

```php
function (Get $get): array {
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

            $field = match ($attribute->type) {
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
}
```

Verified against `filament/filament@4.x` docs (`packages/forms/docs/01-overview.md`,
"Dynamic fields based on a select option"): passing a closure injecting
`Get` to a layout component's `->schema()` is the documented mechanism, and
`->live()` on the field being read (`attribute_set_id`) is what triggers the
re-render. `BelongsToMany::orderByPivot()` confirmed present in the
installed `laravel/framework` source
(`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Relations/BelongsToMany.php`).

**`attribute_values` is not a real column/relationship on `Product`.** It
only exists as nested dot-notation form state
(`attribute_values.{code} => value`) produced by the fields above. It must
never reach `Product::create()`/`$record->update()`, and must be written to
`AttributeValue` rows separately, after the `Product` record has an id. See
the cross-requirement specification below.

### Cross-requirement: attribute-set switching, hydration, and persistence

**Rule** (single canonical type→column mapping, used identically for both
reading and writing — see the Context section's mapping table): given an
`Attribute` and its `type`, there is exactly one `value_*` column to
read/write, decided by `PersistsAttributeValues::valueColumns()` /
`::hydrateValue()` below. Both directions must stay in sync; do not
duplicate this mapping anywhere else.

**Stages**:

1. **Hydrate (Edit page load)** — `EditProduct::mutateFormDataBeforeFill()`
   loads the record's existing `attributeValues` (eager-loaded with
   `attribute`), and for each one writes
   `$data['attribute_values'][$attributeValue->attribute->code]` using the
   canonical column mapping (reading whichever `value_*` column is correct
   for that attribute's `type`).
2. **User/context change (switching AttributeSet)** — changing
   `attribute_set_id` (which is `->live()`) re-evaluates the "Attributes"
   Section's `->schema()` closure. The closure derives its field list
   *solely* from the currently-selected `attribute_set_id` at the moment it
   runs — it has no memory of a previously-selected set. Fields for the old
   set simply stop being part of the schema; fields for the new set appear,
   empty (no `fill()` call initializes them from anything — there is
   nothing to initialize them from, since the previous AttributeSet's saved
   values are for different `Attribute` codes entirely).
3. **Validate/save (enforcement point)** — `mutateFormDataBeforeSave()`
   (Edit) / `mutateFormDataBeforeCreate()` (Create) receive `$data` as the
   form's current *dehydrated* state. Because dehydration re-derives the
   schema from the current `attribute_set_id` (step 2's mechanism), `$data['attribute_values']`
   at this point contains keys **only** for whichever AttributeSet is
   selected at submit time — never stale keys from an earlier
   selection made and reverted within the same session. Both hooks stash
   this array onto the Livewire component (`$this->attributeValuesData`)
   and `unset($data['attribute_values'])` before returning, so it never
   reaches `Product::create()`/`$record->update()`.
4. **Persist (after the id exists)** — `afterCreate()` / `afterSave()` call
   `persistAttributeValues($this->record)`, which loops the stashed
   `code => value` pairs, resolves each `Attribute` via
   `$record->attributeSet->attributes()->where('code', $code)->first()`
   (scoped to the record's *current* `attribute_set_id` through the
   relationship itself — never queries `Attribute` unscoped), and
   `AttributeValue::updateOrCreate(['product_id' => $record->id,
   'attribute_id' => $attribute->id], [...])` with the one correct
   `value_*` column for that attribute's type.
5. **Decided behavior — old AttributeValue rows are never touched**: if a
   user switches `attribute_set_id` from set A to set B and saves, step 4
   only writes rows for set B's attributes (the only ones present in
   `$data['attribute_values']` at that save). Set A's previously-saved
   `AttributeValue` rows are **not deleted, not nulled, and not touched in
   any way** — nothing in this flow ever issues a delete against
   `AttributeValue` scoped to the old `attribute_id`s. They become
   invisible (not displayed) only because the Edit form's Section no
   longer renders fields for them while set B is selected. If the user
   later switches back to set A (same session, or after a fresh page
   load+reload), step 1's hydration reads those same still-present rows
   back in, unedited.

**Consistency check (discriminating test)** — see Tests section
"AttributeSet switching" for the full Pest test. Switch A → B → save →
reload Edit page → switch back to A: A's original values must still be
present, byte-for-byte, not blank and not reset — because nothing in this
flow ever deletes them.

### `PersistsAttributeValues` trait (shared by Create and Edit pages)

```
Location: Modules\Products\Filament\Resources\Products\Pages\Concerns\PersistsAttributeValues
```

```php
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
     * `$this->record->id`. Chosen over reading `$this->form->getRawState()`
     * in the `after*()` hook so the exact dehydrated array (already
     * scoped to the AttributeSet selected at submit time) is captured once
     * and reused, rather than re-derived.
     *
     * @var array<string, mixed>
     */
    protected array $attributeValuesData = [];

    /**
     * Call from `mutateFormDataBeforeCreate()` / `mutateFormDataBeforeSave()`.
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
     * Call from `afterCreate()` / `afterSave()`, once `$this->record` has
     * an id and a (possibly just-changed) `attribute_set_id`. Upserts one
     * `AttributeValue` row per submitted code. Never deletes
     * `AttributeValue` rows belonging to a different AttributeSet — see
     * the "Decided behavior" note in the Models section.
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
                    ...$this->valueColumns($attribute->type, $value),
                ],
            );
        }
    }

    /**
     * Canonical type -> value_* column mapping (write direction). Keep in
     * sync with `hydrateValue()` below — see the Context section's mapping
     * table and its flagged open questions (`value_integer` unused,
     * `multiselect` JSON-encoded into `value_text`).
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
```

### CreateProduct page

```
Location: Modules\Products\Filament\Resources\Products\Pages\CreateProduct
extends: Filament\Resources\Pages\CreateRecord
```

```php
<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Products\Filament\Resources\Products\Pages\Concerns\PersistsAttributeValues;
use Modules\Products\Filament\Resources\Products\ProductResource;

class CreateProduct extends CreateRecord
{
    use PersistsAttributeValues;

    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->stashAndStripAttributeValues($data);
    }

    protected function afterCreate(): void
    {
        $this->persistAttributeValues($this->record);
    }
}
```

Verified against `filament/filament@4.x` docs
(`docs/03-resources/03-creating-records.md`): `mutateFormDataBeforeCreate()`
and the `afterCreate()` lifecycle hook (with `$this->record` already set by
that point) are both documented, current APIs.

### EditProduct page

```
Location: Modules\Products\Filament\Resources\Products\Pages\EditProduct
extends: Filament\Resources\Pages\EditRecord
```

```php
<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Products\Filament\Resources\Products\Pages\Concerns\PersistsAttributeValues;
use Modules\Products\Filament\Resources\Products\ProductResource;

class EditProduct extends EditRecord
{
    use PersistsAttributeValues;

    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('attributeValues.attribute');

        foreach ($this->record->attributeValues as $attributeValue) {
            $data['attribute_values'][$attributeValue->attribute->code] =
                $this->hydrateValue($attributeValue->attribute->type, $attributeValue);
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

Verified against `filament/filament@4.x` docs
(`docs/03-resources/04-editing-records.md`): `mutateFormDataBeforeFill()`,
`mutateFormDataBeforeSave()`, and `afterSave()` are all documented, current
APIs for Edit pages. `RestoreAction`/`ForceDeleteAction`/`DeleteAction` as
header actions on the Edit page is standard soft-delete wiring (same
`--soft-deletes` scaffold would generate this; authored directly here per
Commands step 3).

**No View page.** The locked design leaves this to this blueprint's
judgment — decided: no View page. `EditProduct` is the only detail page;
nothing in the locked design needs a read-only Infolist distinct from the
Edit form, and adding one would duplicate the entire Attributes-section
logic above for no stated benefit. `getPages()` therefore has no `'view'`
entry.

### Table (`ListProducts` page)

No `modifyQueryUsing` needed — unlike Category's root-only list query,
Products have no tree/hierarchy restriction. The default
`ProductResource::getEloquentQuery()` (CatalogScope + default
non-soft-deleted) applies.

```
Column: sku
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: status
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->badge()
  Note: color/label come from the `ProductStatus` enum's `HasColor`/
  `HasLabel` implementation via the model's `status` cast — draft=gray,
  published=success, per the Enum section above. No separate `->color()`
  closure needed; this is the documented behavior for enum-backed `->badge()`
  columns.

Column: attributeSet.name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->label('Attribute Set')->toggleable(isToggledHiddenByDefault: true)

Column: price
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->money('USD', divideBy: 100)->sortable()
  Note: `->money()`'s `divideBy: 100` argument (confirmed in
  `filament/filament@4.x` docs, `packages/tables/docs/02-columns/02-text.md`)
  converts the stored integer cents to a displayed dollar amount — this is
  the documented, built-in way to do it, no custom `formatStateUsing()`
  needed. `->money()` renders nothing (not "$0.00") when the underlying
  value is `null`, so no extra nullable-safe wrapping is needed; it is
  null-safe by default.

Column: categories
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config:
    ->label('Categories')
    ->state(fn (Modules\Products\Models\Product $record): string => $record->categories->pluck('name')->join(', '))

Filter: TrashedFilter
  Component: Filament\Tables\Filters\TrashedFilter
  Docs: https://filamentphp.com/docs/4.x/tables/filters/ternary

Filter: status
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/4.x/tables/filters/select
  Config: ->options(Modules\Products\Enums\ProductStatus::class)

Standard soft-delete actions: EditAction/DeleteAction (row), RestoreAction,
ForceDeleteAction (row — visible only on trashed records, Filament default),
DeleteBulkAction, RestoreBulkAction, ForceDeleteBulkAction (bulk) — default
behavior, no customization.

Explicitly no blocking-guard behavior: unlike Category (which blocks
deleting a category with live subcategories via a `static::deleting()`
model hook + `before()` action-hook early check), Product has no
"children" problem — nothing else in this locked design has a required FK
pointing at `products.id` that would need to be protected before a delete.
Do not add a deletion guard here; there is no rule to enforce.
```

### Pages (`getPages()`)

```
'index' => ListProducts::route('/')
'create' => CreateProduct::route('/create')
'edit' => EditProduct::route('/{record}/edit')
'activities' => ManageProductActivities::route('/{record}/activities')
```

Add to `ProductResource`:
```php
public static function getRecordSubNavigation(Page $page): array
{
    return $page->generateNavigationItems([
        EditProduct::class,
        ManageProductActivities::class,
    ]);
}
```
(`Imports: Filament\Resources\Pages\Page`)

`ManageProductActivities` (History tab — exact pattern from
`blueprints/pim.md` §2):
```
Location: Modules\Products\Filament\Resources\Products\Pages\ManageProductActivities
extends: Filament\Resources\Pages\ManageRelatedRecords
protected static string $resource = ProductResource::class;
protected static string $relationship = 'activitiesAsSubject';
protected static ?string $relatedResource = App\Filament\Resources\ActivityLogs\ActivityLogResource::class;
protected static ?string $navigationLabel = 'History';
protected static ?string $breadcrumb = 'History';
protected static ?string $title = 'History';
```

## Authorization

```
Resource: ProductResource
  Authorization: All authenticated users (no Policy class — per
  blueprints/pim.md's existing convention, tenant isolation via CatalogScope
  + User::canAccessTenant() is the only guard; a user who can access the
  current catalog can fully manage its products)
```

## Tests

```
ProductResource:
  Authorization:
    - a user who is not a member of the current catalog gets a 404 on a
      direct /products/{id}/edit URL for a product in that catalog
    - products belonging to a different catalog never appear in the list,
      even if their IDs are guessed in the URL
    - a product in a different catalog cannot be edited by submitting the
      Edit form directly (assert no write occurred, not just that the UI
      hid the link)

  Validation (use dataset pattern):
    - sku: required, max:255
    - name: required, max:255
    - slug: required, max:255, alpha_dash
    - status: required, valid ProductStatus value
    - price: nullable, integer, min:0

  Uniqueness (dataset pattern, scoped per catalog):
    - sku must be unique within the same catalog (create two products in
      the same catalog with the same sku — second fails; same sku in two
      DIFFERENT catalogs both succeed)
    - slug must be unique within the same catalog (same shape as sku above)

  Component Config:
    - name field's afterStateUpdated sets slug from name only when
      $operation is 'create'; editing name on an existing record does not
      change an already-saved slug (same test shape as Category's
      equivalent test in blueprints/category.md)
    - status column renders as a badge; assert color is 'gray' for a draft
      product and 'success' for a published product
      (assertTableColumnExists('status', fn (TextColumn $column, Product $record) => ...)
      checking the resolved color per record, or assert via the
      ProductStatus enum's getColor() directly against both cases)
    - attribute_set_id Select is ->live() (assertSchemaComponentExists)

  AttributeSet switching (discriminating scenario — see Models section
  "Cross-requirement" for the full rule):
    - "switching the attribute set away and back does not lose or alter
      the original set's saved values": create a Product with AttributeSet
      A and fill in values for A's attributes; save. Reload the Edit page,
      switch attribute_set_id to AttributeSet B, fill in B's values, save.
      Reload the Edit page again, switch attribute_set_id back to A. Assert:
        - the AttributeValue rows for A's attribute ids still exist in the
          database, with their original values unchanged (query directly —
          do not rely on the form's hydration to prove this, since a bug
          could affect both hydration and persistence identically)
        - re-selecting A in the form (fresh page load) re-populates the
          form fields with exactly those original values (hydration proven
          via assertSchemaStateSet with the `attribute_values.*` keys)
        - the AttributeValue rows for B's attribute ids also exist and are
          correct (both sets' data must coexist; this isn't just "A
          survives", B must also have actually been written)
    - "attribute_values never reaches Product's own columns": after
      saving a product with attribute values filled in, assert the
      `products` table row has no `attribute_values` column/attribute and
      that `Product::create()`/update was never passed that key (can be
      asserted indirectly: the product's own fillable columns save
      correctly even when `attribute_values` contains keys that don't
      exist as `Attribute::code`s in a tampered/unexpected payload — the
      `persistAttributeValues()` loop's `if (! $attribute) { continue; }`
      guard should silently skip those rather than erroring)
    - "required attribute fields use that AttributeSet's pivot
      is_required, not a hardcoded value": build two AttributeSets sharing
      no attributes, one with an attribute marked `is_required => true` on
      its pivot, the other with the same Attribute code marked
      `is_required => false`; assert the rendered field's `isRequired()`
      differs per AttributeSet (assertSchemaComponentExists on the
      dot-notation key, checked while that set is selected)

  Category assignment:
    - assigning categories on create persists the pivot rows
      ($product->categories()->pluck('id') matches the submitted set)
    - changing a product's categories on an edit+save round-trip (add one,
      remove one) results in exactly the new set, not the union of old and
      new (assert exact ids, not just a count)
    - the categories table column displays a comma-joined list of category
      names matching the record's actual assigned categories

  Filters:
    - TrashedFilter, selected to "Only Trashed", shows soft-deleted
      products and hides live ones
    - status SelectFilter, set to 'published', shows only published
      products and hides drafts (and vice versa)

  Sorting:
    - can sort by sku
    - can sort by name
    - can sort by price

  Global search:
    - searching by sku finds the product and its result links to that
      product's Edit page
    - searching by slug also finds the product (GloballySearchableAttributes
      includes slug)

  Soft deletes:
    - deleting a product soft-deletes it and it disappears from the
      default (non-trashed) list — no blocking guard applies (unlike
      Category, there is no "children" rule for Product)
    - restoring a soft-deleted product makes it visible in the default
      list again
```

**Test helper verification**: this blueprint assumes the same test stack
`blueprints/category.md` assumes — Pest with `pestphp/pest-plugin-laravel`
(confirmed installed via `composer show`: `pestphp/pest ^5.3`,
`pestphp/pest-plugin-laravel ^5.0`) and Filament's Livewire testing helpers
(`livewire(...)`, `assertSchemaComponentExists`, `assertTableColumnExists`,
`assertSchemaStateSet`, `filterTable`, `sortTable`) per
`filament/filament@4.x`'s testing docs. No repeater-specific helpers
(`Repeater::fake()`) are needed since this blueprint uses no `Repeater`
anywhere. Direct-query assertions against `AttributeValue` (bypassing the
Filament form layer entirely) are explicitly called for in the AttributeSet
-switching tests above, per testing.md's instruction not to derive expected
outcomes purely from the implementation's own calculator/query.

## Summary of flagged assumptions / open questions for reconciliation

1. **`Modules\Attributes\Models\*` namespace** — assumed, not verified
   against `attributes.md` (written in parallel; not readable from here).
2. **`value_integer` column** — unused by this blueprint's type→column
   mapping, since `Attribute::type` has 7 cases and `AttributeValue` has 5
   `value_*` columns. Possible the `attributes.md` author intended it for
   `number` instead of `value_decimal` — reconcile and update both
   `valueColumns()`/`hydrateValue()` together if so.
3. **`multiselect` storage** — JSON-encoded into `value_text`, since no
   array-capable column exists on `AttributeValue` as specified. Flag for
   the `attributes.md` author.
4. **`attribute_set_id` FK on delete** — `nullOnDelete()`, not specified by
   the locked design. Confirm against whatever deletion behavior
   `attributes.md` defines for `AttributeSet`.
5. **`category_product` FK on delete** — `cascadeOnDelete()` on both
   sides, a housekeeping default for this blueprint's own join table; does
   not alter Category's or Product's own deletion rules.
6. **Migration placement/ordering** — `category_product` and `products`
   both have cross-module FKs; this document places both migrations in the
   `products` module and notes the ordering constraint, but actual
   migration timestamps can only be finalized once all three modules
   (`categories`, `attributes`, `products`) are implemented together.
7. **Price display** — no dollars-displayed/cents-stored conversion UI is
   specified for the form's `price` field beyond a `->prefix('cents')`
   helper label; this is a known rough edge, not a decision this blueprint
   makes on the orchestrator's behalf.
8. **No View page** — decided explicitly (see Resources section); flagged
   as this blueprint's own call per the task's instruction to make that
   call.
