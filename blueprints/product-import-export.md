# Product Import/Export Module

## Context

CSV/XLSX import and export for `Product` only, via Filament's native
`Importer`/`Exporter` action classes. Builds on `blueprints/pim.md` §1
(two-layer multi-tenancy — `Subdomain` → `Catalog`, `CatalogScope`,
`BelongsToCatalog`, "no Policy classes" authorization convention). Does
**not** touch §2 (Activity History) — `ProductImporter`/`ProductExporter`
are not Eloquent models and get no History tab; this convention only
applies per top-level Filament Resource.

This module was planned in parallel with five sibling blueprints (Products,
Media, Channels, Relations, and the already-built Categories). It takes
their locked shape as given, without redesigning it:

- `Modules\Products\Models\Product` — table `products`: `id, catalog_id,
  attribute_set_id (nullable FK), sku (unique per catalog), name, slug
  (unique per catalog), status (enum: draft|published), description
  (nullable text), price (nullable integer, cents), timestamps, deleted_at`.
  `ProductResource` at `Modules\Products\Filament\Resources\Products\ProductResource`.
- `Modules\Categories\Models\Category` (already built) — `belongsToMany`
  with `Product` via a plain `category_product` pivot (no pivot columns),
  method `categories()` on `Product`, `slug` unique per catalog.
- `Modules\Attributes\Models\AttributeSet` — `id, catalog_id, name, slug`.
  `Product belongsTo AttributeSet` via `attribute_set_id`, method
  `attributeSet()`.

**Assumption inherited unmodified from the Products blueprint** (stated here
only because this module's correctness depends on it, not because this
document is deciding it): `Product` uses
`App\Models\Concerns\BelongsToCatalog`, the same way `Category` does per
`blueprints/category.md` — i.e. `Product` carries `App\Models\Scopes\CatalogScope`
as a global scope keyed on `Filament::getTenant()`. Every tenant-isolation
claim below (in `resolveRecord()`, new-record creation, and the Exporter's
base query) rests on this. If the Products blueprint gives `Product` a
different tenancy mechanism, this document's Authorization section needs
re-grounding — flag that back rather than silently reconciling it.

### Dependency check (confirmed, not assumed)

Filament's own import/export actions depend transitively on `league/csv` and
`openspout/openspout` for CSV/XLSX reading and writing. Confirmed directly
against the root `composer.lock`:

```
$ grep -i -E "league/csv|openspout" composer.lock
"league/csv": "^9.27",
"openspout/openspout": "^4.23",
"name": "league/csv",
"name": "openspout/openspout",
"source": "https://github.com/openspout/openspout/tree/v4.32.0"
```

Both are already present (resolved: `league/csv` ^9.27, `openspout/openspout`
v4.32.0). **No new Composer dependency is needed for this module.**

## Commands

1. This module does **not** scaffold a new `app-modules/*` package — it adds
   two new files inside the Products module (scaffolded by the Products
   blueprint, in parallel) and extends that module's `ProductResource`. Do
   not run `php artisan make:module`.

2. **Do not** use `php artisan make:filament-importer` / `make:filament-exporter`
   for these classes, even though both commands exist in this app
   (confirmed via `php artisan list`: `make:filament-importer`,
   `make:filament-exporter`). Same reasoning `blueprints/category.md`
   already established for `make:filament-resource`: their default path
   resolution targets `app/`, not a module's `src/` root, and there's no
   evidence `internachi/modular` patches that. Author `ProductImporter` and
   `ProductExporter` directly at the paths/namespaces given below.

3. Filament's own supporting tables (`imports`, `exports`,
   `failed_import_rows`) are shipped as package migrations via
   `Filament\Actions\ActionsServiceProvider` (`->hasMigrations([...])`,
   confirmed by reading
   `vendor/filament/actions/src/ActionsServiceProvider.php`) — these
   auto-load; no `vendor:publish` step is needed. Just make sure
   `php artisan migrate` has been run (after the Products module's own
   `products`/`category_product` migrations exist) before using import or
   export in this panel.

4. After writing the files below: `vendor/bin/pint --dirty --format agent`.

## Models

No new model and no migration change. This module only adds two Filament
action-support classes and extends `ProductResource`'s table — it does not
own `Product`'s migration, factory, or model body.

## Resources

### New: `Modules\Products\Filament\Imports\ProductImporter`

