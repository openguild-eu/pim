# Attribute Set Sidebar Items — Append "Attributes" to the Label

## Context

This is **not** a new page, route, or model — it's a small, scoped follow-up
to `blueprints/product-attribute-dynamic-subnav.md` (already implemented:
each `AttributeSet` attached to a `Product` gets its own sub-navigation item
in the left sidebar of the Product's record pages).

**Current behavior** (`app-modules/products/src/Filament/Resources/Products/ProductResource.php:248-274`,
verified by reading the installed checkout):

```php
public static function getRecordSubNavigation(Page $page): array
{
    $record = $page->getRecord();

    $attributeSetItems = $record->attributeSets
        ->sortBy('name')
        ->map(fn (AttributeSet $attributeSet): NavigationItem => NavigationItem::make($attributeSet->name)
            ->icon(Heroicon::OutlinedRectangleGroup)
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

Each sub-navigation item's label (`NavigationItem::make($attributeSet->name)`)
is currently the bare `AttributeSet->name`, e.g. `"extra"` or `"Apparel"`
(real data in this checkout, confirmed via `SELECT id, name FROM
attribute_sets`: ids 1/2 are `"extra"` and `"Apparel"`).

**Decision (user request)**: each sidebar item's label must read
**"{Title-Cased Name} Attributes"** instead of the bare name — e.g.
`"Apparel Attributes"`, `"Extra Attributes"`. The attribute set's own
`name` column is stored with inconsistent casing (`"extra"` vs
`"Apparel"`), so the name portion must be title-cased before appending
`" Attributes"`.

**Scope — label only.** Do **not** change:
- `ManageProductAttributes::getTitle()` / `getBreadcrumb()`
  (`app-modules/products/src/Filament/Resources/Products/Pages/ManageProductAttributes.php:105-113`)
  — these must keep returning the bare `$this->getAttributeSet()->name`
  (e.g. `'Clothing'`), per the existing test `'titles the Attributes page
  with the assigned attribute set\'s own name'`
  (`app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php:582-592`).
  This document does not touch that test or that method.
- The form `Section::make($attributeSet->name)` title in the same page
  (line 66) — unaffected, not referenced from this document.
- `AttributeSet` model, its `name` column, or any other model/migration —
  this is a presentation-only change to one `NavigationItem` label.

No new Filament API is used. `NavigationItem::make(string | Htmlable
$label)` already accepts a plain string
(`vendor/filament/filament/src/Navigation/NavigationItem.php:56-60`) — only
the string passed to it changes.

---

## Resources

### Update Resource: `ProductResource`

**File**: `app-modules/products/src/Filament/Resources/Products/ProductResource.php`

**Imports**: none to add — `Illuminate\Support\Str` is already imported
(line 32 of the current file) and unused elsewhere in this specific method,
so it's free to use here.

**Modify** `getRecordSubNavigation()` (lines 248-274): change only the
`NavigationItem::make(...)` argument on line 254, from:

```php
->map(fn (AttributeSet $attributeSet): NavigationItem => NavigationItem::make($attributeSet->name)
```

to:

```php
->map(fn (AttributeSet $attributeSet): NavigationItem => NavigationItem::make(Str::title($attributeSet->name).' Attributes')
```

Every other line of the method (the `->sortBy('name')` call, `->icon(...)`,
`->url(...)`, `->isActiveWhen(...)`, and the surrounding
`$page->generateNavigationItems([...])` calls) stays exactly as-is — sorting
still happens on the raw `name` column, not the formatted label, and the
active-state check still compares the attribute set's key, not its label, so
neither is affected by this change.

**Rule**: the label transform is `Str::title($attributeSet->name).'
Attributes'`, applied in exactly one place (`getRecordSubNavigation()`).
There is no second place in the codebase that independently formats this
same label, so there is no second rule to keep in sync.

**Verified**: `Illuminate\Support\Str::title()` capitalizes the first letter
of each word and lowercases the rest of each word (via
`mb_convert_case($value, MB_CASE_TITLE)`) — confirmed by reading
`vendor/laravel/framework/src/Illuminate/Support/Str.php`. For the two
attribute sets currently in this database this produces:
- `"extra"` → `"Extra Attributes"`
- `"Apparel"` → `"Apparel Attributes"`

Docs: https://laravel.com/docs/strings#method-str-title

---

## Authorization

No change. This is a display-text-only change to an already-visible
navigation item; no new data is exposed and no access control is affected.

---

## Tests

**File**: `app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`

**Update this existing test** (lines 612-633) — its assertions currently
expect the bare attribute set names as sub-navigation labels, which is now
wrong since the label gains a title-cased name plus the `" Attributes"`
suffix:

Current:
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

Replace the final `expect()` block (everything else in the test is
unchanged) with:

```php
    expect($subNavigationLabels)->toContain('Color Set Attributes', 'Material Set Attributes')
        ->and($subNavigationLabels)->toHaveCount($baselineCount + 2);
});
```

**Add this new test** — a discriminating case proving the title-casing
normalization itself (not just the suffix), using the real inconsistent
casing seen in this database (`"extra"` lowercase):

```php
it('title-cases the attribute set name before appending Attributes to the sidebar label', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'extra']);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $product->attributeSets()->attach($attributeSet->id);

    $test = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
    $test->assertOk();

    $subNavigationLabels = collect(
        ProductResource::getRecordSubNavigation($test->instance())
    )->map(fn ($item) => $item->getLabel())->all();

    expect($subNavigationLabels)->toContain('Extra Attributes')
        ->and($subNavigationLabels)->not->toContain('extra Attributes', 'extra');
});
```

**Leave unchanged**:
- `'shows an Images sub-navigation tab on the product Edit page, alongside
  Related Products'` (lines 567-580) — asserts `'Images'` and `'Related
  Products'`, neither of which are attribute-set items; unaffected.
- `'titles the Attributes page with the assigned attribute set\'s own
  name'` (lines 582-592) — asserts `getTitle()`/`getBreadcrumb()` equal the
  bare name `'Clothing'`; this document deliberately does not touch
  `getTitle()`/`getBreadcrumb()`, so this test's expectations stay valid and
  must still pass unmodified after this change.
- Every other test in the file.

**Test prerequisites**: same stack already in use (Pest, Filament's
Livewire testing helpers, `AttributeSet::factory()`, `actingAsCatalogUser()`
helper already used throughout this file) — no new package or factory
state required.

Run after implementing:
```
vendor/bin/pest app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php
```
then `vendor/bin/pint --dirty --format agent`.

---

## Summary of flagged assumptions

1. **`Str::title()` is the correct normalization for inconsistent casing.**
   Confirmed against the two real rows in this database (`"extra"` →
   `"Extra"`, `"Apparel"` → `"Apparel"`), matching the user's examples
   ("apparel attributes", "extra attributes" → rendered as "Apparel
   Attributes" / "Extra Attributes"). If a future attribute set name
   contains an acronym or mixed-case brand name that `Str::title()` would
   mangle (e.g. `"USB-C"` → `"Usb-c"`), that's a pre-existing display
   limitation of `Str::title()` on arbitrary input, not something this
   document's scope addresses — no such name exists in current data.
2. **The suffix is a literal, non-translated `" Attributes"`** appended
   after the title-cased name, matching the user's own phrasing ("apparel
   attributes", "extra attributes"). No pluralization/localization handling
   was requested and none is added.
