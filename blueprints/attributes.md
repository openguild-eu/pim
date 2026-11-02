# Attributes Module

## Context

EAV-style flexible attributes for the PIM. Builds on the foundations in
`blueprints/pim.md` §1 (two-layer multi-tenancy) and §2 (Activity History
convention), and follows the format/conventions established in
`blueprints/category.md`.

**Scope**: three new models (`AttributeSet`, `Attribute`, `AttributeValue`),
the `attribute_set_attributes` pivot (with `position` + `is_required`
columns), and two top-level Filament Resources (`AttributeResource`,
`AttributeSetResource`), each with a History tab. `AttributeValue` gets **no**
Filament Resource of its own — see the dedicated note under its Model
section.

**Build order**: Attributes is built first among the sibling modules;
Products (a separate blueprint, not written here) will later add
`Product.attribute_set_id`, a `hasMany Product` relationship on
`AttributeSet` (deferred — see below), and a dynamic per-attribute-set form
section on the Product resource that edits `AttributeValue` rows inline.
Whether Attributes or Categories is physically scaffolded first in this
codebase is not fixed by this document — Commands below are written so they
work either way.

**Forward reference**: `AttributeValue.product_id` references a `products`
table that does not exist yet in this codebase. This is intentional per the
locked design — see the Models section for exactly how to handle the
migration and why `php artisan migrate` will fail for that one migration
file until the Products module creates `products`.

## Commands

