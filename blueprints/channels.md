# Channels Module

## Context

Multi-channel publishing assignment for the PIM. Builds on the foundations in
`blueprints/pim.md` §1 (two-layer multi-tenancy) and §2 (Activity History
convention), and follows the exact module-scaffolding, slug-pattern, and
"no Policy" conventions established in `blueprints/category.md`.

**Scope**: `Channel` model (flat, no tree — just a named publishing target per
catalog, e.g. "Webshop", "Marketplace", "Print Catalog"), CRUD resource, and
a `belongsToMany` assignment to `Product` via a new `channel_product` pivot
table carrying `is_published` / `published_at`. This is the **fourth** module
in build order — **Attributes → Products → Media → Channels → Relations →
Import/Export** — and depends on `Modules\Products\Models\Product` already
existing (defined by the sibling `blueprints/products.md`) before the
`Product`-side relationship and `RelationManager` below can be implemented.

**Cross-blueprint coordination on `Product`**: this is one of **three**
sibling blueprints that each add a relationship method to the `Product`
class body defined in `blueprints/products.md` (Media and Relations are the
other two — Import/Export does not touch the model body). When actually
implemented, apply all three additions to the same `Product` class, in build
order, so no later module's edit silently drops an earlier one's method.
This document only specifies the one addition Channels is responsible for:
`channels(): BelongsToMany`.

## Commands

1. Scaffold the module:
   ```
   php artisan make:module channels --no-interaction
   ```
   Then edit the generated `app-modules/channels/composer.json` to mirror
   `app-modules/users/composer.json` exactly in shape:
   - package name `modules/channels`
   - PSR-4: `Modules\Channels\` => `src/`, `Modules\Channels\Tests\` =>
     `tests/`, `Modules\Channels\Database\Factories\` =>
     `database/factories/`, `Modules\Channels\Database\Seeders\` =>
     `database/seeders/`
   - `extra.laravel.providers`: `Modules\Channels\Providers\ChannelsServiceProvider`

2. Add `"modules/channels": "*"` to the root `composer.json` `require` block
   (next to `"modules/users": "*"`), then:
   ```
   composer update modules/channels --no-interaction
   ```

3. **Do not** use `make:filament-resource` / `make:model` /
   `make:filament-relation-manager` / `make:migration` for this module —
   their default path resolution targets `app/` and `database/migrations/`
   at the application root, not this module's `src/`/`database/` roots, and
   there's no evidence `internachi/modular` patches that (same caveat
   `category.md` notes). Author every file below directly at its listed path
   and namespace.

4. Create `app-modules/channels/src/Providers/ChannelsServiceProvider.php`,
   matching `Modules\Users\Providers\UsersServiceProvider` exactly (empty
   `register()`, `boot()` calls
   `$this->loadMigrationsFrom(__DIR__.'/../../database/migrations')`).

5. In `app/Providers/Filament/AdminPanelProvider.php`, add a resource
   discovery call (alongside the existing `Modules\Categories\...` and
   `Modules\Products\...` ones from the earlier-built sibling modules):
   ```php
   ->discoverResources(in: base_path('app-modules/channels/src/Filament/Resources'), for: 'Modules\Channels\Filament\Resources')
   ```
   **Do not** add a new entry to `navigationGroups([...])` — `NavigationGroup::make()->label('Catalog')`
   is already registered by whichever of Category/Products was built first
   (both blueprints register it). Check the array before editing; it should
   contain exactly one `Catalog` entry, not two.

6. In `app/Filament/Resources/ActivityLogs/ActivityLogResource.php`, extend
   `scopeEloquentQueryToTenant()`'s `where()` closure with an additional
   branch (`Channel` has a direct `catalog_id`, per the "Direct catalog_id"
   shape in `blueprints/pim.md` §2) — add it alongside whatever branches
   Category/Products already added, don't replace them:
   ```php
   ->orWhereHasMorph('subject', [Channel::class], function (Builder $channelQuery) use ($tenant): void {
       $channelQuery->where('catalog_id', $tenant->getKey());
   })
   ```
   (add `use Modules\Channels\Models\Channel;` to the imports)

7. After creating the migrations (step in Models below): `php artisan migrate`

8. In `Modules\Products\Filament\Resources\Products\ProductResource` (owned
   by the Products blueprint, already built by the time this module is
   implemented), register the new `ChannelsRelationManager` in
   `getRelations()` — see Resources §"Update Existing Resource: ProductResource".

9. `vendor/bin/pint --dirty --format agent`

## Models

### New Model: Channel

```
Model: Channel
  Namespace: Modules\Channels\Models\Channel
  Table: channels
  Attributes:
    - id: bigint, primary
    - catalog_id: bigint, foreign(catalogs.id), required
    - name: string, required
    - slug: string, required
    - is_active: boolean, required, default:true
    - created_at: timestamp
    - updated_at: timestamp
  Indexes:
    - unique: [catalog_id, slug]
  Relationships:
    - belongsTo: Catalog via catalog_id (method `catalog()` — required name,
      it's the Filament tenant ownership relationship)
    - belongsToMany: Product via channel_product
      Pivot columns: is_published (boolean, default false), published_at (timestamp, nullable)
  Traits:
    - App\Models\Concerns\BelongsToCatalog
    - Spatie\Activitylog\Models\Concerns\LogsActivity
  Factory: Modules\Channels\Database\Factories\ChannelFactory
```

No `SoftDeletes` — unlike `Category`, `Channel` is deliberately **not**
soft-deleted (see "Deletion decision" below for why a hard delete is correct
here). No `deleted_at` column, no `TrashedFilter`, no restore/force-delete
actions anywhere in this module.

Migration FK modifiers: `catalog_id` — `constrained('catalogs')` (no cascade
specified, same as Category — Catalog deletion isn't a defined feature yet).

**Migration 1** — `app-modules/channels/database/migrations/<timestamp>_create_channels_table.php`:
```php
Schema::create('channels', function (Blueprint $table) {
    $table->id();
    $table->foreignId('catalog_id')->constrained('catalogs');
    $table->string('name');
    $table->string('slug');
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['catalog_id', 'slug']);
});
```

**Migration 2** — `app-modules/channels/database/migrations/<timestamp>_create_channel_product_table.php`
(run after `products` exists, i.e. after the Products module's migrations —
sequence the timestamp accordingly):
```php
Schema::create('channel_product', function (Blueprint $table) {
    $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->boolean('is_published')->default(false);
    $table->timestamp('published_at')->nullable();
    $table->timestamps();

    $table->primary(['channel_id', 'product_id']);
});
```
Both FKs `cascadeOnDelete()` — this is the mechanism behind the deletion
decision below: deleting either a `Channel` or a `Product` row removes its
`channel_product` rows automatically at the database level, no model-level
guard needed.

### Model body (exact — covers the non-trivial rules; copy as-is)

```php
<?php

