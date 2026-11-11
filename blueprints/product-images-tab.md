# Product Edit — Images Tab (left sub-navigation page)

## Context

This is **not** a new module or model. Per the `planning-filament` skill's
"New vs Update Plans" format
(`vendor/filament/blueprint/resources/markdown/planning/overview.md`), this
document is:

- **Update Resource: `Modules\Products\Filament\Resources\Products\ProductResource`**
  (`app-modules/products/src/Filament/Resources/Products/ProductResource.php`)
- **New Page: `Modules\Products\Filament\Resources\Products\Pages\ManageProductImages`**

**Revision note**: an earlier version of this document specified wrapping
`ProductResource::form()` in a `Filament\Schemas\Components\Tabs` component
(an in-form tab). **That approach is superseded and must not be
implemented.** The user explicitly asked for the Images tab to behave like
the existing "Related Products" tab — i.e. a left-hand **resource
sub-navigation page** (`SubNavigationPosition::Start`, this resource's
default — confirmed unset/default in `ProductResource`, so no new
`subNavigationPosition()` call is needed), not a tab inside the single Edit
form. This document replaces the Tabs approach entirely with a dedicated
page, following the exact same convention this resource already uses for
`related-products`, `cross-sells`, `up-sells`, and `activities`.

**Verified Filament pattern** (filament/filament@4.16.0, confirmed via
`search-docs`, `docs/03-resources/04-editing-records.md` "Adding edit pages
to resource sub-navigation"): an additional page extending
`Filament\Resources\Pages\EditRecord`, pointed at the same resource, with its
own `form()` override, is the documented way to add a second "edit-style"
page to a resource and register it in `getRecordSubNavigation()` /
`getPages()` — exactly the mechanism this plan uses. `EditRecord::form()` is
a normal (non-static) instance method (confirmed in
`vendor/filament/filament/src/Resources/Pages/EditRecord.php:394-396`:
`public function form(Schema $schema): Schema { return
static::getResource()::form($schema); }`) specifically meant to be
overridden per-page — this plan overrides it to show only the `images`
field.

**Scope**: move the existing `SpatieMediaLibraryFileUpload::make('images')`
field (currently inline in `ProductResource::form()`, between `description`
and `categories`) out of the main Details form and into a new dedicated
`ManageProductImages` page, reachable from the Product Edit screen's
left-hand sub-navigation, alongside Related Products / Cross-sells / Up-sells
/ History. No model change, no migration, no new Composer dependency — the
media wiring (`Product::registerMediaCollections()` /
`registerMediaConversions()`, the `images` collection, the `thumb`
conversion, per `blueprints/media.md`) is untouched.

**Not covered / explicitly deferred**:
- The Table's leading `SpatieMediaLibraryImageColumn` (list page) is
  untouched.
- `CreateProduct` (the Create form) **loses the `images` field entirely** —
  see "Flagged behavior change" below. This matches how Related
  Products/Cross-sells/Up-sells/Activities already behave: all are
  `/{record}/...` routes that only exist once a Product has been saved, with
  no equivalent available on Create. Images now follows that same rule.

---

## Flagged behavior change (read before implementing)

Today, `CreateProduct` and `EditProduct` both render the `images` field
inline, so a user can attach images while *creating* a new product, before
it has been saved. After this change, **image upload is only possible after
the product exists** — the user must save the product first (Details tab),
then switch to the new "Images" sub-navigation page. This is the same
constraint every other sub-navigation page on this resource already has
(you cannot attach Related Products, Cross-sells, Up-sells, or view History
until the record exists either). Implementing agent: do not try to preserve
image upload on Create — that would require a different, non-matching UI
(e.g. a modal or deferred-upload flow) and was not asked for. If this
trade-off needs revisiting, that's a follow-up conversation with the user,
not a silent addition here.

---

## Resources

### Update Resource: `ProductResource`

No scaffold command. Two changes to the existing file:

**1. Remove the `images` field from `form()`.** Delete these lines
(currently at `ProductResource.php:109-115`, between `description` and
`categories`) from the `->components([...])` array — do not replace them
with anything:

```php
SpatieMediaLibraryFileUpload::make('images')
    ->collection('images')
    ->conversion('thumb')
    ->multiple()
    ->reorderable()
    ->image()
    ->columnSpanFull(),
```

Remove the now-unused `use Filament\Forms\Components\SpatieMediaLibraryFileUpload;`
import from `ProductResource.php` **only if** nothing else in the file still
references it after this change (it doesn't — the table's
`SpatieMediaLibraryImageColumn` is a different class/import and is untouched).

**2. Register the new page.** Add one import, one `getPages()` entry, and
one `getRecordSubNavigation()` entry:

```php
use Modules\Products\Filament\Resources\Products\Pages\ManageProductImages;
```

```
Form:
  (unchanged except for the removal above — Columns: 1, same fields/Section
  as today, minus `images`)

Pages (getPages()):
  'index' => ListProducts::route('/')
  'create' => CreateProduct::route('/create')
  'edit' => EditProduct::route('/{record}/edit')
  'images' => ManageProductImages::route('/{record}/images')   ← NEW, placed
    right after 'edit' (cosmetic ordering choice — flagged below)
  'related-products' => ManageRelatedProducts::route('/{record}/related-products')
  'cross-sells' => ManageCrossSellProducts::route('/{record}/cross-sells')
  'up-sells' => ManageUpSellProducts::route('/{record}/up-sells')
  'activities' => ManageProductActivities::route('/{record}/activities')

getRecordSubNavigation(Page $page):
  return $page->generateNavigationItems([
      EditProduct::class,
      ManageProductImages::class,   ← NEW, placed right after EditProduct,
        before ManageRelatedProducts (so the left sub-nav reads: Edit,
        Images, Related Products, Cross-sells, Up-sells, History)
      ManageRelatedProducts::class,
      ManageCrossSellProducts::class,
      ManageUpSellProducts::class,
      ManageProductActivities::class,
  ]);
```

No change to `getRelations()`, `table()`, `getGloballySearchableAttributes()`,
or `getRecordRouteBindingEloquentQuery()`.

### New Page: `ManageProductImages`

```
Location: Modules\Products\Filament\Resources\Products\Pages\ManageProductImages
extends: Filament\Resources\Pages\EditRecord
```

Exact file content (copy as-is):

```php
<?php

declare(strict_types=1);

namespace Modules\Products\Filament\Resources\Products\Pages;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Modules\Products\Filament\Resources\Products\ProductResource;

class ManageProductImages extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected static ?string $navigationLabel = 'Images';

    protected static ?string $breadcrumb = 'Images';

    protected static ?string $title = 'Images';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                SpatieMediaLibraryFileUpload::make('images')
                    ->collection('images')
                    ->conversion('thumb')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->columnSpanFull(),
            ]);
    }
}
```

Notes:
- `form()` is overridden here (not `ProductResource::form()`) precisely
  because this page needs a *different* field set than the main Edit page —
  this is the documented per-page override point (see Context section).
- No `getHeaderActions()` override: this page needs no Delete/Restore/
  ForceDelete actions (those remain exclusively on `EditProduct`, unchanged
  — duplicating them here would let a user delete the Product from the
  Images tab, which is not asked for and not how the sibling `Manage*`
  pages behave either; none of them expose delete actions).
- No `mutateFormDataBeforeFill()` / `mutateFormDataBeforeSave()` /
  `afterSave()` overrides needed: `images` is not a `products` table column
  and is never part of the form's dehydrated `$data` array —
  `SpatieMediaLibraryFileUpload` saves itself via the schema's own
  relationship-saving hook (the same mechanism that already makes this
  field work today inside the combined `EditProduct`/`CreateProduct` forms,
  with no custom save logic needed there either). `EditRecord`'s default
  `handleRecordUpdate()` calling `$record->update($data)` with an empty
  `$data` array (since `images` dehydrates to nothing meaningful for mass
  assignment) is harmless and identical to today's behavior.
- Route model binding, tenant scoping (`CatalogScope` via
  `ProductResource::getEloquentQuery()`), and the soft-deleted-record
  override (`getRecordRouteBindingEloquentQuery()`) all apply automatically
  to this page exactly as they do to `EditProduct`, because both share the
  same `$resource = ProductResource::class` and Filament's `EditRecord`
  resolves its record through the resource's route-binding query — no
  separate tenant check is written here (same reasoning
  `blueprints/media.md` already documents for why media has no independent
  tenant check).

### Update: `CreateProduct` and `EditProduct` pages

No code changes to either page class file. `EditProduct` no longer renders
an `images` field purely because `ProductResource::form()` no longer
includes it (the resource-level change above) — `EditProduct::form()` is
not overridden today and continues to delegate to
`static::getResource()::form($schema)` by default, so removing the field
from the resource's `form()` is sufficient; nothing in `EditProduct.php`
itself references `images`. Same for `CreateProduct.php`.

---

## Authorization

```
Resource: ProductResource (ManageProductImages page)
  Authorization: No change from today. A user who can already reach
  /default-catalog/products/{id}/edit (i.e. can access the current catalog,
  per blueprints/pim.md's CatalogScope + User::canAccessTenant() convention,
  no Policy class) can equally reach /default-catalog/products/{id}/images
  and upload/reorder/remove images — identical authorization boundary to
  today's inline field, just relocated. No new Policy, no new gate.
```

---

## Tests

The existing `app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`
file already has extensive image-specific coverage (read directly from the
file on 2026-11-11). Because the field moves to a different Livewire
component class, these existing tests must be **relocated to target
`ManageProductImages::class` instead of `EditProduct::class`**, not left
pointing at the old location (they would fail — `EditProduct` no longer has
an `images` field at all). This is a required test update, not an optional
cleanup.

**Relocate these existing `it(...)` tests** (currently calling
`Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])`
then `->fillForm(['images' => ...])`) to instead call
`Livewire::test(ManageProductImages::class, ['record' => $product->getRouteKey()])`.
Everything else about each test body (assertions, `Storage::fake('public')`,
factory setup) stays the same — only the Livewire target component changes:

- `'persists an uploaded image and synchronously generates the thumb conversion'`
- `'retrieves uploaded images in upload order with the first as primary'`
- `'changes the primary image when media is reordered via the Edit page form field and saved'`
  — update the docblock-style comment referring to "the Edit page form
  field" to say "the Images page form field" since it's accurate now.
- `'does not attach media to a product in a different catalog when the edit form is submitted directly'`
  — update the description/comment to say "the images page form" instead of
  "the edit form"; the `ModelNotFoundException` assertion and tenant-scoping
  mechanics are unchanged (see Authorization section).

Add `use Modules\Products\Filament\Resources\Products\Pages\ManageProductImages;`
to the test file's imports.

**Replace this existing test** (currently targets `CreateProduct::class`,
which no longer has an `images` field — this assertion would now fail):

```php
it('configures the images field as a reorderable, multiple, image-only SpatieMediaLibraryFileUpload bound to the images collection', function () {
    actingAsCatalogUser();

    Livewire::test(CreateProduct::class)
        ->assertSchemaComponentExists(
            'images',
            checkComponentUsing: fn (SpatieMediaLibraryFileUpload $component): bool => $component->getCollection() === 'images'
                && $component->isMultiple()
                && $component->isReorderable()
                && in_array('image/*', $component->getAcceptedFileTypes() ?? [], true),
        );
});
```

with the same assertion body, targeting `ManageProductImages::class` against
a real saved Product instead (Create has no record to resolve the page
against, since `ManageProductImages` requires `{record}`):

```php
it('configures the images field as a reorderable, multiple, image-only SpatieMediaLibraryFileUpload bound to the images collection', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ManageProductImages::class, ['record' => $product->getRouteKey()])
        ->assertSchemaComponentExists(
            'images',
            checkComponentUsing: fn (SpatieMediaLibraryFileUpload $component): bool => $component->getCollection() === 'images'
                && $component->isMultiple()
                && $component->isReorderable()
                && in_array('image/*', $component->getAcceptedFileTypes() ?? [], true),
        );
});
```

**Leave unchanged**: the table-column test
(`'configures the leading images table column as a SpatieMediaLibraryImageColumn...'`,
targeting `ListProducts::class`) — the table is untouched by this document.

**Add one new test** confirming the sub-navigation wiring itself (the one
genuinely new piece of behavior this document introduces):

```php
it('shows an Images sub-navigation tab on the product Edit page, alongside Related Products', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    $test = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);

    $test->assertOk();

    $subNavigationLabels = collect(
        ProductResource::getRecordSubNavigation($test->instance())
    )->map(fn ($item) => $item->getLabel())->all();

    expect($subNavigationLabels)->toContain('Images', 'Related Products');
});
```

(`Filament\Navigation\NavigationItem::getLabel()` — confirmed as the
existing accessor on the objects `generateNavigationItems()` returns, same
class Filament's own sub-navigation rendering uses internally.)

**No new test needed for "Images only reachable after save"** — this is
the same, already-unverified-by-this-suite behavior that Related Products/
Cross-sells/Up-sells/Activities already have (none of them have a
"not available on Create" regression test either); adding one here alone
would be inconsistent scope, not requested, and not a risk this change
introduces (it's inherent to every `{record}`-based sub-navigation page).

**Test prerequisites**: same stack already in use by this test file — Pest,
Filament's Livewire testing helpers (`Livewire::test()`,
`assertSchemaComponentExists`), no new package required.

Run after implementing:
```
vendor/bin/pest app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php
```
(or `php artisan test --filter=ProductResourceTest --compact`), then
`vendor/bin/pint --dirty --format agent`.

---

## Summary of flagged assumptions

1. **Page naming** — named the new page `ManageProductImages` to match this
   resource's existing sibling sub-navigation pages' naming convention
   (`ManageRelatedProducts`, `ManageCrossSellProducts`,
   `ManageUpSellProducts`, `ManageProductActivities`), even though it
   extends `EditRecord` rather than `ManageRelatedRecords` (there is no
   relationship to manage — only a media collection). Easily renamed if the
   user prefers e.g. `EditProductImages`.
2. **Sub-navigation order** — placed "Images" second (right after the main
   Edit/"Details" entry, before "Related Products"). Not specified by the
   user beyond "like Related Products" (i.e. a left sub-nav item); this
   ordering is cosmetic and trivially reordered in
   `getRecordSubNavigation()`/`getPages()` if a different position is
   wanted.
3. **Images removed from Create** — see "Flagged behavior change" above.
   This is the direct, necessary consequence of matching the Related-
   Products-style pattern (which is itself record-URL-based and therefore
   Edit-only); not an independent design choice made beyond what was asked.
