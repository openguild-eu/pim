# Relations Module

## Context

Related / cross-sell / up-sell products for the PIM. Builds on the
foundations in `blueprints/pim.md` §1 (two-layer multi-tenancy) and §2
(Activity History convention), and follows the format and depth of
`blueprints/category.md`, the most recently finished sibling module.

**Scope**: one new pivot model, `ProductRelation` (table `product_relations`),
carrying `type` and `position` beyond the two foreign keys, in a **new**
module `app-modules/relations`. No new top-level Filament Resource — the
feature surfaces entirely through three new sub-navigation tabs on the
existing `ProductResource` (owned by the sibling Products module): "Related
Products", "Cross-sells", "Up-sells".

**Sibling blueprints, written in parallel, that this document assumes exist**:
`Modules\Products\Models\Product` (table `products`: `id, catalog_id,
attribute_set_id, sku, name, slug, status, description, price, timestamps,
deleted_at`) and `Modules\Products\Filament\Resources\Products\ProductResource`
(already has Edit + History sub-navigation tabs via `getRecordSubNavigation()`).
Those files are **not** authored here — the "Update Existing Model: Product"
and "Update Existing Resource: ProductResource" sections below are patches to
be applied, by whoever implements this blueprint, to the Products module's
own files, in the build order: **Attributes → Products → Media → Channels →
Relations (this document) → Import/Export**. Media, Channels, and
Import/Export have their own "Update Existing Model: Product" patches too;
apply all of them, this one being only the Relations-authored piece.

### Corrections made to the locked design during verification

The orchestrator's locked design is followed exactly except for three points
that `search-docs` and direct inspection of the installed
`laravel/framework` and `filament/filament` source (both vendored at
`vendor/laravel/framework`, `vendor/filament/*`) showed would not work as
literally given. Each is called out again inline where it matters, but
summarized here:

1. **`->using(ProductRelation::class)` is required, not optional.**
   `Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable::attach()`
   (`vendor/laravel/framework/.../BelongsToMany.php` / its `Concerns` trait)
   branches on `if ($this->using)`: only when a custom pivot class is
   registered does `attach()` build and `->save()` a real Eloquent model
   (`attachUsingCustomClass()` → `newPivot()->save()`), which is what fires
   model events (`creating`, etc). Without `->using()`, `attach()` — which is
   exactly what Filament's `AttachAction` calls internally
   (`vendor/filament/actions/src/AttachAction.php`: `$relationship->attach($record, Arr::only($data, $relationship->getPivotColumns()))`)
   — does a raw query-builder `insert()` that never touches the model at all.
   The locked design's position-auto-increment `creating()` hook (and the
   `catalog_id` default added below, which the locked design didn't specify a
   source for) would silently never run if the relationship were left as a
   bare `belongsToMany()`. All three relationship methods on `Product`
   therefore add `->using(ProductRelation::class)`.
2. **`wherePivot('type', ...)` only *filters*; it does not set the value on
   create.** Laravel's own docs for intermediate-table filtering state this
   explicitly: *"The `wherePivot` adds a where clause constraint to the
   query, but does not add the specified value when creating new models via
   the defined relationship. If you need to both query and create
   relationships with a particular pivot value, you may use the
   `withPivotValue` method."* Since none of the three new sub-navigation
   tabs expose a `type` field for the admin to fill in (the type is implied
   by which tab/relationship was used), using plain `wherePivot('type', ...)`
   as literally written in the locked design would attach new rows with
   `type` left `NULL`, violating the column's `required` constraint. Each
   relationship method uses `withPivotValue('type', ...)` instead (confirmed
   at `vendor/laravel/framework/.../BelongsToMany.php:526-543`: it stores the
   value for creation *and* calls `wherePivot()` for filtering — one method
   covers both).