```
Importer: ProductImporter
  Location: Modules\Products\Filament\Imports\ProductImporter
  Extends: Filament\Actions\Imports\Importer
  Model: Modules\Products\Models\Product
  Docs: https://filamentphp.com/docs/4.x/actions/import
```

**Required abstract methods** (verified against
`vendor/filament/actions/src/Imports/Importer.php`: `getColumns()` and
`getCompletedNotificationBody()` are both declared `abstract`, so both must
be implemented — the locked design given for this module did not call these
out explicitly):

```php
public static function getColumns(): array { /* see Columns below */ }

public static function getCompletedNotificationBody(Import $import): string
{
    $body = 'Your product import has completed and '
        .number_format($import->successful_rows).' '
        .str('row')->plural($import->successful_rows).' imported.';

    if ($failedRowsCount = $import->getFailedRowsCount()) {
        $body .= ' '.number_format($failedRowsCount).' '
            .str('row')->plural($failedRowsCount).' failed to import.';
    }

    return $body;
}
```
(`Import` here is `Filament\Actions\Imports\Models\Import`;
`getFailedRowsCount()` and the `total_rows`/`successful_rows` casts are
confirmed on that model. The exact wording is not mandated by the locked
design — write any clear completion message; this is a reasonable default.)

**Columns** (`Filament\Actions\Imports\ImportColumn`, docs:
https://filamentphp.com/docs/4.x/actions/import#importing-data):

```
Column: sku
  Config: ->requiredMapping()
  Rules: required, max:255

Column: name
  Config: ->requiredMapping()
  Rules: required, max:255

Column: slug
  Config: (no ->requiredMapping() — optional CSV column)
  Rules: nullable, max:255
  Config: ->fillRecordUsing(function (Product $record, ?string $state, array $data): void {
      $record->slug = filled($state) ? $state : Str::slug((string) $data['name']);
  })

Column: status
  Rules: ->rules(['required', 'in:draft,published'])

Column: price
  Config: ->numeric(decimalPlaces: 0)
  Rules: nullable, numeric

Column: description
  Rules: nullable

Column: category_slugs
  Rules: nullable
  Note: NOT a real column on Product. Resolved in afterSave() below, not
  filled onto the record directly — see "Customizing how a column is
  filled" vs. this being a virtual, relationship-only column. Give it no
  fillRecordUsing(); its mapped value is read from $this->data in
  afterSave() instead, so it is never assigned to an attribute.
```

**`fillRecordUsing()` closure — verified parameter injection.** The locked
design's closure for `slug` needs `$record`, `$state`, *and* the full mapped
row (`$data`, to read `$data['name']`). Confirmed by reading
`ImportColumn::resolveDefaultClosureDependencyForEvaluationByName()` in
`vendor/filament/actions/src/Imports/ImportColumn.php`:

```php
protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
{
    return match ($parameterName) {
        'data' => [$this->getImporter()->getData()],
        'importer' => [$this->getImporter()],
        'options' => [$this->getImporter()->getOptions()],
        'originalData' => [$this->getImporter()->getOriginalData()],
        'record' => [$this->getRecord()],
        default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
    };
}
```
`$data`, `$record`, and `$state` (passed explicitly by `fillRecord()`) are
all legitimate parameter names — write the closure exactly as above, with
exactly those three parameter names in that signature.

**`price` column — raw cents, not a decimal dollar amount (documented input
format, not a silent assumption).** `price` is stored as integer cents in
the DB. `->numeric(decimalPlaces: 0)` casts via `round($state, 0)`
(confirmed in `ImportColumn::castState()`/`getDecimalPlaces()` handling in
the same file) — it does **not** convert dollars to cents. A CSV cell of
`"19.99"` is rounded to the integer `20` and stored as **20 cents**, not
$19.99. The CSV column must be documented to its users as "whole cents" (an
operator uploading a $19.99 product must put `1999` in the cell). There is
no automatic dollars→cents conversion in this importer — note this
explicitly in the column's `->helperText('Whole cents, not dollars — e.g. 1999 for $19.99.')`.

**`resolveRecord()` — upsert by `sku` (verified base signature)**:

```php
use Modules\Products\Models\Product;

public function resolveRecord(): ?Product
{
    return Product::firstOrNew([
        'sku' => $this->data['sku'],
    ]);
}
```
Base signature confirmed as `public function resolveRecord(): ?Model` in
`vendor/filament/actions/src/Imports/Importer.php` line 176 — the override
narrows the return type to `?Product`. `firstOrNew()` never returns `null`,
so every row either updates an existing `Product` (matched by `sku`) or
creates a new one — this is the explicitly chosen upsert behavior (not
create-only or update-only), per the locked design. No
`RowImportFailedException` path is needed here (that pattern is only for an
update-only importer that must fail when no match exists — not this one).

**`afterSave()` hook — exact signature, corrected from the locked design's
assumption.** The locked design described this hook as
`afterSave(Product $record, array $data)`. That signature does **not**
exist on `Filament\Actions\Imports\Importer`. Verified against the official
lifecycle-hooks documentation and `Importer`'s `CanCallHooks`-based dispatch
(`vendor/filament/actions/src/Imports/Importer.php`): every lifecycle hook —
`beforeValidate()`, `afterValidate()`, `beforeFill()`, `afterFill()`,
`beforeSave()`, `beforeCreate()`, `beforeUpdate()`, `afterSave()`,
`afterCreate()`, `afterUpdate()` — is declared with **no parameters**. State
is read from `$this->data` (mapped+cast row), `$this->originalData`
(pre-cast CSV row), `$this->record` (current model), and `$this->options`
(import options) inside the hook body. Implement it as:

```php
protected function afterSave(): void
{
    $slugsCell = $this->data['category_slugs'] ?? null;

    if (blank($slugsCell)) {
        return;
    }

    $slugs = array_values(array_filter(array_map('trim', explode(',', (string) $slugsCell))));

    if ($slugs === []) {
        return;
    }

    $categories = Category::query()->whereIn('slug', $slugs)->get();

    $this->record->categories()->sync($categories->pluck('id'));
}
```
(`Category` here is `Modules\Categories\Models\Category`.)

**Unknown-slug behavior (decided, not a silent gap)**: `Category::whereIn(
'slug', $slugs)->get()` is implicitly scoped to the current catalog by
`Category`'s own `CatalogScope` (per `blueprints/category.md`). Slugs with
no match (wrong catalog, typo, or simply nonexistent) are silently dropped —
`sync()` only attaches what resolved. The row is **not** failed over an
unknown slug; only the valid slugs end up attached. This is the one
behavior explicitly specified by the locked design, and it is implemented
exactly as "sync only what resolved."

**Deliberate deferral (stated explicitly, not a silent gap)**: no
`attribute_set_id` column and no per-attribute dynamic value columns in
this importer. Bulk-importing `AttributeValue` rows would need dynamic
per-attribute CSV columns whose shape depends on which `AttributeSet` a row
uses — that can't be known generically by a single static column list. A
future, more advanced importer could add this once the plain `Product`
import is proven out. Every row imported through `ProductImporter` gets
`attribute_set_id = null` (the column is never touched, so it keeps
whatever `firstOrNew()`'s default/existing value is — `null` for new
records, unchanged for existing ones).

**Options Form: none.** No custom import-options toggle (e.g. a "notify"
flag) is needed for v1 — keep the action's options form empty. Do not add
an `options()` array or options-form fields to `ImportAction`.

**Tenant-aware queued import — verified mechanism, not assumed.**

The Importer runs inside a queued, chunked job (`Filament\Actions\Imports\Jobs\ImportCsv`).
Two independent mechanisms make `resolveRecord()`'s catalog scoping and
new-`Product` catalog assignment work correctly inside that job, both
verified directly in the installed Filament source — not inferred:

1. **Tenant/panel context restoration.** Filament registers a queue-job
   middleware, `Filament\Queue\Middleware\RestoreImportTenant`
   (`vendor/filament/filament/src/Queue/Middleware/RestoreImportTenant.php`,
   wired up in `FilamentServiceProvider`), on every import job dispatched
   from a tenant-aware panel. Before each chunk runs, it does
   `Filament::setCurrentPanel($panel)` then `Filament::setTenant($tenant)`
   (after re-verifying the tenant still exists and
   `$user->canAccessTenant($tenant)` still holds — re-checked per chunk,
   not trusted from when the import started), and restores the previous
   panel/tenant/auth state in a `finally` block afterward. This is also
   confirmed by the official docs ("Importing tenant-aware records":
   "Filament restores that panel and tenant for each import chunk...The
   restored context covers row processing and importer lifecycle hooks").
   Because `App\Models\Scopes\CatalogScope` reads `Filament::getTenant()`,
   this means `Product::firstOrNew(['sku' => ...])` inside `resolveRecord()`
   is correctly scoped to the catalog the import was started from, for
   every chunk, including chunks processed later/async.

2. **New-record catalog assignment.** This is **not** something
   `ProductImporter` needs to do itself. Filament registers a generic
   `Product::creating()` listener for any tenant-aware Resource (via
   `Filament\Resources\Resource\Concerns\BelongsToTenant::observeTenancyModelCreation()`,
   confirmed in `vendor/filament/filament/src/Resources/Resource/Concerns/BelongsToTenant.php`)
   once `ProductResource` is registered in the panel. That listener checks
   `Filament::getCurrentPanel()` and `Filament::getTenant()` — both
   correctly restored per (1) above — and calls
   `$record->catalog()->associate($tenant)` before the insert. So a `new
   Product()` returned from `resolveRecord()` gets `catalog_id` set
   automatically on save, the same way a normal tenant-aware Resource
   `CreateRecord` page would, with zero code in this importer.

   This mechanism is keyed on `Product::class` having a tenant-aware
   Resource registered in the panel (`ProductResource`, assumed
   `$isScopedToTenant = true`, the default) — it is **not** automatic for
   every model, only for ones with a registered tenant-aware Resource. This
   is why the "models without resources aren't auto-scoped" caveat in
   Filament's own multi-tenancy docs does not apply here.

Both mechanisms only cover row processing and importer lifecycle hooks
inside the standard `ImportAction` flow — they do **not** cover custom job
middleware you might add, or batch-completion callbacks. This module adds
neither, so no further action is needed.

### New: `Modules\Products\Filament\Exports\ProductExporter`

```
Exporter: ProductExporter
  Location: Modules\Products\Filament\Exports\ProductExporter
  Extends: Filament\Actions\Exports\Exporter
  Model: Modules\Products\Models\Product
  Docs: https://filamentphp.com/docs/4.x/actions/export
```

**Required abstract methods** (same verification as the Importer —
`getColumns()` and `getCompletedNotificationBody()` are `abstract` on
`Filament\Actions\Exports\Exporter`, confirmed in
`vendor/filament/actions/src/Exports/Exporter.php`):

```php
public static function getColumns(): array { /* see Columns below */ }

public static function getCompletedNotificationBody(Export $export): string
{
    $body = 'Your product export has completed and '
        .number_format($export->successful_rows).' '
        .str('row')->plural($export->successful_rows).' exported.';

    if ($failedRowsCount = $export->getFailedRowsCount()) {
        $body .= ' '.number_format($failedRowsCount).' '
            .str('row')->plural($failedRowsCount).' failed to export.';
    }

    return $body;
}
```
(`Export` here is `Filament\Actions\Exports\Models\Export`.)

**Columns** (`Filament\Actions\Exports\ExportColumn`, docs:
https://filamentphp.com/docs/4.x/actions/export#exporting-data):

```
Column: sku

Column: name

Column: status
  Config: ->formatStateUsing(fn (string $state): string => ucfirst($state))

Column: price
  Config: ->formatStateUsing(fn (?int $state): string => $state === null ? '' : number_format($state / 100, 2))

Column: attributeSet.name
  Label: Attribute Set

Column: categories
  Label: Categories
  Config: ->state(fn (Product $record): string => $record->categories->pluck('name')->join(', '))

Column: created_at
  Config: ->formatStateUsing(fn (\Carbon\Carbon $state): string => $state->format('Y-m-d H:i'))
```

**Price formatting is deliberately asymmetric with the importer — stated
explicitly, not left as an unexplained inconsistency.** The importer's
`price` column expects raw whole cents (e.g. `1999` for $19.99); this
exporter's `price` column formats stored cents back into a decimal dollar
string (`1999` → `"19.99"`). These are not meant to round-trip byte-for-byte
through "export then re-import" — they serve different audiences: import is
a developer/ops-facing bulk-load format (raw, unambiguous integers, no
locale or currency-symbol parsing needed), while export is for a human
reading the file in a spreadsheet (a formatted decimal reads naturally;
nobody wants to mentally divide by 100). Re-importing an exported file
without reformatting `price` back to cents would therefore produce wrong
values — that's expected, not a defect, given the two formats serve
different purposes.

**Formats**: CSV and XLSX — `Filament\Actions\Exports\Enums\ExportFormat::Csv`
and `::Xlsx`. Verified directly in
`vendor/filament/actions/src/Exports/Enums/ExportFormat.php`:

```php
enum ExportFormat: string implements ExportFormatInterface
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    // ...
}
```
Both case names are exactly `Csv` and `Xlsx` (not `CSV`/`XLSX`), confirming
the locked design's casing is correct as written.

**Tenant isolation for exports — verified mechanism, different from
import.** There is **no** `RestoreExportTenant` queue middleware — searched
the whole `vendor/filament` tree for it; it does not exist, only
`RestoreImportTenant` does. Export tenant-safety works differently and was
confirmed by reading `vendor/filament/actions/src/Exports/Jobs/PrepareCsvExport.php`:
its constructor takes `protected string $query` — the base Eloquent query is
**built and serialized synchronously**, while still inside the current
request/action (i.e. while `Filament::getTenant()` is still correctly set
to the catalog the user is scoped to), using `anourvalar/eloquent-serialize`.
That already-scoped, already-serialized query string is what the queued
export job(s) deserialize and execute — they never need to re-resolve
tenant context, because the `WHERE catalog_id = ...` constraint from
`CatalogScope` (and Filament's own tenancy scope, registered the same way
as described for imports via `BelongsToTenant::registerTenancyModelGlobalScope()`)
is already baked into the serialized query by the time it reaches the
queue. A `ProductExporter` run from catalog A's panel context therefore
never includes catalog B's products, regardless of what tenant context (if
any) is active when the queued job actually executes.

This also matches Filament's documented export security note (per the
installed docs, "Per-record authorization" in `actions/export`): the export
system applies no per-record policy checks, only whatever the query already
excludes. Tenant isolation here comes entirely from the query being built
under the correct tenant scope, not from any authorization layer — which is
consistent with this app's "no Policy classes" convention: `CatalogScope` is
the enforcement, not an afterthought.

### Update Existing Resource: `ProductResource`

Per the update-plan convention (`vendor/filament/blueprint/resources/markdown/planning/overview.md`
"New vs Update Plans": no scaffold command, only the changes). This section
only adds actions to the table that the Products blueprint defines — it does
not redefine `ProductResource`'s form, columns, or pages.

**Add to table header actions**:

```
Action: Import products
  Component: Filament\Actions\ImportAction
  Docs: https://filamentphp.com/docs/4.x/actions/import
  Location: table header
  Visibility: always visible to authenticated catalog members
  Authorization: All authenticated users (see Authorization section)
  Config: ->importer(ProductImporter::class)

Action: Export products
  Component: Filament\Actions\ExportAction
  Docs: https://filamentphp.com/docs/4.x/actions/export
  Location: table header
  Visibility: always visible to authenticated catalog members
  Authorization: All authenticated users (see Authorization section)
  Config: ->exporter(ProductExporter::class)->formats([ExportFormat::Csv, ExportFormat::Xlsx])
```
(`ExportFormat` here is `Filament\Actions\Exports\Enums\ExportFormat`.)

**Add to the table's bulk actions group** (inside the existing
`BulkActionGroup`/`groupedBulkActions([...])` alongside any delete/restore
bulk actions the Products blueprint defines):