declare(strict_types=1);

namespace Modules\Channels\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Channels\Database\Factories\ChannelFactory;
use Modules\Products\Models\Product;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Channel extends Model
{
    use BelongsToCatalog;

    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'catalog_id',
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * `withPivot()` must match Product::channels() exactly (both sides) —
     * see blueprints/pim.md's pivot-tables convention and the "Update
     * Existing Model: Product" section below. `is_published`/`published_at`
     * are written in exactly two places across the whole module (the Attach
     * form and the RelationManager's inline toggle, both on the Product
     * side) — see Resources §"Update Existing Resource: ProductResource"
     * for the single canonical synchronization rule.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'channel_product')
            ->withPivot(['is_published', 'published_at'])
            ->withTimestamps();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('channel')
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

**Deletion decision — allowed, not blocked.** Unlike `Category` (a
structural container: deleting a parent with live children would orphan or
silently cascade-destroy a subtree, which is why `category.md` adds a
`deleting()` guard), a `Channel` is just an assignment target, not a
container. Nothing is structurally "inside" a Channel — `Product` rows are
never owned by it and never depend on it existing. Deleting a Channel that
still has Products assigned to it is **allowed**: the `channel_product`
pivot rows for that channel are removed automatically via the migration's
`cascadeOnDelete()` on `channel_id` (see Migration 2 above). There is no
`deleting()` guard on `Channel` and no pre-delete "still has Products"
confirmation — this mirrors how detaching one Product from one Channel
already works (a single pivot-row deletion with no side effects beyond
losing that one assignment); deleting the Channel is just that same
operation applied to every row at once. No data loss beyond the assignment
itself occurs, and the assignment is trivially re-creatable if deleted by
mistake (re-attach from either side), so the extra friction `Category`'s
guard is worth paying isn't justified here.

### Update Existing Model: Product

```
Migration: none (relationship method only, no new column)
Model: Modules\Products\Models\Product (defined by blueprints/products.md)
  Add relationship:
    - belongsToMany: Channel via channel_product
      Pivot columns: is_published (boolean, default false), published_at (timestamp, nullable)
```