3. **`wherePivot`/`withPivotValue` do not implicitly add to
   `getPivotColumns()`** (verified by reading `wherePivot()`'s and
   `withPivotValue()`'s bodies — only `withPivot()` appends to
   `$this->pivotColumns`). `withPivot(['type', 'position'])` must stay on the
   relationship in addition to `withPivotValue('type', ...)`, both because
   `AttachAction` passes `Arr::only($data, $relationship->getPivotColumns())`
   to `attach()`, and because `Filament\Tables\Concerns\CanReorderRecords`
   (`vendor/filament/tables/src/Concerns/CanReorderRecords.php:36-37`) only
   takes the "update the pivot table" branch when the reorder column is
   `in_array($orderColumn, $relationship->getPivotColumns())` — this is also
   the mechanism (see Resources §"Why no extra scoping for reordering" below)
   that makes `->reorderable('position')` work at all for a pivot column.

Everything else below (schema, position-increment logic, self-exclusion,
authorization, no-`LogsActivity`-on-the-pivot) is the locked design,
expanded to full blueprint format.

## Commands

1. Scaffold the module:
   ```
   php artisan make:module relations --no-interaction
   ```
   Then edit the generated `app-modules/relations/composer.json` to mirror
   `app-modules/users/composer.json` exactly in shape, with one addition
   (`ProductRelation` type-hints `Modules\Products\Models\Product`, so this
   module depends on the Products module, unlike `categories`, which
   depended on nothing):
   - package name `modules/relations`
   - PSR-4: `Modules\Relations\` => `src/`, `Modules\Relations\Tests\` =>
     `tests/`, `Modules\Relations\Database\Factories\` =>
     `database/factories/`, `Modules\Relations\Database\Seeders\` =>
     `database/seeders/`
   - `require`: add `"modules/products": "*"` (next to the implicit
     `filament/filament`/`laravel/framework` constraints already present in
     every module's composer.json per the `users` template)
   - `extra.laravel.providers`: `Modules\Relations\Providers\RelationsServiceProvider`

2. Add `"modules/relations": "*"` to the root `composer.json` `require` block
   (next to `"modules/products": "*"`, which the Products blueprint must have
   already added), then:
   ```
   composer update modules/relations --no-interaction
   ```

3. **Do not** use `make:filament-resource` / `make:model` / `make:filament-page`
   for this module's own model — their default path resolution targets
   `app/`, not this module's `src/` root. Author `ProductRelation`, its
   migration, and its factory directly at the listed paths and namespaces.
   The three new sub-navigation **pages**, however, are physically added to
   the **Products** module's existing `app-modules/products/src/Filament/Resources/Products/Pages/`
   directory (see "Update Existing Resource: ProductResource" below) — they
   are not part of `app-modules/relations` at all, since `ManageRelatedRecords`
   pages must live alongside the resource they extend.

4. Create `app-modules/relations/src/Providers/RelationsServiceProvider.php`,
   matching `Modules\Users\Providers\UsersServiceProvider` exactly (empty
   `register()`, `boot()` calls
   `$this->loadMigrationsFrom(__DIR__.'/../../database/migrations')`).

5. No new `AdminPanelProvider` resource-discovery call and no new
   `NavigationGroup` — this module contributes no top-level Resource.
   Nothing to change in `app/Providers/Filament/AdminPanelProvider.php`.

6. No change to `app/Filament/Resources/ActivityLogs/ActivityLogResource.php`
   — `ProductRelation` does not use `LogsActivity` (see "Activity History"
   below), so it is never added to `scopeEloquentQueryToTenant()`'s subject
   list.

7. After creating the migration (step below) and the Products blueprint's
   `products` migration have both run (`product_relations` has FK columns
   into `products` — this migration's timestamp must sort after Products'
   `create_products_table` migration; build order above guarantees this):
   ```
   php artisan migrate
   ```

8. `vendor/bin/pint --dirty --format agent`

## Models

### New Model

```
Model: ProductRelation
  Namespace: Modules\Relations\Models\ProductRelation
  Table: product_relations
  Attributes:
    - id: bigint, primary
    - catalog_id: bigint, foreign(catalogs.id), required
    - product_id: bigint, foreign(products.id), required
    - related_product_id: bigint, foreign(products.id), required
    - type: enum(related|cross_sell|up_sell), required
    - position: integer, required, default:0
    - created_at: timestamp
    - updated_at: timestamp
  Indexes:
    - unique: [product_id, related_product_id, type]
    - index: [related_product_id]
  Relationships:
    - belongsTo: Catalog via catalog_id (method `catalog()`)
    - belongsTo: Product via product_id (method `product()`)
    - belongsTo: Product via related_product_id (method `relatedProduct()`)
  Traits:
    - App\Models\Concerns\BelongsToCatalog
  Factory: Modules\Relations\Database\Factories\ProductRelationFactory