```
Action: Export selected products
  Component: Filament\Actions\ExportBulkAction
  Docs: https://filamentphp.com/docs/4.x/actions/export
  Location: bulk (in BulkActionGroup)
  Visibility: always visible to authenticated catalog members
  Authorization: All authenticated users (see Authorization section)
  Config: ->exporter(ProductExporter::class)
```
`ExportBulkAction` (confirmed to exist at
`vendor/filament/actions/src/ExportBulkAction.php`, namespace
`Filament\Actions\ExportBulkAction`) is the correct bulk-export mechanism —
it exports only the rows the user selected in the table, as opposed to
`ExportAction`, which exports everything matching the current table query
(filters/search included, but not a manual row selection). Confirmed via
the installed testing docs ("Inspecting bulk selections" in
`docs/10-testing/08-testing-exports.md`): a bulk export's dispatched
`Export` carries a non-null `$records` array of the selected primary keys
alongside the base query, whereas a non-bulk export's `$records` is `null`.

## Authorization

```
Resource: ProductResource (Import/Export additions)
  Authorization: All authenticated users (no Policy class — per
  blueprints/pim.md's existing convention). Tenant isolation, not a
  Policy, is what prevents cross-catalog access for both actions.
```

Both the import and export system are documented by Filament to perform
**no per-record authorization** of their own (confirmed: "Per-record
authorization" sections in both `packages/actions/docs/11-import.md` and
`12-export.md` — import processes every row through `resolveRecord()`/
`fillRecord()`/`saveRecord()`, and export includes every record matching the
query, neither consulting a Laravel policy). That is consistent with this
app's "no Policy classes" convention — the enforcement here is tenant
scoping, not authorization, and it's real:

