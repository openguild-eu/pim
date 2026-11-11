# Icon for the Attribute-Set Sub-Navigation Entries

## Context

Small, scoped follow-up to `blueprints/product-multiple-attribute-sets.md`
(implemented at commit `639051a`). The manually-built `NavigationItem`s for
each attached `AttributeSet` in `ProductResource::getRecordSubNavigation()`
currently set no icon (flagged explicitly as assumption #1 in that
document). The user asked for an icon before these sidebar menu items.

**Icon choice**: `Filament\Support\Icons\Heroicon::OutlinedRectangleGroup` —
already used in this exact codebase for the standalone `AttributeSetResource`
(`app-modules/attributes/src/Filament/Resources/AttributeSets/AttributeSetResource.php:30`),
so this reuses the app's own existing visual vocabulary for "attribute set"
rather than introducing a new, unrelated icon. `Heroicon` is already
imported in `ProductResource.php` (used for the resource's own
`$navigationIcon = Heroicon::OutlinedCube`) — no new import needed.

## Resources

### Update Resource: `ProductResource`

In `getRecordSubNavigation()`, add one `->icon(...)` call to the existing
`NavigationItem::make(...)` chain:

```php
$attributeSetItems = $record->attributeSets
    ->sortBy('name')
    ->map(fn (AttributeSet $attributeSet): NavigationItem => NavigationItem::make($attributeSet->name)
        ->icon(Heroicon::OutlinedRectangleGroup)
        ->url(ManageProductAttributes::getUrl(['record' => $record, 'attributeSet' => $attributeSet]))
        ->isActiveWhen(fn (): bool => (string) original_request()->route('attributeSet') === (string) $attributeSet->getKey()))
    ->values()
    ->all();
```

No other change to this method or anywhere else in the file.

## Tests

Per `testing.md`'s "What Not to Test" (form/navigation layout rendering is
Filament's own concern), no new test is required for a pure icon addition —
nothing behavioral changes. Run the existing suite to confirm no
regression:

```
vendor/bin/pest app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php
```
then `vendor/bin/pint --dirty --format agent`.