```

No `SoftDeletes` — detaching a related/cross-sell/up-sell link is a hard
`DetachAction` delete of the pivot row, not a soft-deletable lifecycle event
(same reasoning Attributes' sibling blueprint gives for `AttributeValue`: this
is a line-item of the owning `Product`, not an independently retained
record).

Migration FK modifiers: `catalog_id` — `constrained('catalogs')` (no cascade
specified, matching Category's existing precedent — Catalog deletion isn't a
defined feature yet). `product_id` / `related_product_id` — both
`constrained('products')` with **no** `->cascadeOnDelete()` and no
`->nullOnDelete()` (default `RESTRICT`). This is a deliberate, currently
unresolved decision carried over from the same gap Category left open for
`catalog_id`: nothing in the Products blueprint yet defines what happens to
dependent rows when a `Product` is force-deleted. Left as default `RESTRICT`
means a product cannot be force-deleted while it is still referenced (as
either `product_id` or `related_product_id`) by any `ProductRelation` row.
Flag this for the Products blueprint / orchestrator to resolve when
product-deletion semantics are scoped; nothing here should be read as having
decided cascade-delete is wrong, only that it wasn't specified and shouldn't
be silently assumed.

### Model body (exact — copy as-is)

```php
<?php

declare(strict_types=1);

namespace Modules\Relations\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Products\Models\Product;
use Modules\Relations\Database\Factories\ProductRelationFactory;

class ProductRelation extends Pivot
{
    use BelongsToCatalog;

    /** @use HasFactory<ProductRelationFactory> */
    use HasFactory;

    /**
     * Pivot defaults `$incrementing = false` (composite-key pivots). This
     * table has its own auto-incrementing `id`, so it must be turned back on
     * — otherwise Eloquent will not know how to persist the primary key.
     */
    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'type' => ProductRelationType::class,
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    protected static function booted(): void
    {
        static::creating(function (ProductRelation $productRelation): void {
            // Pivot rows are never created through a tenant-aware Filament
            // CreateRecord page (they're inserted by AttachAction calling
            // BelongsToMany::attach(), which only reaches this event because
            // the relationship is registered with ->using(self::class) — see
            // "Corrections made to the locked design" above). There is no
            // other point where catalog_id would get filled in.
            $productRelation->catalog_id ??= Filament::getTenant()?->getKey();

            // Same max(position)+1 pattern Category uses for its sibling
            // `position`, scoped to [product_id, type] instead of parent_id.
            $productRelation->position ??= (static::query()
                ->where('product_id', $productRelation->product_id)
                ->where('type', $productRelation->type)
                ->max('position') ?? -1) + 1;
        });
    }
}
```

Note what is deliberately **not** overridden from `Pivot`'s defaults:
`$guarded = []` is kept as-is (not replaced with a `$fillable` allowlist, as
`Category` does). This pivot is never the target of a public create form —
it is only ever populated internally via `attach()` — so the house
`$fillable` convention, which exists to bound what a Filament form can mass-
assign, doesn't apply the same way here.

### Enum: ProductRelationType

```
Enum: ProductRelationType
  Namespace: Modules\Relations\Models\ProductRelationType
  Implements: Filament\Support\Contracts\HasLabel
  Backed: string
  Cases:
    - Related: value "related", label "Related"
    - CrossSell: value "cross_sell", label "Cross-sell"
    - UpSell: value "up_sell", label "Up-sell"