Add to the `Product` class body — **identical `withPivot()`/`withTimestamps()`
call to `Channel::products()` above**, per `pivot-tables.md`'s critical rule
that both sides must list the same pivot columns:

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Channels\Models\Channel;

public function channels(): BelongsToMany
{
    return $this->belongsToMany(Channel::class, 'channel_product')
        ->withPivot(['is_published', 'published_at'])
        ->withTimestamps();
}
```

This is applied to `Product` alongside whatever Media and Relations also add
there (each blueprint documents only its own method); when actually
implemented, add all three methods to the one `Product` class in build
order, without removing an earlier module's addition.

## Resources

```
Resource: ChannelResource
  Location: Modules\Channels\Filament\Resources\Channels\ChannelResource
  Docs: https://filamentphp.com/docs/4.x/resources/overview
  RecordTitleAttribute: name
  GloballySearchableAttributes: [name, slug]

  Navigation:
    Group: Catalog
    Icon: Filament\Support\Icons\Heroicon::OutlinedMegaphone
    Sort: 5
```

**Form** (used by Create and Edit pages — `Columns: 1`):

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

Field: is_active
  Component: Filament\Forms\Components\Toggle
  Docs: https://filamentphp.com/docs/4.x/forms/toggle
  Validation: boolean
  Config: ->default(true)
```

Reactive-fields consistency check: identical reasoning to `Category`'s
`name`/`slug` pair in `blueprints/category.md` — `afterStateUpdated` only
writes `slug` when `$operation === 'create'`, so editing `name` on an
existing Channel never overwrites a slug that may already be published in a
feed/URL elsewhere. Each keystroke re-derives `slug` from the *current*
`name`, not the original.

**Table** (`ListChannels` page):

```
Column: name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: slug
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->toggleable(isToggledHiddenByDefault: true)

Column: is_active
  Component: Filament\Tables\Columns\IconColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/icon
  Config: ->boolean()->sortable()

Column: products_count
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->counts('products')->label('Products')->sortable()

Column: created_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)

Action: Delete (row)
  Component: Filament\Actions\DeleteAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Location: table row
  Authorization: all authenticated catalog members (see Authorization section)
  Behavior: none — no before() guard. Per the Deletion decision above,
    deleting a Channel with Products still assigned is allowed; the
    `channel_product` rows cascade-delete at the database level.

Bulk Action: Delete
  Component: Filament\Actions\BulkActions\DeleteBulkAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Behavior: none — same as the row action, no guard.
```

No `TrashedFilter` (no soft deletes on this model — see Models section).

**Pages** (`getPages()`):

```
'index' => ListChannels::route('/')
'create' => CreateChannel::route('/create')
'edit' => EditChannel::route('/{record}/edit')
'activities' => ManageChannelActivities::route('/{record}/activities')
```

Add to `ChannelResource`:
```php
public static function getRecordSubNavigation(Page $page): array
{
    return $page->generateNavigationItems([
        EditChannel::class,
        ManageChannelActivities::class,
    ]);
}
```
(`Imports: Filament\Resources\Pages\Page`)

`ManageChannelActivities` (History tab — exact pattern from
`blueprints/pim.md` §2):
```
Location: Modules\Channels\Filament\Resources\Channels\Pages\ManageChannelActivities
extends: Filament\Resources\Pages\ManageRelatedRecords
protected static string $resource = ChannelResource::class;
protected static string $relationship = 'activitiesAsSubject';
protected static ?string $relatedResource = App\Filament\Resources\ActivityLogs\ActivityLogResource::class;
protected static ?string $navigationLabel = 'History';
protected static ?string $breadcrumb = 'History';
protected static ?string $title = 'History';
```

## Update Existing Resource: ProductResource

Add a "Channels" tab to `Modules\Products\Filament\Resources\Products\ProductResource`
as a `RelationManager` — **not** a `ManageRelatedRecords` top-level page,
since this is a `belongsToMany` attach/detach interface onto an existing
Product, not a drill-down hierarchy (per `relationships.md`'s decision
guide: "Related records have editable fields beyond the association" plus
pivot data to view/edit → RelationManager).

