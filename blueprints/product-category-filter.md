# Blueprint: Product Category Filter

## Goal

Add a table filter to the product overview (`ListProducts`, backed by
`ProductResource::table()`) that narrows the list to products assigned to one
or more selected categories. This is a pure query filter on the existing
`categories` `BelongsToMany` relationship — no schema change, no new column,
no change to the existing `completeness` column (already implemented in
`blueprints/product-completeness.md` and shipped — not part of this plan).

Out of scope (explicitly, do not implement): hierarchical/descendant category
matching (i.e. selecting a parent category does **not** also match products
in its child categories — `Category::descendantIds()` exists for the tree UI
but this filter does flat, direct-assignment matching only, consistent with
the existing `categories` form field and the `doesntHave('categories')` query
in `app/Filament/Widgets/ProductCompletenessOverview.php`); any change to
`Category` or `Product` models; any new migration.

## Cross-Requirement Consistency

Not applicable — this plan adds one stateless, read-only table filter. There
is no multi-stage lifecycle (no hydrate → edit → validate → save path): the
filter only narrows a `SELECT` query based on the current request's filter
state, and that state is not persisted anywhere beyond the table's own
session/URL filter state (Filament's existing behavior, unchanged).

One scoping fact matters and is noted explicitly rather than treated as an
open question: `Modules\Categories\Models\Category` uses the `BelongsToCatalog`
trait (`app/Models/Concerns/BelongsToCatalog.php`), which registers
`App\Models\Scopes\CatalogScope` as a global scope. That scope filters any
query on a catalog-scoped model by `catalog_id = Filament::getTenant()->id`
whenever a Filament tenant is active. A relationship-backed `SelectFilter`
resolves its dropdown options by querying the related model
(`Category::query()`) through the normal Eloquent relationship — Eloquent
global scopes apply to that query exactly as they do to any other `Category`
query, with no special-casing needed. The filter's category options are
therefore automatically limited to the current catalog, the same way the
`categories` field in `ProductResource::form()` already is. Do not add any
manual `->where('catalog_id', ...)` to the filter's relationship query — that
would duplicate scoping already enforced by `CatalogScope`.

## Models

No model changes.

## Resources

### Update Resource: `Modules\Products\Filament\Resources\Products\ProductResource`

File: `app-modules/products/src/Filament/Resources/Products/ProductResource.php`

No scaffold command — this is an update to an existing resource.

#### Add: `Filter: categories`

Component: `Filament\Tables\Filters\SelectFilter`
Docs: https://filamentphp.com/docs/4.x/tables/filters/select

`Filament\Tables\Filters\SelectFilter` is already imported in this file (it's
used by the existing `status` filter) — no new import needed.

Location: in `ProductResource::table()`'s `->filters([...])` array
(currently `[TrashedFilter::make(), SelectFilter::make('status')->options(ProductStatus::class)]`,
around line 178-182). Add as the third entry, after the `status` filter:

```php
SelectFilter::make('categories')
    ->relationship(name: 'categories', titleAttribute: 'name', hasEmptyOption: true)
    ->multiple()
    ->searchable()
    ->preload()
    ->emptyRelationshipOptionLabel('No category'),
```

Config notes:

- `->relationship('categories', 'name')` matches the house convention already
  used for this exact relationship in this file's `form()` method (the
  `categories` `Select` field, lines ~106-110) — same relation name, same
  title attribute.
- `->multiple()`: when the user selects more than one category, the table
  shows products matching **any** of the selected categories (OR semantics,
  per Filament's documented multi-select-filter behavior) — not products that
  have all selected categories.
- `->searchable()->preload()`: matches the form field's own config; the
  category list is expected to be small enough to preload, consistent with
  the existing form field's choice.
- `hasEmptyOption: true` + `->emptyRelationshipOptionLabel('No category')`:
  adds an explicit "No category" option that matches products with zero
  categories assigned (`doesntHave('categories')`). This directly complements
  the existing "missing categories" metric already surfaced by
  `app/Filament/Widgets/ProductCompletenessOverview.php`, letting a user find
  and fix those same products from the list page. Without this option,
  Filament's default relationship-filter behavior simply excludes
  zero-category products from every selected-category result (they'd never
  be reachable via this filter at all).
- Do not add `->default(...)` — the filter should be unselected (showing all
  products) until the user explicitly applies it.

No changes to `->modifyQueryUsing(...)`, to any column, or to any other filter.

## Authorization

No new authorization rules. This filter only narrows the set of rows already
visible to the user under `ProductResource`'s existing policy and the
existing `CatalogScope`; it exposes no new data and performs no write.

## Tests

Add to the existing
`app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`
(this file already imports `Category`, `Product`, `ListProducts`, and uses
`actingAsCatalogUser()` — reuse that exact pattern; it sits right after the
existing `it('filters by status', ...)` test, around line 245).

```php
it('filters products by a single selected category', function () {
    $catalog = actingAsCatalogUser();
    $categoryA = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Shoes']);
    $categoryB = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Hats']);
    $inA = Product::factory()->create(['catalog_id' => $catalog->id]);
    $inA->categories()->attach($categoryA->id);
    $inB = Product::factory()->create(['catalog_id' => $catalog->id]);
    $inB->categories()->attach($categoryB->id);

    Livewire::test(ListProducts::class)
        ->filterTable('categories', [$categoryA->id])
        ->assertCanSeeTableRecords([$inA])
        ->assertCanNotSeeTableRecords([$inB]);
});

it('shows products matching any of multiple selected categories', function () {
    $catalog = actingAsCatalogUser();
    $categoryA = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Shoes']);
    $categoryB = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Hats']);
    $categoryC = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Belts']);
    $inA = Product::factory()->create(['catalog_id' => $catalog->id]);
    $inA->categories()->attach($categoryA->id);
    $inB = Product::factory()->create(['catalog_id' => $catalog->id]);
    $inB->categories()->attach($categoryB->id);
    $inC = Product::factory()->create(['catalog_id' => $catalog->id]);
    $inC->categories()->attach($categoryC->id);

    Livewire::test(ListProducts::class)
        ->filterTable('categories', [$categoryA->id, $categoryB->id])
        ->assertCanSeeTableRecords([$inA, $inB])
        ->assertCanNotSeeTableRecords([$inC]);
});

it('the "No category" filter option shows only products with no category assigned', function () {
    $catalog = actingAsCatalogUser();
    $category = Category::factory()->create(['catalog_id' => $catalog->id]);
    $withCategory = Product::factory()->create(['catalog_id' => $catalog->id]);
    $withCategory->categories()->attach($category->id);
    $withoutCategory = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ListProducts::class)
        ->filterTable('categories', ['__empty'])
        ->assertCanSeeTableRecords([$withoutCategory])
        ->assertCanNotSeeTableRecords([$withCategory]);
});
```

Note on the third test: confirmed against the installed Filament version's
source (`vendor/filament/tables/src/Filters/SelectFilter.php`) — the "empty
relationship" option's sentinel value is the literal string `'__empty'`
(`SelectFilter::EMPTY_RELATIONSHIP_OPTION_KEY`), not an empty string. Use
`'__empty'` exactly as shown; do not use `''`.

### Test prerequisites

No new test dependencies. `Livewire::test(ListProducts::class)->filterTable(...)`
is the project's existing Filament table-testing helper (already used by the
neighboring `it('filters by status', ...)` and `it('filters by trashed products', ...)`
tests in the same file) — reuse it exactly as those tests do.

### Commands

None — no artisan scaffold command needed; this plan only edits an existing
resource file and an existing test file.

Run after implementing, before reporting back:

```
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=ProductResourceTest
```