```

Not currently rendered as a badge anywhere in the UI (each of the three
sub-navigation tabs is already filtered to one type, so showing a `type`
column on any of them would be redundant) — `HasLabel` is implemented anyway
per `models.md`'s "always implement HasLabel" convention, in case a future
admin-facing type picker is added. `HasColor`/`HasIcon` are not implemented,
since nothing displays it as a badge today.

### Update Existing Model: Product

```
Migration: (none — Product's own migration, owned by the Products blueprint,
  is unaffected; this is purely new relationship methods on the model)
Model: Modules\Products\Models\Product
  Add relationships:
    - belongsToMany: Product via product_relations (method `relatedProducts()`)
    - belongsToMany: Product via product_relations (method `crossSellProducts()`)
    - belongsToMany: Product via product_relations (method `upSellProducts()`)
```

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Relations\Models\ProductRelation;
use Modules\Relations\Models\ProductRelationType;

public function relatedProducts(): BelongsToMany
{
    return $this->belongsToMany(Product::class, 'product_relations', 'product_id', 'related_product_id')
        ->using(ProductRelation::class)
        ->withPivot(['type', 'position'])
        ->withPivotValue('type', ProductRelationType::Related->value)
        ->orderByPivot('position');
}

public function crossSellProducts(): BelongsToMany
{
    return $this->belongsToMany(Product::class, 'product_relations', 'product_id', 'related_product_id')
        ->using(ProductRelation::class)
        ->withPivot(['type', 'position'])
        ->withPivotValue('type', ProductRelationType::CrossSell->value)
        ->orderByPivot('position');
}

public function upSellProducts(): BelongsToMany
{
    return $this->belongsToMany(Product::class, 'product_relations', 'product_id', 'related_product_id')
        ->using(ProductRelation::class)
        ->withPivot(['type', 'position'])
        ->withPivotValue('type', ProductRelationType::UpSell->value)
        ->orderByPivot('position');
}
```

See "Corrections made to the locked design" above for why this differs from
the locked design's literal `wherePivot('type', 'related')` (no `->using()`,
no `withPivotValue`) snippet: as written there, `AttachAction` would insert
rows with `type = NULL` and skip the `creating()` position/`catalog_id`
logic entirely.

**Directional only — decided behavior, not an oversight.** Attaching product
A → B under `related` does not attach B → A. `relatedProducts()` (and the
other two) is defined purely in terms of `product_id` (owning side) and
`related_product_id` (related side); nothing mirrors a write onto the
inverse pair. If an admin wants the relationship to read as symmetric in the
UI, they attach both directions manually, once from A's tab and once from
B's tab. See the "directional only" test below — two separate assertions
(A→B appears, B→A does not) discriminate this from an implementation that
accidentally made it symmetric (e.g. by using a single unordered pair query).

**Build-order note**: Media, Channels, and Import/Export are three other
sibling blueprints running in parallel; per the locked design, Media and
Channels also have their own "Update Existing Model: Product" sections (not
authored here), and Import/Export touches nothing on `Product`'s model
directly. Whoever implements all six blueprints together should apply every
"Update Existing Model: Product" patch in the stated build order — Attributes
→ Products → Media → Channels → **Relations (this document)** → Import/Export
— since later patches may add relationships/casts whose correctness depends
on earlier ones already being in place. This document only defines the
Relations-authored piece (`relatedProducts()`, `crossSellProducts()`,
`upSellProducts()`).

## Resources

### Update Existing Resource: ProductResource

```
Resource: Modules\Products\Filament\Resources\Products\ProductResource
  Docs: https://filamentphp.com/docs/4.x/resources/managing-relationships
  No scaffold command — existing resource, only additions below.
```

No new top-level `ProductRelation` Resource. Add three new pages, at the
**same location** as `ProductResource`'s existing pages (e.g. its `EditProduct`
and History page), i.e. inside `app-modules/products/src/Filament/Resources/Products/Pages/`
— not inside `app-modules/relations`:

