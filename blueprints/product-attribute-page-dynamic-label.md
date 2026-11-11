# Attributes Sub-Navigation Entry — Label With the Assigned Set's Own Name

## Context

This is **not** a new page, route, or model — it's a small, scoped follow-up
to `blueprints/product-attribute-dynamic-subnav.md` (already implemented:
commit `eab5d05` on this branch added `ManageProductAttributes` as a
dynamic sub-navigation entry, shown only when the product's
`attribute_set_id` is filled, currently always labeled the static string
`'Attributes'`).

**Decision (confirmed with the user)**: the sub-navigation entry, the page
title, and the breadcrumb must all show the **actual assigned `AttributeSet`'s
`name`** (e.g. "Clothing") instead of the generic literal "Attributes". The
page/route/visibility-gating mechanism itself is unchanged — a product still
has exactly one `AttributeSet` (`belongsTo`), the sub-nav item is still
hidden entirely when none is assigned (that logic lives in
`ProductResource::getRecordSubNavigation()` and is untouched by this
document), and clicking the item still opens the same
`ManageProductAttributes` page that edits that set's attribute values. Only
the **label/title/breadcrumb text** changes, driven by whichever set is
currently assigned.

**Verified Filament mechanics** (filament/filament@4.16.0, confirmed by
reading installed vendor source in this checkout):
- `getNavigationLabel()` (used to build the sidebar label) is a **static**
  method with no parameters
  (`vendor/filament/filament/src/Resources/Pages/EditRecord.php:80`:
  `public static function getNavigationLabel(): string | Htmlable`) — it has
  no access to `$this->record`, so it cannot by itself read the assigned
  attribute set's name.
- However, `Filament\Resources\Pages\Page::getNavigationItems(array $urlParameters = []): array`
  (`vendor/filament/filament/src/Resources/Pages/Page.php:141`) — the method
  that actually builds the `NavigationItem` for the sidebar — **does**
  receive `$urlParameters`, and `Filament\Resources\Pages\Concerns\InteractsWithRecord::getSubNavigationParameters()`
  (`vendor/filament/filament/src/Resources/Pages/Concerns/InteractsWithRecord.php:116-120`)
  returns exactly `['record' => $this->getRecord()]`. This flows through
  `HasSubNavigation::generateNavigationItems()`
  (`vendor/filament/filament/src/Pages/Concerns/HasSubNavigation.php:170-206`,
  already used unmodified by `ProductResource::getRecordSubNavigation()`) as
  `$component::getNavigationItems($parameters)` for any resource page. So
  `ManageProductAttributes::getNavigationItems($urlParameters)` **can** read
  `$urlParameters['record']` and therefore the bound `Product`'s
  `attributeSet`. Overriding `getNavigationItems()` (not just
  `getNavigationLabel()`) on this one page, reproducing
  `Page::getNavigationItems()`'s default body exactly except for the label
  source, is the confirmed, correct extension point — there is no other
  Filament API for a per-record dynamic sub-navigation label.
- `getBreadcrumb(): string` (`EditRecord.php:74`) and
  `getTitle(): string | Htmlable` (`EditRecord.php:322`) are **instance**
  methods, so they already have direct access to `$this->record` — no
  special handling needed for these two, just a normal override.

**Scope**: `app-modules/products/src/Filament/Resources/Products/Pages/ManageProductAttributes.php`
only. No change to `ProductResource.php`'s `getPages()` or
`getRecordSubNavigation()` — the conditional-inclusion logic there (`if
(filled($page->getRecord()->attribute_set_id)) { $items[] =
ManageProductAttributes::class; }`) is correct as-is and untouched; this
document only changes what `ManageProductAttributes` itself reports as its
label/title/breadcrumb once included.

---

## Resources

### Update Page: `ManageProductAttributes`

**Imports** — add one:
```php
use Filament\Navigation\NavigationItem;
```
(`Illuminate\Contracts\Support\Htmlable` is only needed in a type
declaration already satisfied by a plain `string` return below — not
required as an import since this plan returns a literal string, not an
`Htmlable` instance.)

**Remove** the three static display properties — they're replaced by method
overrides below so the label/title/breadcrumb can depend on the record:
```php
protected static ?string $navigationLabel = 'Attributes';

protected static ?string $breadcrumb = 'Attributes';

protected static ?string $title = 'Attributes';
```

**Add** four method overrides (place them after the `form()` method, before
the existing `mutateFormDataBeforeFill()`):

```php
public static function getNavigationLabel(): string | Htmlable
{
    return 'Attributes';
}

public function getTitle(): string | Htmlable
{
    return $this->record->attributeSet?->name ?? 'Attributes';
}

public function getBreadcrumb(): string
{
    return $this->record->attributeSet?->name ?? 'Attributes';
}

public static function getNavigationItems(array $urlParameters = []): array
{
    $record = $urlParameters['record'] ?? null;
    $label = $record?->attributeSet?->name ?? static::getNavigationLabel();

    return [
        NavigationItem::make($label)
            ->key(static::class)
            ->group(static::getNavigationGroup())
            ->parentItem(static::getNavigationParentItem())
            ->icon(static::getNavigationIcon())
            ->activeIcon(static::getActiveNavigationIcon())
            ->activeWhen(fn (): bool => original_request()->routeIs(static::getNavigationItemActiveRoutePattern()))
            ->sort(static::getNavigationSort())
            ->badge(static::getNavigationBadge(), color: static::getNavigationBadgeColor())
            ->url(static::getNavigationUrl($urlParameters)),
    ];
}
```