Scaffold reference (same caveat as `make:filament-resource` — its default
path resolution targets `app/Filament/Resources/...`, not the Products
module's `src/` root, so author the file directly instead of trusting the
command's output path):
```
php artisan make:filament-relation-manager ProductResource channels name --attach --no-interaction
```

```
RelationManager: ChannelsRelationManager
  Location: Modules\Products\Filament\Resources\Products\RelationManagers\ChannelsRelationManager
  Relationship: channels (belongsToMany Channel, defined above on Product)
  Title attribute: name
  Can create: no — Channels are managed by ChannelResource; this tab only
    attaches/detaches EXISTING channels to this Product
  Can edit: no separate edit form/modal for pivot fields — see the
    "Canonical pivot-sync rule" below for why
  Can delete: no — "delete" here would delete the Channel record itself.
    Only Detach/DetachBulk (which remove the pivot row) are offered.
```

Register it in `ProductResource::getRelations()`:
```php
public static function getRelations(): array
{
    return [
        // ...any relation managers from Media/Relations blueprints...
        ChannelsRelationManager::class,
    ];
}
```

### Canonical pivot-sync rule (read before implementing)

`is_published` and `published_at` are kept consistent by **one rule, written
once, enforced at exactly two write sites** — the AttachAction form (initial
attach) and the table's inline toggle (every change after that). There is
**no third site**: deliberately no "Edit" action/modal is added for this
RelationManager's pivot fields, because a third independently-coded path is
exactly the drift risk this section exists to prevent — if an Edit modal's
save logic diverged even slightly from the rule below, `published_at` could
end up out of sync with `is_published` depending on which UI path a user
happened to use last. The rule itself:

> `published_at = $isPublished ? now() : null` — never set independently of
> `is_published`, in either direction.

**Site 1 — AttachAction (initial attach).** Per `pivot-tables.md`, use
`$action->getRecordSelect()` plus extra schema fields that save to the pivot
table automatically. The locked design's reactive pattern
(`Toggle::make('is_published')->live()->afterStateUpdated(fn ($state, Set $set) => $set('published_at', $state ? now() : null))`)
was checked against `filament/filament@4.x`'s docs
(`03-resources/07-managing-relationships.md`, "Attaching with pivot
attributes") and needs one correction: that doc's own example
(`TextInput::make('role')`) shows only fields **explicitly present in the
schema array** are saved to the pivot — `$set()` alone, with no backing
component for `published_at`, has no guaranteed field to dehydrate into. Add
an explicit (hidden) `published_at` component so the `$set()` value actually
persists:

```php
use Filament\Actions\AttachAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;

AttachAction::make()
    ->schema(fn (AttachAction $action): array => [
        $action->getRecordSelect(),
        Toggle::make('is_published')
            ->live()
            ->afterStateUpdated(fn (bool $state, Set $set) => $set('published_at', $state ? now() : null)),
        Hidden::make('published_at'),
    ])
```

No explicit `->recordSelectOptionsQuery()` is added for tenant scoping:
`Channel` uses `BelongsToCatalog`, whose global `CatalogScope` already
applies to every query built against the `Channel` model — including the
one `getRecordSelect()` builds internally — the same reasoning
`category.md`'s `parent_id` Select relies on for its own cross-tenant
exclusion. Per `multi-tenancy.md`, Filament validates the submitted record
against this same (scoped) query, so a foreign-catalog Channel ID submitted
by a tampered request is rejected, not just hidden from the dropdown. The
Tests section below verifies this is actually true rather than assuming it.

**Site 2 — table's inline toggle (ongoing edits).** Per `pivot-tables.md`,
pivot columns in `withPivot()` behave like normal table columns. Use
`Filament\Tables\Columns\ToggleColumn` (not a read-only badge) so a catalog
manager can flip publication status without re-opening the attach modal —
the "Use `ToggleColumn`" choice over a badge-plus-attach-modal-only is
deliberate: a badge would force detach-then-reattach (losing the row's
`created_at`/other pivot metadata) just to toggle one boolean, which is worse
UX for no safety benefit, since `ToggleColumn`'s own `afterStateUpdated` hook
(confirmed against `tables/02-columns/07-toggle.md`'s "Lifecycle hooks"
section — it runs *after* the toggle's own state save) lets the
`published_at` half of the rule run in the same place:

```php
use Filament\Tables\Columns\ToggleColumn;
use Modules\Channels\Models\Channel;

ToggleColumn::make('is_published')
    ->afterStateUpdated(function (Channel $record, bool $state): void {
        $record->pivot->published_at = $state ? now() : null;
        $record->pivot->save();
    })
```

Per `tables/02-columns/07-toggle.md`'s Security note, `ToggleColumn` does
not check model policies by itself — acceptable here only because this
module's whole authorization stance is "all authenticated catalog members,"
the same as every other action in `ChannelResource` (see Authorization).

**Table columns**:
```
Column: channel.name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()->label('Channel')

Column: is_published
  Component: Filament\Tables\Columns\ToggleColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/toggle
  Config: (afterStateUpdated hook above)

Column: published_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->dateTime()->sortable()->placeholder('Not published')
```
`published_at` is read-only in this table — it is never independently
editable; it only ever changes as a side effect of the `is_published` toggle
(Site 1 or Site 2 above), per the canonical rule.

**Header/row actions**:
```
Action: Attach
  Component: Filament\Actions\AttachAction
  Docs: https://filamentphp.com/docs/4.x/resources/managing-relationships#attaching-and-detaching-records
  Location: table header
  Behavior: schema above

Action: Detach (row)
  Component: Filament\Actions\DetachAction
  Docs: https://filamentphp.com/docs/4.x/resources/managing-relationships#attaching-and-detaching-records
  Location: table row

Bulk Action: Detach
  Component: Filament\Actions\DetachBulkAction
  Docs: https://filamentphp.com/docs/4.x/resources/managing-relationships#attaching-and-detaching-records
```

No explicit `->inverseRelationship()` call on the RelationManager's table —
`Channel::products()` is literally named `products`, which is Filament's
default inverse-relationship-name guess for a `Product`-owned relation
manager, so the convention already matches (per `relationships.md`'s
"Non-Standard Relationship Names" section, that override is only needed when
it *doesn't*).

## Authorization

```
Resource: ChannelResource
  Authorization: All authenticated users (no Policy class — per
  blueprints/pim.md's existing convention, tenant isolation via CatalogScope
  + User::canAccessTenant() is the only guard; a user who can access the
  current catalog can fully manage its channels)

RelationManager: ChannelsRelationManager (on ProductResource)
  Authorization: All authenticated users — same convention; Attach/Detach
  actions only check the relation manager's isReadOnly() state by default
  (per filament/filament's managing-relationships docs), which is fine here
  since there is no narrower ability to enforce.
```

## Tests

```
ChannelResource:
  Validation (use dataset pattern):
    - name: required, max:255
    - slug: required, max:255, alpha_dash
    - slug must be unique within the same catalog (create two channels in
      the same catalog with the same slug — second fails; same slug in two
      DIFFERENT catalogs both succeed)

  Component Config:
    - name field's afterStateUpdated sets slug from name only when
      $operation is 'create'; editing name on an existing record does not
      change an already-saved slug

  Table:
    - products_count column reflects ->counts('products') accurately after
      attaching/detaching Products via the ProductResource RelationManager

  Deletion:
    - deleting a Channel with zero Products attached succeeds and the record
      is gone (hard delete, no soft-delete row remains)
    - deleting a Channel with one or more Products currently attached
      succeeds (not blocked): assert the Channel row is gone AND the
      corresponding channel_product pivot rows for that channel are gone,
      AND the attached Product record(s) themselves are untouched/still
      exist (only the assignment was removed, not the Product)

ProductResource > ChannelsRelationManager:
  Tenant isolation:
    - a Channel belonging to catalog B does not appear in the AttachAction's
      record select options when managing a Product in catalog A (assert
      against the Select's actual resolved options/search results, not just
      that the UI "looks" scoped)
    - submitting catalog B's Channel id directly as the AttachAction's
      record (bypassing the UI) is rejected/ignored — proves
      recordSelectOptionsQuery's CatalogScope-derived query is the real
      enforcement boundary, not merely a UI filter

  Pivot sync (is_published / published_at):
    - attaching a Product to a Channel via AttachAction with is_published
      toggled ON sets published_at to a fresh timestamp (assert the exact
      persisted pivot row, not just that the attach succeeded)
    - attaching with is_published left OFF (default) leaves published_at
      null
    - toggling an already-attached row's is_published OFF (inline
      ToggleColumn) clears published_at to null
    - toggling that same row's is_published back ON sets a NEW published_at
      (freeze time at two distinct instants via Carbon::setTestNow and
      assert the second timestamp differs from — and is later than — any
      value set at initial attach; don't just assert "not null")

  Detach:
    - detaching a Channel from a Product removes only that one
      channel_product pivot row; the Channel record and any OTHER Product's
      assignment to that same Channel are untouched
```