```
'related-products' => ManageRelatedProducts::route('/{record}/related-products')
'cross-sells' => ManageCrossSellProducts::route('/{record}/cross-sells')
'up-sells' => ManageUpSellProducts::route('/{record}/up-sells')
```

Add to `ProductResource::getRecordSubNavigation()` (append to the array
`products.md` defines, don't replace it). **Resolved** (confirmed against
the other five sibling blueprints during the consistency pass — Media adds
a form field/table column, not a page; Channels adds a `RelationManager`
tab via `getRelations()`, not a `getRecordSubNavigation()` entry; neither
touches this array), the final order is:

```php
public static function getRecordSubNavigation(Page $page): array
{
    return $page->generateNavigationItems([
        EditProduct::class,
        ManageRelatedProducts::class,
        ManageCrossSellProducts::class,
        ManageUpSellProducts::class,
        ManageProductActivities::class, // History — from the Products blueprint; keep last, same convention Category uses
    ]);
}
```
(`Imports: Filament\Resources\Pages\Page`)

The three pages are structurally identical, differing only in relationship
name, navigation label, and the `withPivotValue` type baked into that
relationship (so no extra type-filtering is needed on the page itself — the
relationship already only ever returns/attaches rows of its own type):

```
Page: ManageRelatedProducts
  Location: Modules\Products\Filament\Resources\Products\Pages\ManageRelatedProducts
  extends: Filament\Resources\Pages\ManageRelatedRecords
  Docs: https://filamentphp.com/docs/4.x/resources/managing-relationships#relation-pages
  protected static string $resource = ProductResource::class;
  protected static string $relationship = 'relatedProducts';
  protected static ?string $navigationLabel = 'Related Products';
  protected static ?string $breadcrumb = 'Related Products';
  protected static ?string $title = 'Related Products';

Page: ManageCrossSellProducts
  Location: Modules\Products\Filament\Resources\Products\Pages\ManageCrossSellProducts
  extends: Filament\Resources\Pages\ManageRelatedRecords
  protected static string $resource = ProductResource::class;
  protected static string $relationship = 'crossSellProducts';
  protected static ?string $navigationLabel = 'Cross-sells';
  protected static ?string $breadcrumb = 'Cross-sells';
  protected static ?string $title = 'Cross-sells';

Page: ManageUpSellProducts
  Location: Modules\Products\Filament\Resources\Products\Pages\ManageUpSellProducts
  extends: Filament\Resources\Pages\ManageRelatedRecords
  protected static string $resource = ProductResource::class;
  protected static string $relationship = 'upSellProducts';
  protected static ?string $navigationLabel = 'Up-sells';
  protected static ?string $breadcrumb = 'Up-sells';
  protected static ?string $title = 'Up-sells';
```

Each page's `table()` method (identical shape across all three; shown once
for `ManageRelatedProducts`, substitute the relationship/label for the other
two):

```php
<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Products\Filament\Resources\Products\ProductResource;

class ManageRelatedProducts extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'relatedProducts';

    protected static ?string $navigationLabel = 'Related Products';

    protected static ?string $breadcrumb = 'Related Products';

    protected static ?string $title = 'Related Products';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->reorderable('position')
            ->columns([
                TextColumn::make('sku')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('price')
                    ->money(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->recordSelectSearchColumns(['sku', 'name'])
                    ->recordSelectOptionsQuery(
                        fn (Builder $query): Builder => $query->whereKeyNot($this->getOwnerRecord()->getKey()),
                    ),
            ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
```

Docs: `https://filamentphp.com/docs/4.x/resources/managing-relationships`
(`Scoping the options to attach`, `Attaching and detaching records`,
`Listing with pivot attributes`).