Notes:
- `getNavigationLabel()` stays as a static fallback (`'Attributes'`) — used
  only by `getNavigationItems()`'s own fallback expression when
  `$urlParameters['record']` is somehow absent or has no `attributeSet`; in
  normal operation the sub-nav item is only ever included by
  `ProductResource::getRecordSubNavigation()` when `attribute_set_id` is
  filled, so this fallback should be effectively unreachable in the sidebar
  — it exists only so the method has a sane default rather than `null`.
- `getNavigationItems()`'s body is copied **verbatim** from
  `Filament\Resources\Pages\Page::getNavigationItems()`
  (`vendor/filament/filament/src/Resources/Pages/Page.php:141-155`) — every
  line is identical except the `NavigationItem::make(...)` argument, which
  changes from `static::getNavigationLabel()` to the record-derived `$label`
  computed above it. `original_request()` is called unqualified with no
  `use function` import, exactly as `Page.php` itself does (it's a global
  helper, resolved via PHP's namespace fallback — confirmed by reading how
  `Page.php` calls it, with no corresponding `use function` in that file).
- `getTitle()`/`getBreadcrumb()` use `$this->record` directly (already an
  instance method, same convention `EditProduct`/`ManageProductImages` use
  elsewhere in this resource) — no parameter-passing concern, unlike the
  static `getNavigationItems()` case above.
- The "no attribute set assigned" placeholder path (direct URL access,
  sub-nav item hidden) still falls back to the literal `'Attributes'` title/
  breadcrumb/label via the `?? 'Attributes'` in each method — unchanged
  behavior, just consistent across all three surfaces now instead of only
  being the fixed value everywhere.

No other change to this file — `form()`, `mutateFormDataBeforeFill()`,
`mutateFormDataBeforeSave()`, `afterSave()`, and the `PersistsAttributeValues`
trait usage are all unaffected.

---

## Authorization

No change. This document only changes display text (label/title/breadcrumb),
not access control, routing, or data persistence.

---

## Tests

All changes are to
`app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`.

**Replace this existing test** (added by the previous blueprint; its
assertion — that the sub-nav label is literally `'Attributes'` — is now
wrong, since the label is the assigned set's own name):

```php
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

with:

```php
it('labels the Attributes sub-navigation entry with the assigned attribute set\'s own name', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Clothing']);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    $test = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
    $test->assertOk();

    $subNavigationLabels = collect(
        ProductResource::getRecordSubNavigation($test->instance())
    )->map(fn ($item) => $item->getLabel())->all();

    expect($subNavigationLabels)->toContain('Clothing')
        ->and($subNavigationLabels)->not->toContain('Attributes');
});
```

**Add this new test** — proves the page's own title/breadcrumb match the
sidebar label (the "every attribute set gets its own [named] edit page"
requirement, beyond just the sub-nav text):

```php
it('titles the Attributes page with the assigned attribute set\'s own name', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Clothing']);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    $test = Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey()]);

    expect($test->instance()->getTitle())->toBe('Clothing')
        ->and($test->instance()->getBreadcrumb())->toBe('Clothing');
});
```

**Add this new test** — confirms the fallback stays sane on the existing
"no attribute set assigned" edge case (the direct-URL path, already covered
by the existing `'loads the Attributes page without error...'` smoke test —
this adds the title/breadcrumb assertion that test doesn't make):

```php
it('falls back to a generic title and breadcrumb on the Attributes page when no attribute set is assigned', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);

    $test = Livewire::test(ManageProductAttributes::class, ['record' => $product->getRouteKey()]);

    expect($test->instance()->getTitle())->toBe('Attributes')
        ->and($test->instance()->getBreadcrumb())->toBe('Attributes');
});
```

**Leave unchanged**: every other test in the file, including
`'hides the Attributes sub-navigation entry when no attribute set is
assigned'` (unaffected — that test only checks the item's absence, not its
label) and `'loads the Attributes page without error when no attribute set
is assigned'`.

**Test prerequisites**: same stack already in use — Pest, Filament's
Livewire testing helpers, no new package required.

Run after implementing:
```
vendor/bin/pest app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php
```
then the full module suite:
```
vendor/bin/pest app-modules/products/tests
```
then `vendor/bin/pint --dirty --format agent`.

---

## Summary of flagged assumptions

1. **Fallback label/title/breadcrumb stays the literal `'Attributes'`** when
   no attribute set is assigned (direct URL access only — the sub-nav item
   itself is still hidden in that state, unchanged from the previous
   document). If the user would rather this edge case showed something else
   (e.g. "No attribute set"), that's a one-line change to the three `??
   'Attributes'` fallbacks.
2. **`getNavigationItems()` override duplicates Filament's default method
   body** rather than being a one-line change, because the label is the only
   thing that needs to differ and `getNavigationLabel()` alone has no access
   to the record. This is the confirmed, correct extension point (see
   Context) — not a workaround.