1. Scaffold the module:
   ```
   php artisan make:module attributes --no-interaction
   ```
   Then edit the generated `app-modules/attributes/composer.json` to mirror
   `app-modules/users/composer.json` exactly in shape:
   - package name `modules/attributes`
   - PSR-4: `Modules\Attributes\` => `src/`, `Modules\Attributes\Tests\` =>
     `tests/`, `Modules\Attributes\Database\Factories\` =>
     `database/factories/`, `Modules\Attributes\Database\Seeders\` =>
     `database/seeders/`
   - `extra.laravel.providers`:
     `Modules\Attributes\Providers\AttributesServiceProvider`

2. Add `"modules/attributes": "*"` to the root `composer.json` `require`
   block, then:
   ```
   composer update modules/attributes --no-interaction
   ```

3. **Do not** use `make:filament-resource` / `make:model` /
   `make:filament-page` / `make:filament-relation-manager` for this module —
   their default path resolution targets `app/`, not this module's `src/`
   root, and there's no evidence `internachi/modular` patches that. Author
   every file below directly at its listed path and namespace.

4. Create
   `app-modules/attributes/src/Providers/AttributesServiceProvider.php`,
   matching `Modules\Users\Providers\UsersServiceProvider` exactly (empty
   `register()`, `boot()` calls
   `$this->loadMigrationsFrom(__DIR__.'/../../database/migrations')`).

5. In `app/Providers/Filament/AdminPanelProvider.php`, add a resource
   discovery call:
   ```php
   ->discoverResources(in: base_path('app-modules/attributes/src/Filament/Resources'), for: 'Modules\Attributes\Filament\Resources')
   ```
   and ensure a `Catalog` navigation group exists in `navigationGroups([...])`
   (before the `Settings` group):
   ```php
   NavigationGroup::make()->label('Catalog'),
   ```
   This is the same group `blueprints/category.md` uses. **Check first** —
   if the Categories module was scaffolded before this one, the group and
   its own `discoverResources()` call will already be present; just add this
   module's own `discoverResources()` line alongside it, don't duplicate the
   group registration.

6. In `app/Filament/Resources/ActivityLogs/ActivityLogResource.php`, extend
   `scopeEloquentQueryToTenant()`'s `where()` closure with two more branches
   (both `Attribute` and `AttributeSet` have a direct `catalog_id`, per the
   "Direct catalog_id" shape in `blueprints/pim.md` §2):
   ```php
   ->orWhereHasMorph('subject', [Attribute::class], function (Builder $attributeQuery) use ($tenant): void {
       $attributeQuery->where('catalog_id', $tenant->getKey());
   })
   ->orWhereHasMorph('subject', [AttributeSet::class], function (Builder $attributeSetQuery) use ($tenant): void {
       $attributeSetQuery->where('catalog_id', $tenant->getKey());
   })
   ```
   (add `use Modules\Attributes\Models\Attribute;` and
   `use Modules\Attributes\Models\AttributeSet;` to the imports — if
   `Category::class` is already one of the branches, add these alongside it,
   don't replace it)

7. After creating the migrations below:
   ```
   php artisan migrate
   ```
   **Except** the `attribute_values` migration — see the forward-reference
   note under `AttributeValue` in Models. Either hold that one migration
   file back until the Products module's migration exists, or run
   `php artisan migrate` for everything else first and that file last, once
   `products` exists.

8. `vendor/bin/pint --dirty --format agent`

## Models

### New Model: AttributeSet

```
Model: AttributeSet
  Namespace: Modules\Attributes\Models\AttributeSet
  Table: attribute_sets
  Attributes:
    - id: bigint, primary
    - catalog_id: bigint, foreign(catalogs.id), required
    - name: string, required
    - slug: string, required
    - created_at: timestamp
    - updated_at: timestamp
  Indexes:
    - unique: [catalog_id, slug]
  Relationships:
    - belongsTo: Catalog via catalog_id (method `catalog()` — required name,
      it's the Filament tenant ownership relationship)
    - belongsToMany: Attribute via `attribute_set_attributes` (method
      `attributes()`), pivot columns `position` (integer, default 0),
      `is_required` (boolean, default false) — NO `withTimestamps()`
    - hasMany: Product via attribute_set_id — DEFERRED. `Product` doesn't
      exist in this codebase yet. The Products blueprint must add this
      relationship method to `AttributeSet` when it creates `Product` and
      its `attribute_set_id` column. Do not add a `products()` method now.
  Traits:
    - App\Models\Concerns\BelongsToCatalog
    - Spatie\Activitylog\Models\Concerns\LogsActivity
  Factory: Modules\Attributes\Database\Factories\AttributeSetFactory
```

Migration FK modifier: `catalog_id` — `constrained('catalogs')` (no cascade
specified, same as Category's `catalog_id`; Catalog deletion isn't a defined
feature yet).

**Pivot table naming**: Laravel's default `belongsToMany` pivot table name
for `AttributeSet`/`Attribute` would be `attribute_attribute_set`
(alphabetical singular model names). The locked design calls for
`attribute_set_attributes` instead, so the table name **must** be passed
explicitly as the second argument to `belongsToMany()` on both sides — it's
not derivable from defaults.

```php
Schema::create('attribute_set_attributes', function (Blueprint $table): void {
    $table->foreignId('attribute_set_id')->constrained()->cascadeOnDelete();
    $table->foreignId('attribute_id')->constrained()->restrictOnDelete();
    $table->integer('position')->default(0);
    $table->boolean('is_required')->default(false);
    $table->primary(['attribute_set_id', 'attribute_id']);
});
```

The composite primary key is the mechanism that prevents attaching the same
`Attribute` to the same `AttributeSet` twice (a second `attach()` with the
same pair is a duplicate-key DB error) — no additional application-level
uniqueness check is needed. `attribute_id` uses `restrictOnDelete()`: this is
the "FK restrict is enough" rule referenced under `Attribute` below — it
blocks deleting an `Attribute` that's still attached to any `AttributeSet`,
at the database level, with no custom guard code.

### Model body (exact)

```php
<?php

declare(strict_types=1);

namespace Modules\Attributes\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Attributes\Database\Factories\AttributeSetFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AttributeSet extends Model
{
    use BelongsToCatalog;

    /** @use HasFactory<AttributeSetFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'catalog_id',
        'name',
        'slug',
    ];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * @return BelongsToMany<Attribute, $this>
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'attribute_set_attributes')
            ->withPivot(['position', 'is_required'])
            ->orderByPivot('position');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('attribute_set')
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

### New Model: Attribute

```
Model: Attribute
  Namespace: Modules\Attributes\Models\Attribute
  Table: attributes
  Attributes:
    - id: bigint, primary
    - catalog_id: bigint, foreign(catalogs.id), required
    - code: string, required (machine name, e.g. "color")
    - label: string, required
    - type: string, required (cast to AttributeType enum)
    - options: json, nullable (array of {value,label} pairs — only
      populated/relevant when type is select or multiselect)
    - is_filterable: boolean, required, default:false
    - created_at: timestamp
    - updated_at: timestamp
  Indexes:
    - unique: [catalog_id, code]
  Relationships:
    - belongsTo: Catalog via catalog_id (method `catalog()` — required name)
    - belongsToMany: AttributeSet via `attribute_set_attributes` (method
      `attributeSets()`), pivot columns `position`, `is_required` — same
      pivot table and columns as AttributeSet's side; `withPivot()` must be
      specified identically on both sides per the pivot-tables.md rule, even
      though Attribute's own Filament form never surfaces these pivot
      columns itself (only AttributeSetResource's RelationManager does).
  Traits:
    - App\Models\Concerns\BelongsToCatalog
    - Spatie\Activitylog\Models\Concerns\LogsActivity
  Factory: Modules\Attributes\Database\Factories\AttributeFactory
```

Migration FK modifier: `catalog_id` — `constrained('catalogs')` (same
rationale as above). `type` is stored as a plain `string` column (`$table->string('type')`);
the `AttributeType` enum (below) is applied via an Eloquent cast, not a DB
enum column — this keeps adding a new `AttributeType` case a pure
application-layer change with no migration required.

### Enum: AttributeType

```
Enum: AttributeType
  Namespace: Modules\Attributes\Enums\AttributeType
  Implements: HasLabel
  Backed by: string
  Cases:
    - Text: value 'text', label 'Text'
    - Textarea: value 'textarea', label 'Textarea'
    - Number: value 'number', label 'Number'
    - Boolean: value 'boolean', label 'Boolean'
    - Date: value 'date', label 'Date'
    - Select: value 'select', label 'Select'
    - Multiselect: value 'multiselect', label 'Multiselect'
```

```php
<?php

declare(strict_types=1);

namespace Modules\Attributes\Enums;

use Filament\Support\Contracts\HasLabel;

enum AttributeType: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';
    case Multiselect = 'multiselect';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Textarea => 'Textarea',
            self::Number => 'Number',
            self::Boolean => 'Boolean',
            self::Date => 'Date',
            self::Select => 'Select',
            self::Multiselect => 'Multiselect',
        };
    }
}
```

### Model body (exact)

```php
<?php

declare(strict_types=1);

namespace Modules\Attributes\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Attributes\Database\Factories\AttributeFactory;
use Modules\Attributes\Enums\AttributeType;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Attribute extends Model
{
    use BelongsToCatalog;

    /** @use HasFactory<AttributeFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'catalog_id',
        'code',
        'label',
        'type',
        'options',
        'is_filterable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'options' => 'array',
            'is_filterable' => 'boolean',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * @return BelongsToMany<AttributeSet, $this>
     */
    public function attributeSets(): BelongsToMany
    {
        return $this->belongsToMany(AttributeSet::class, 'attribute_set_attributes')
            ->withPivot(['position', 'is_required']);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('attribute')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->label;
    }
}
```

**No delete-blocking logic**: unlike `Category` (which blocks deleting a
category with live children via a model `deleting()` guard),
`Attribute`'s deletion is governed only by the pivot's `restrictOnDelete()`
FK — attempting to delete an `Attribute` still attached to any
`AttributeSet` raises a database constraint violation. This is a deliberate,
simple choice per the locked design: do not add a `children()`-style guard,
a `before()` notification hook, or any other custom check here. The
trade-off (an unhandled `QueryException` surfaces as a generic error rather
than a friendly Filament notification) is accepted as-is for this round.

### New Model: AttributeValue

```
Model: AttributeValue
  Namespace: Modules\Attributes\Models\AttributeValue
  Table: attribute_values
  Attributes:
    - id: bigint, primary
    - catalog_id: bigint, foreign(catalogs.id), required
    - product_id: bigint, foreign(products.id), required — FORWARD
      REFERENCE, see note below
    - attribute_id: bigint, foreign(attributes.id), required
    - value_text: string, nullable
    - value_integer: integer, nullable
    - value_decimal: decimal(10,2), nullable
    - value_boolean: boolean, nullable
    - value_date: date, nullable
  Indexes:
    - unique: [product_id, attribute_id]
  Relationships:
    - belongsTo: Attribute via attribute_id (method `attribute()`)
    - belongsTo: Product via product_id — DEFERRED, same reason as
      `AttributeSet::products()` above. Do not add a `product()` method now;
      the Products blueprint adds it once `Product` exists.
  Traits:
    - App\Models\Concerns\BelongsToCatalog
  Factory: none yet — deferred to the Products blueprint (a factory for this
    model needs `Product::factory()`, which doesn't exist)
```

**No `LogsActivity`.** This is a deliberate decision, not an omission:
individual attribute-value changes aren't separately audited this round —
`Attribute` and `AttributeSet` (the definitions) are audited, but the
per-product values they produce are not. Revisit if/when the Products
blueprint needs value-level history.

**No timestamps.** The locked schema for this model lists no
`created_at`/`updated_at`. Set `protected $timestamps = false;` on the model
to match — don't add timestamp columns that aren't in the spec.

**Forward reference — `product_id`.** The `products` table doesn't exist in
this codebase yet (Products hasn't been built). Per the locked design, still
declare the full FK in the migration:

```php
Schema::create('attribute_values', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('catalog_id')->constrained('catalogs');
    $table->foreignId('product_id')->constrained('products');
    $table->foreignId('attribute_id')->constrained('attributes')->restrictOnDelete();
    $table->string('value_text')->nullable();
    $table->integer('value_integer')->nullable();
    $table->decimal('value_decimal', 10, 2)->nullable();
    $table->boolean('value_boolean')->nullable();
    $table->date('value_date')->nullable();
    $table->unique(['product_id', 'attribute_id']);
});
```

This migration file **will fail at `php artisan migrate` time** until a
`products` table exists — `constrained('products')` requires the referenced
table to already be present. Create the migration file now (it locks in the
agreed schema for whoever builds Products next), but don't run it as part of
this module's migrate step. Run it only once the Products module's own
migration has created `products`, or hand it to that module's implementer to
run alongside their migration.

```php
<?php

declare(strict_types=1);

namespace Modules\Attributes\Models;

use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeValue extends Model
{
    use BelongsToCatalog;

    public $timestamps = false;

    protected $fillable = [
        'catalog_id',
        'product_id',
        'attribute_id',
        'value_text',
        'value_integer',
        'value_decimal',
        'value_boolean',
        'value_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:2',
            'value_boolean' => 'boolean',
            'value_date' => 'date',
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
```

**No Filament Resource for `AttributeValue`.** Do not scaffold
`AttributeValueResource`, a page, or a RelationManager for it in this
module. Only one of the five `value_*` columns is ever populated on a given
row — whichever matches that row's `attribute.type` (`text`/`textarea` →
`value_text`, `number` → `value_integer` or `value_decimal`, `boolean` →
`value_boolean`, `date` → `value_date`, `select`/`multiselect` →
`value_text`, storing the selected option's `value`). This typed-column EAV
shape (rather than one JSON/text blob on `Product`) is exactly why this
architecture was chosen — it keeps SQL-level filtering and sorting on
attribute values possible. `AttributeValue` rows are edited inline on
`Product`'s own form, which is the Products blueprint's responsibility. If
you're implementing this module and don't see that UI anywhere, that's
correct — it doesn't belong here.

## Resources

```
Resource: AttributeResource
  Command: php artisan make:filament-resource Attribute --no-interaction
  Location: Modules\Attributes\Filament\Resources\Attributes\AttributeResource
  Docs: https://filamentphp.com/docs/4.x/resources/overview
  RecordTitleAttribute: label
  GloballySearchableAttributes: [code, label]

  Navigation:
    Group: Catalog
    Icon: Filament\Support\Icons\Heroicon::OutlinedTag
    Sort: 2
```

**Form** (`Columns: 1`):

```
Imports:
  - Filament\Schemas\Components\Utilities\Get

Field: code
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255, alpha_dash, unique per catalog
  Config:
    ->required()
    ->maxLength(255)
    ->alphaDash()
    ->scopedUnique(ignoreRecord: true)
    ->helperText('Machine name used internally (e.g. by the Products form). Lowercase letters, numbers, and dashes only.')

Field: label
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255
  Config: ->required()->maxLength(255)

Field: type
  Component: Filament\Forms\Components\Select
  Docs: https://filamentphp.com/docs/4.x/forms/select
  Validation: required, must be a valid AttributeType value
  Config:
    ->required()
    ->options(Modules\Attributes\Enums\AttributeType::class)
    ->native(false)
    ->live()

Field: options
  Component: Filament\Forms\Components\Repeater
  Docs: https://filamentphp.com/docs/4.x/forms/repeater
  Validation: required (array, at least one item) only when `type` is
    `select` or `multiselect`; each item: value required max:255, label
    required max:255
  Config:
    ->schema([
        Filament\Forms\Components\TextInput::make('value')->required()->maxLength(255),
        Filament\Forms\Components\TextInput::make('label')->required()->maxLength(255),
    ])
    ->columns(2)
    ->addActionLabel('Add option')
    ->visible(fn (Get $get): bool => in_array($get('type'), ['select', 'multiselect']))
    ->required(fn (Get $get): bool => in_array($get('type'), ['select', 'multiselect']))
    ->dehydrateStateUsing(fn (Get $get, ?array $state): ?array => in_array($get('type'), ['select', 'multiselect']) ? $state : null)

Field: is_filterable
  Component: Filament\Forms\Components\Toggle
  Docs: https://filamentphp.com/docs/4.x/forms/toggle
  Validation: boolean
  Config: ->default(false)
```

**Reactive-fields consistency check** (per `overview.md`'s cross-requirement
format — this is the one canonical rule for `options`, covering hydrate,
react, validate, save, and reload):

- **Rule**: `options` is populated/required/saved if and only if the
  *current* value of `type` is `select` or `multiselect`. There is exactly
  one place this is decided — the `in_array($get('type'), ['select',
  'multiselect'])` check, reused identically across `->visible()`,
  `->required()`, and `->dehydrateStateUsing()`. Don't duplicate this list
  anywhere else (e.g. a separate Laravel validation rule) — it would be a
  second, driftable source of truth.
- **Stages**:
  1. Hydrate (edit): an existing `select`-type Attribute loads with its
     saved `options` array shown and editable; an existing `text`-type
     Attribute loads with `options` hidden (its DB value is `null`).
  2. User changes `type` (`->live()` on `type` re-renders the form):
     switching *to* `select`/`multiselect` reveals `options`, now required,
     starting empty if it had no prior value. Switching *away* hides it
     again; whatever was typed into it remains in Livewire's in-memory form
     state (not cleared on blur) but is a dead end — see save below.
  3. Validate on submit: if `type` is `select`/`multiselect`, `options`
     must be non-empty with both sub-fields filled; if `type` is anything
     else, no validation runs on `options` at all (its `required()` closure
     evaluates false).
  4. Save: `dehydrateStateUsing` re-checks `type` one more time at the
     actual save point — if `type` is not `select`/`multiselect`, `options`
     is written as `null` regardless of whatever is sitting in form state.
     This is what actually enforces "only one of these is ever meaningful";
     steps 2–3 alone would let stale `options` data survive a save if a
     user filled it in, switched to `text`, then submitted.
  5. Reload: re-opening the record after save reflects step 1 exactly —
     `options` is `null` for non-select/multiselect types, non-empty for
     select/multiselect ones.
- **Consistency check / discriminating test**: create a `select`-type
  Attribute with two options, switch `type` to `text` *without saving*,
  submit. Independently derived expectation: save succeeds (no validation
  error — `options` isn't required for `text`), and the persisted
  `options` column is `null`, not the two options that were still sitting
  in form state. A wrong implementation that only used `->visible()` +
  `->required()` (no `dehydrateStateUsing`) would incorrectly persist the
  stale array. See Tests.

**Table** (`ListAttributes` page):

```
Column: code
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: label
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: type
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->badge()

Column: is_filterable
  Component: Filament\Tables\Columns\IconColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/icon
  Config: ->boolean()

Filter: type
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/4.x/tables/filters/select
  Config: ->options(Modules\Attributes\Enums\AttributeType::class)

Action: Delete (row)
  Component: Filament\Actions\DeleteAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Location: table row
  Authorization: all authenticated catalog members (see Authorization)
  Behavior: default (no `before()` guard — see "No delete-blocking logic"
    note under the Attribute model above; the pivot's `restrictOnDelete()`
    FK is the only enforcement)

Bulk Action: Delete
  Component: Filament\Actions\BulkActions\DeleteBulkAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Behavior: default (same rationale as the row action)
```

**Pages** (`getPages()`):

```
'index' => ListAttributes::route('/')
'create' => CreateAttribute::route('/create')
'edit' => EditAttribute::route('/{record}/edit')
'activities' => ManageAttributeActivities::route('/{record}/activities')
```

Add to `AttributeResource`:
```php
public static function getRecordSubNavigation(Page $page): array
{
    return $page->generateNavigationItems([
        EditAttribute::class,
        ManageAttributeActivities::class,
    ]);
}
```
(`Imports: Filament\Resources\Pages\Page`)

`ManageAttributeActivities` (History tab — exact pattern from
`blueprints/pim.md` §2):
```
Location: Modules\Attributes\Filament\Resources\Attributes\Pages\ManageAttributeActivities
extends: Filament\Resources\Pages\ManageRelatedRecords
protected static string $resource = AttributeResource::class;
protected static string $relationship = 'activitiesAsSubject';
protected static ?string $relatedResource = App\Filament\Resources\ActivityLogs\ActivityLogResource::class;
protected static ?string $navigationLabel = 'History';
protected static ?string $breadcrumb = 'History';
protected static ?string $title = 'History';
```

---

```
Resource: AttributeSetResource
  Command: php artisan make:filament-resource AttributeSet --no-interaction
  Location: Modules\Attributes\Filament\Resources\AttributeSets\AttributeSetResource
  Docs: https://filamentphp.com/docs/4.x/resources/overview
  RecordTitleAttribute: name
  GloballySearchableAttributes: [name, slug]

  Navigation:
    Group: Catalog
    Icon: Filament\Support\Icons\Heroicon::OutlinedRectangleGroup
    Sort: 3
```

**Form** (`Columns: 1`) — only `name`/`slug`; attaching Attributes is a
separate RelationManager tab on the Edit page, not part of this schema:

```
Imports:
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
```

Same auto-slug-from-name-on-create pattern as Category and the same
reactive-fields consistency rule applies verbatim: `afterStateUpdated` only
writes `slug` when `$operation === 'create'`; editing `name` on an existing
record never overwrites an already-saved `slug`.

### Attaching Attributes: RelationManager vs inline Repeater

**Decision: RelationManager.** Per `relationships.md`'s decision guide, a
RelationManager is the right call here because the attached-attributes
table needs: its own reorderable listing (`position`), an editable pivot
column (`is_required`) independent of editing the `Attribute` record itself,
and attach/detach semantics rather than create/delete (the `Attribute`
records themselves have independent lifecycles managed by
`AttributeResource` — attaching one to a set never creates or deletes the
`Attribute`). An inline Repeater bound to the `attributes` relationship
would conflate "pick an existing Attribute" with "edit the Attribute's own
fields," which isn't the requirement, and Repeater's relationship mode is a
poor fit for a `belongsToMany` with pivot metadata that needs reordering by
database column — the Table Builder's native pivot-aware `reorderable()`
(confirmed below) already does exactly that.

```
RelationManager: AttributesRelationManager
  Command: php artisan make:filament-relation-manager AttributeSetResource attributes label --attach --no-interaction
  Location: Modules\Attributes\Filament\Resources\AttributeSets\RelationManagers\AttributesRelationManager
  Relationship: attributes (belongsToMany Attribute, pivot attribute_set_attributes)
  Title attribute: label
  Can create: no — Attributes are created only via AttributeResource; this
    manager only attaches/detaches existing ones (AttachAction, not
    CreateAction)
  Can edit the Attribute record: no — editing code/label/type/options
    happens on AttributeResource, not here
  Can edit the pivot's is_required: yes — via the custom "Edit requirement"
    row action below (updates only the pivot row, not the Attribute)
  Can detach: yes (DetachAction / DetachBulkAction — removes the pivot row
    only, never deletes the Attribute)
  Can reorder: yes, by the pivot's position column
```

Register it on the resource:
```php
public static function getRelations(): array
{
    return [
        AttributesRelationManager::class,
    ];
}
```
Filament's `EditAttributeSet` page renders this automatically as an
additional tab alongside the edit form (no extra wiring needed — this is
the base `EditRecord` page's standard behavior when
`static::getResource()::getRelations()` is non-empty).

**Table**:

```
Config: ->reorderable('position')->defaultSort('position')
```
Verified against `Filament\Tables\Concerns\CanReorderRecords::reorderTable()`
(installed `filament/filament` v4.16.0 source,
`vendor/filament/tables/src/Concerns/CanReorderRecords.php`): when the
table's underlying relationship is a `BelongsToMany` and the reorder column
is one of the relationship's pivot columns, Filament automatically updates
`attribute_set_attributes.position` via the pivot query directly — no custom
reorder code is needed. This is the same mechanism `blueprints/category.md`
uses for its (non-pivot) `position` column, just confirmed here to also
cover the pivot case.

```
Column: code
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()

Column: label
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()

Column: type
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->badge()

Column: is_required
  Component: Filament\Tables\Columns\IconColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/icon
  Config: ->boolean()
  Note: this is the pivot column — Filament resolves `is_required` from
  `$record->pivot->is_required` automatically because it's listed in
  `withPivot()` on both sides (confirmed in managing-relationships.md:
  "pivot attributes work like normal columns"). Read-only display; use the
  "Edit requirement" action below to change it.
```

**Action: Attach**

```
Action: AttachAction
  Component: Filament\Actions\AttachAction
  Docs: https://filamentphp.com/docs/4.x/resources/managing-relationships
  Location: table header
  Authorization: all authenticated catalog members
  Behavior:
```
```php
use Filament\Actions\AttachAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;

AttachAction::make()
    ->preloadRecordSelect()
    ->recordSelectSearchColumns(['code', 'label'])
    ->schema(fn (AttachAction $action): array => [
        $action->getRecordSelect(),
        Toggle::make('is_required')
            ->label('Required')
            ->default(false),
    ])
    ->using(function (RelationManager $livewire, array $data): void {
        $ownerRecord = $livewire->getOwnerRecord();

        $position = ($ownerRecord->attributes()->max('attribute_set_attributes.position') ?? -1) + 1;

        $ownerRecord->attributes()->attach($data['recordId'], [
            'position' => $position,
            'is_required' => $data['is_required'] ?? false,
        ]);
    })
```
Two independent guarantees prevent attaching the same `Attribute` twice:
`getRecordSelect()`'s options already exclude already-attached records
(confirmed in `vendor/filament/actions/src/AttachAction.php`'s
`getOptions()` closure — it applies `whereDoesntHave($inverseRelationship,
...)` unless `allowsDuplicates()`, which we don't enable), and even a
tampered request hits the pivot table's composite primary key. `position` is
computed the same way Category computes its sibling position
(`max(...) ?? -1) + 1`) — explicit in `using()` rather than a model
`creating()` hook, because `BelongsToMany::attach()` performs a raw insert
and does not fire Eloquent model events on a custom Pivot class.

**Action: Edit requirement** (pivot-only edit)

```
Action: EditRequirement
  Component: Filament\Actions\Action
  Docs: https://filamentphp.com/docs/4.x/actions/overview
  Location: table row
  Authorization: all authenticated catalog members
  Behavior:
```
```php
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;

Action::make('editRequirement')
    ->label('Edit requirement')
    ->icon(Heroicon::OutlinedPencil)
    ->schema([
        Toggle::make('is_required')->label('Required'),
    ])
    ->fillForm(fn (Attribute $record): array => [
        'is_required' => $record->pivot->is_required,
    ])
    ->action(function (Attribute $record, array $data): void {
        $record->pivot->update(['is_required' => $data['is_required']]);
    })
```
(`Imports: Filament\Support\Icons\Heroicon`, `Modules\Attributes\Models\Attribute`)

**Can detach**: `DetachAction` / `DetachBulkAction` (scaffolded by
`--attach`), default behavior — removes the pivot row only.

**Pages** (`getPages()`):

```
'index' => ListAttributeSets::route('/')
'create' => CreateAttributeSet::route('/create')
'edit' => EditAttributeSet::route('/{record}/edit')
'activities' => ManageAttributeSetActivities::route('/{record}/activities')
```

Add to `AttributeSetResource`:
```php
public static function getRecordSubNavigation(Page $page): array
{
    return $page->generateNavigationItems([
        EditAttributeSet::class,
        ManageAttributeSetActivities::class,
    ]);
}
```
(The `attributes` RelationManager is a tab *within* `EditAttributeSet`, not
a separate sub-navigation entry — unlike Category's `ManageSubcategories`,
which is its own routed Page.)

**Main list table** (`ListAttributeSets` page):

```
Column: name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: slug
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->toggleable(isToggledHiddenByDefault: true)

Column: attributes_count
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->counts('attributes')->label('Attributes')->sortable()

Column: created_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)

Action: Delete (row)
  Component: Filament\Actions\DeleteAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Location: table row
  Behavior: default — no children-style guard. Deleting an AttributeSet
    cascades its `attribute_set_attributes` pivot rows
    (`cascadeOnDelete()` on `attribute_set_id`), detaching all its
    Attributes; it never deletes the Attributes themselves.

Bulk Action: Delete
  Component: Filament\Actions\BulkActions\DeleteBulkAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Behavior: default, same rationale
```

`ManageAttributeSetActivities` (History tab — same exact pattern):
```
Location: Modules\Attributes\Filament\Resources\AttributeSets\Pages\ManageAttributeSetActivities
extends: Filament\Resources\Pages\ManageRelatedRecords
protected static string $resource = AttributeSetResource::class;
protected static string $relationship = 'activitiesAsSubject';
protected static ?string $relatedResource = App\Filament\Resources\ActivityLogs\ActivityLogResource::class;
protected static ?string $navigationLabel = 'History';
protected static ?string $breadcrumb = 'History';
protected static ?string $title = 'History';
```

## Authorization

```
Resource: AttributeResource
  Authorization: All authenticated users (no Policy class — per
  blueprints/pim.md's existing convention, tenant isolation via CatalogScope
  + User::canAccessTenant() is the only guard)

Resource: AttributeSetResource
  Authorization: All authenticated users (same rationale)
```

## Tests

```
AttributeResource:
  Authorization:
    - a user who is not a member of the current catalog gets a 404 on a
      direct /attributes/{id}/edit URL for an attribute in that catalog
    - attributes belonging to a different catalog never appear in the list,
      even if their IDs are guessed in the URL

  Validation (use dataset pattern):
    - code: required, max:255, alpha_dash
    - code must be unique within the same catalog (create two attributes in
      the same catalog with the same code — second fails; same code in two
      DIFFERENT catalogs both succeed)
    - label: required, max:255
    - type: required, must be one of the AttributeType enum values

  Component Config (options field — discriminating, not a final-state-only
  fill; see the reactive-fields consistency check above):
    - creating an Attribute with type 'text' never requires `options` and
      the Repeater is not visible
    - setting type to 'select' makes `options` visible and required;
      submitting with an empty options array fails validation
    - setting type to 'select', filling two options, submitting succeeds
      and persists both {value,label} pairs as the `options` JSON array
    - setting type to 'select', filling two options, THEN switching type
      back to 'text' before submitting: submit succeeds (no validation
      error), and the persisted `options` column is null — the stale
      Repeater state must not be saved just because it was once filled in
    - editing an existing 'select'-type attribute: the Repeater hydrates
      with its saved options already populated and editable

  Tenant isolation:
    - an Attribute from catalog A is not selectable in catalog B's
      AttributeSetResource "Attach" record select, even by guessing its ID
      in a crafted request (CatalogScope applies to AttachAction's
      underlying relationship query)

  Deletion:
    - deleting an Attribute with no AttributeSet attachments succeeds
    - deleting an Attribute that is still attached to at least one
      AttributeSet throws (the pivot's restrictOnDelete() FK) — assert the
      Attribute still exists afterward (no custom notification expected,
      per the locked "no custom guard" decision)

AttributeSetResource:
  Authorization:
    - a user who is not a member of the current catalog gets a 404 on a
      direct /attribute-sets/{id}/edit URL for a set in that catalog
    - attribute sets belonging to a different catalog never appear in the
      list, even if their IDs are guessed in the URL

  Validation (use dataset pattern):
    - name: required, max:255
    - slug: required, max:255, alpha_dash
    - slug must be unique within the same catalog (same cross-catalog
      exception pattern as Category's slug test)

  Component Config:
    - name field's afterStateUpdated sets slug from name only when
      $operation is 'create'; editing name on an existing record does not
      change an already-saved slug

  AttributesRelationManager:
    - renders only the Attributes currently attached to the given
      AttributeSet (not every Attribute in the catalog)
    - attaching an Attribute via AttachAction persists a pivot row with
      is_required matching the toggle submitted in the attach form
    - attaching a first Attribute assigns position = 0; attaching a second
      assigns position = 1 (independently derived from "previous max + 1",
      not read back from the implementation)
    - attaching the SAME Attribute to the SAME AttributeSet twice is
      prevented: assert it does not appear twice in
      `$attributeSet->attributes` after a second attach attempt
      (already-attached records are excluded from the AttachAction's
      record select, and a bypassed/duplicate attempt raises a DB
      duplicate-key error rather than creating a second row)
    - reordering (drag-and-drop) on this relation manager's table persists
      new `position` values to the `attribute_set_attributes` pivot rows for
      this AttributeSet's attachments only — attaching the same Attribute to
      a DIFFERENT AttributeSet and reordering there does not affect the
      first AttributeSet's positions
    - the "Edit requirement" action updates only the pivot's is_required
      column — assert the underlying Attribute's own `label`/`code`/`type`
      are unchanged after calling it
    - detaching an Attribute removes the pivot row but leaves the
      Attribute record itself intact (still visible in AttributeResource's
      own list)

  Tenant isolation:
    - an AttributeSet from catalog A is not visible in catalog B's list,
      even with its ID guessed in the URL

  Global search:
    - searching by name finds an AttributeSet and its result links to that
      set's Edit page
```