**Self-exclusion** — `recordSelectOptionsQuery(fn (Builder $query) =>
$query->whereKeyNot($this->getOwnerRecord()->getKey()))`, verified against
the exact pattern in Filament's own docs
(`AttachAction::make()->recordSelectOptionsQuery(fn (Builder $query) =>
$query->whereBelongsTo(auth()->user()))`) and against
`vendor/filament/actions/src/AttachAction.php`, which passes the query built
by this callback through `Relation::noConstraints(...)` + `RelationshipJoiner`
before listing/searching options — so excluding the owner record's own key
here is the actual selection boundary, the same way Category's `parent_id`
Select's `modifyQueryUsing` was its real validation boundary (per
`blueprints/category.md`). `getOwnerRecord()` is confirmed on
`ManageRelatedRecords` (`vendor/filament/filament/src/Resources/Pages/ManageRelatedRecords.php:165`).

**Tenant isolation is not something this query needs to add.** `Product`
uses `App\Models\Concerns\BelongsToCatalog` (established by the Products
blueprint, mirroring `Category`), so `App\Models\Scopes\CatalogScope` already
applies to every `Product` query, including the one `recordSelectOptionsQuery`
receives — a Product from another catalog is excluded before this callback
even runs. Only the same-record exclusion needs to be added explicitly.

**Why `->reorderable('position')` needs no extra scoping, unlike the
attach-options query.** `position` is a pivot column
(`withPivot(['type', 'position'])` on the relationship), and
`Filament\Tables\Concerns\CanReorderRecords::reorderTable()`
(`vendor/filament/tables/src/Concerns/CanReorderRecords.php:34-52`) detects
that case (`$relationship instanceof BelongsToMany && in_array($orderColumn,
$relationship->getPivotColumns())`) and updates rows via
`$relationship->newPivotQuery()` — which is the relationship's *own* pivot
query, already carrying both the `product_id = <owner>` foreign-key
constraint and the `wherePivot('type', ...)` constraint added internally by
`withPivotValue()`. A reorder issued from the "Cross-sells" tab can only ever
touch `product_relations` rows where `product_id` is this product and
`type = 'cross_sell'`; it cannot reach the "Related Products" tab's rows for
the same product, or any row belonging to a different product, even though
no explicit scoping was written on the page itself. This is the one place in
this document where "already scoped by the relationship" is not an inference
— it was confirmed by reading `CanReorderRecords::reorderTable()`'s source
directly, since Filament's own docs don't spell out this interaction for
pivot columns.

**Pivot attribute display** — `status` and `price` are plain columns on the
related `Product`, not pivot columns, so they display without `withPivot()`
(per the "Listing with pivot attributes" doc section, only pivot-table
columns need to be declared there; attributes native to the related model
work as normal columns automatically).

**Reusing `Pages/ManageRelatedProducts.php` for the other two pages**: do not
copy-paste-and-forget this file. `ManageCrossSellProducts` and
`ManageUpSellProducts` are the same class body with
`$relationship`/`$navigationLabel`/`$breadcrumb`/`$title` changed to
`crossSellProducts`/`'Cross-sells'` and `upSellProducts`/`'Up-sells'`
respectively — the `table()` method does not change at all, since
self-exclusion and reorder-scoping both come from the relationship
definition on `Product`, not from anything written per-page.

## Authorization

```
Resource: ProductResource (the three new pages inherit this)
  Authorization: All authenticated users (no Policy class — per
  blueprints/pim.md's existing convention; tenant isolation via CatalogScope
  + User::canAccessTenant() is the only guard, same as Category)
```

## Activity History

None for `ProductRelation` — it does not use `LogsActivity`, by the same
reasoning the sibling Attributes blueprint gives for `AttributeValue`:
changes to a product's related/cross-sell/up-sell links are visible and
auditable through the owning `Product`'s own context (which tab shows which
products, right now), not through a separate change log. This is a
deliberate decision, not an omission — restated explicitly per
`blueprints/pim.md` §2's "one History tab per top-level Filament Resource
only; child/line-item models don't get their own tab" scope rule.