- **Import**: `resolveRecord()`'s `Product::firstOrNew(['sku' => ...])`
  lookup is scoped by `Product`'s `CatalogScope` (reads
  `Filament::getTenant()`), which is restored correctly per chunk inside the
  queued job by Filament's `RestoreImportTenant` middleware (see the
  Importer section above for the exact mechanism and source citation). New
  records get `catalog_id` set via Filament's own
  `BelongsToTenant::observeTenancyModelCreation()` listener on
  `Product::creating()`, the same mechanism any tenant-aware Resource's
  `CreateRecord` page relies on — not custom code in this module.
- **Export**: the base query for `ExportAction`/`ExportBulkAction` is built
  and already filtered by `CatalogScope` synchronously, before it's
  serialized and handed to the queued export job (see the Exporter section
  above). A catalog-B product can never appear in a catalog-A export because
  the `WHERE catalog_id = ...` constraint is already part of the serialized
  query, independent of whatever tenant context (if any) the queue worker
  happens to have when it actually runs the job.

Neither action needs `->authorize()`/`->visible()` overrides beyond what
`ProductResource`'s own page-level tenant membership check
(`User::canAccessTenant()`) already provides — any user who can open the
current catalog's `ProductResource` list page can run both actions, same
as every other action on that table.

## Tests