`Product`'s own existing History tab (from the Products blueprint) is
untouched by this document and continues to work exactly as that blueprint
defines it — attaching/detaching a related product does not appear in it,
and nothing here changes `ActivityLogResource::scopeEloquentQueryToTenant()`.

## Tests

```
ProductResource — Related/Cross-sell/Up-sell tabs:

  Authorization:
    - a user who is not a member of the current catalog gets a 404 on a
      direct /products/{id}/related-products URL for a product in that
      catalog (mirrors Category's existing authorization test shape)

  Self-exclusion (test all three tabs separately — same bug could be fixed
  in one tab and left broken in another, since the query is per-page):
    - on ManageRelatedProducts, the owning product's own key is absent from
      AttachAction's record-select options
    - on ManageCrossSellProducts, same assertion
    - on ManageUpSellProducts, same assertion

  Type independence (attaching under one type must not leak into another):
    - attaching product B to product A under `related` makes B appear in
      A's relatedProducts() but NOT in A's crossSellProducts() or
      upSellProducts() — assert all three collections by exact identity,
      not just that `related` has 1 record

  Directional only (two separate assertions — a wrong implementation that
  made the pair symmetric would pass a single "they're related" check but
  fail this):
    - after attaching A→B under `related`, A->relatedProducts() contains B
    - after the same attach, B->relatedProducts() does NOT contain A

  Position / cross-group independence (name this scenario explicitly, not
  just "position works" — a wrong implementation might scope position by
  product_id alone and ignore type):
    - attaching three products to product A under `related`, one at a time,
      assigns positions 0, 1, 2 in attach order
    - attaching a fourth, different product to the SAME product A under
      `cross_sell` right after is assigned position 0, not 3 — proving the
      max(position)+1 scope is [product_id, type], not just [product_id]

  Reordering isolation:
    - reordering ManageCrossSellProducts for product A (changing its
      cross_sell rows' position values) does not change the position values
      of product A's `related` rows, nor of any other product's rows of any
      type — assert the untouched rows' positions by exact value, not just
      "still sorted"

  Tenant isolation:
    - a Product belonging to a different catalog never appears in any of
      the three tabs' AttachAction record-select options, even via a
      crafted search term matching its sku/name (covered implicitly by
      CatalogScope on Product, but assert it explicitly rather than trusting
      the scope silently applies)

  Component Config:
    - each tab's table is ->reorderable('position')
    - each tab's AttachAction has recordSelectSearchColumns(['sku', 'name'])
    - each tab's table columns are sku, name, status (badge), price

  Detach:
    - DetachAction on one row removes only that product_relations row
      (matching this product_id + related_product_id + type triple), and
      leaves the same pair's row under a DIFFERENT type (if one exists)
      intact — discriminates a detach implemented as "delete all rows for
      this pair" from the correct "delete this exact (product, related,
      type) row"
```

Test prerequisites: this project uses Pest (`pestphp/pest` + the Laravel and
Livewire-testing plugins already present per `composer show --direct`, same
runner Category's tests use — `livewire(ManageRelatedProducts::class, [
'record' => $product])->assertCanSeeTableRecords(...)` etc., per
`testing.md`'s "RelationManager Testing" pattern, which applies identically
to `ManageRelatedRecords` pages since both implement
`Tables\Contracts\HasTable`). No new test dependency is needed beyond what
Category's own test suite already relies on.

## Open questions for the orchestrator

1. **FK delete behavior** on `product_id`/`related_product_id` is left as
   default `RESTRICT` (see Models section) because nothing in scope yet
   defines product-deletion semantics. If the Products blueprint gives
   `Product` `SoftDeletes` (consistent with `Category`'s pattern) this is
   moot for normal deletes and only matters for `forceDelete()` — flagging
   rather than guessing.
2. ~~Final tab ordering~~ — **resolved** during the cross-blueprint
   consistency pass: Media and Channels don't add `getRecordSubNavigation()`
   entries at all (Media is a form field/column, Channels is a
   `RelationManager` tab via `getRelations()`), so the only entries are
   Edit, the three Relations tabs, then History — exactly as shown above.