```
ProductImporter:
  Upsert behavior:
    - importing a row whose sku matches an existing Product updates that
      Product in place: assert the record's name/status/price/description
      changed to the imported values (not just "it worked" — check the
      specific fields), and assert the Product count did not increase
    - importing a row whose sku does not match any existing Product creates
      a new Product, scoped to the catalog the import was run under: assert
      a new row exists with that sku and catalog_id equal to the current
      tenant's id, and that the Product count increased by exactly one
    - importing under catalog A never updates or creates a Product actually
      belonging to catalog B, even if catalog B already has a Product with
      the same sku (two Products, same sku, different catalog_id, both
      survive the import untouched except the one in the importing catalog)

  slug derivation (fillRecordUsing):
    - row with an empty/unmapped slug cell: imported Product's slug equals
      Str::slug(name) from that same row
    - row with a non-empty slug cell: imported Product's slug equals the
      mapped value verbatim, even when it differs from Str::slug(name)

  category_slugs (afterSave hook):
    - row whose category_slugs cell contains one valid slug and one unknown
      slug: assert the Product ends up attached to exactly the one matching
      Category (by id/slug), not the unknown one, and assert no exception/
      row failure was recorded for that row
    - row whose category_slugs cell is blank: Product ends up attached to
      no categories (afterSave returns early, sync() never called with an
      empty set unnecessarily — but also no error)
    - a category_slugs entry that matches a Category slug in a DIFFERENT
      catalog is treated as unknown (Category::whereIn('slug', ...) is
      scoped by CatalogScope) — assert it is NOT attached

  price column (import direction only — see price export test below for
  the separate, non-symmetric export assertion):
    - importing price = "1999" stores Product.price as the integer 1999
      (raw cents in, integer cents stored — not divided or multiplied)
    - importing price = "19.99" (a decimal, as if someone mistakenly
      entered dollars) stores Product.price as 20 (round(19.99, 0) per
      ImportColumn::numeric(decimalPlaces: 0) — NOT 1999 and NOT 19) —
      this test exists specifically to prove there is no automatic
      dollars-to-cents conversion, matching the documented column
      expectation
    - importing a blank price cell leaves Product.price null

  Deferred attribute_set_id:
    - every Product created through this importer has attribute_set_id ===
      null, regardless of any other column values in the row (proves the
      stated deferral, not an accidental omission)

  Validation (use dataset pattern):
    - sku: required, max:255
    - name: required, max:255
    - status: required, in:draft,published (a status value outside
      draft/published fails validation and the row is not imported)

  Required mapping (via ImportAction::fake(), not the importer's test()
  helper — requiredMapping() is form-level, not row-level):
    - submitting the import form with columnMap.sku left unmapped fails
      with a form error on columnMap.sku, and nothing is dispatched
      (ImportAction::fake()->assertNothingDispatched())
    - the same for columnMap.name
    - submitting with columnMap.slug and columnMap.category_slugs left
      unmapped succeeds (neither has ->requiredMapping())

ProductExporter:
  Column formatting:
    - status column: a Product with status 'draft' exports as "Draft" (not
      "draft") — exercised via ProductExporter::test()->export($product)
    - price column (export direction only — independently asserted from
      the import-side cents test above, NOT assumed to be its inverse):
      a Product with price = 1999 (integer cents) exports the price column
      as the string "19.99"; a Product with price = null exports "" (empty
      string, not "0.00" or "null")
    - attributeSet.name column: a Product with an attribute set exports
      that set's name; a Product with attribute_set_id = null exports an
      empty value for that column (no error)
    - categories column: a Product attached to two categories exports
      their names joined with ", ", in the order $record->categories
      returns them; a Product attached to zero categories exports ""
    - created_at column: exports formatted as "Y-m-d H:i", e.g. a Product
      created at 2026-01-15 09:30:00 exports as "2026-01-15 09:30"

  Tenant isolation (via ExportAction::fake(), inspecting the dispatched
  query — see "Inspecting the query and submitted configuration" pattern):
    - starting an export while scoped to catalog A, where catalog B also
      has Products, dispatches a query whose resolved ids/rows are only
      catalog A's Products — assert the exact set of included Product ids,
      not just a count, and explicitly assert catalog B's Product id is
      NOT in that set

  Formats (both tested independently — do not assume one proves the other):
    - triggering the export action's CSV format and letting a queued
      export job run to completion produces a downloadable CSV export
      file for that Export record
    - triggering the export action's XLSX format and letting a queued
      export job run to completion produces a downloadable XLSX export
      file for that Export record (separate test, same Product fixture)

  Bulk vs. header export (ExportBulkAction::fake() vs ExportAction::fake()):
    - selecting 2 of 3 Products in the table and calling the bulk export
      action dispatches an Export whose captured $records equals exactly
      those 2 selected ids (not all 3) — per the "Inspecting bulk
      selections" testing pattern, $records is null for a non-bulk export,
      so also assert a plain ExportAction::fake() export carries records
      === null for the same table state
```

### Test prerequisites (verified against installed testing docs, not
assumed)

All testing helpers referenced above are shipped by the already-installed
`filament/actions` package itself — no new test dependency is needed:

- `ProductImporter::test(...)->import([...])` /
  `->assertImported()`/`->assertHasErrors()`/`->getRecord()` — row-level
  importer testing, confirmed in `docs/10-testing/07-testing-imports.md`.
- `ImportAction::fake()` /
  `$imports->assertDispatched(ProductImporter::class, fn (...) => ...)` /
  `assertNothingDispatched()` — action-submission testing, same doc.
- `ProductExporter::test()->export($record)` — row-level exporter column
  value testing, confirmed in `docs/10-testing/08-testing-exports.md`.
- `ExportAction::fake()` / `ExportBulkAction::fake()` /
  `$exports->assertDispatched(ProductExporter::class, fn (Export $export,
  Builder $query, array $columnMap, array $options, array $formats, ?array
  $records) => ...)` — both fakes capture the same callback signature, per
  the same doc's "Inspecting the query and submitted configuration" and
  "Inspecting bulk selections" sections.
- Actually producing a downloadable file (the two Formats tests above)
  cannot be done through the fakes, since both fakes explicitly document
  that they "do not process rows, generate files, or complete exports."
  Those two tests must run unfaked: dispatch the real `ExportAction`/
  `ImportAction` via Livewire and let the queued jobs run — `Queue::fake()`
  is NOT appropriate here since the job must actually execute to produce a
  file. Confirmed this app's `phpunit.xml` already sets
  `QUEUE_CONNECTION=sync` (line 30), so queued jobs dispatched during a Pest
  test run inline automatically; no extra setup is needed for these two
  tests. Assert against the resulting `Export` model's state (e.g.
  `$export->successful_rows`) and use `Storage::fake()` on the configured
  export disk to assert a file was actually written for that export.

Run the narrowest test file/filter that covers each change, per this
project's Pest conventions (`php artisan test --compact --filter=...` or
`vendor/bin/pest --filter=...`), rerunning the specific test after each
edit to it.
