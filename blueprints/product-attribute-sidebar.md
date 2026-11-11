# Product Edit — Attribute Sets in a Left Sidebar (in-form Flex layout)

## Context

This is **not** a new module, model, page, or route. Per the `planning-filament`
skill's "New vs Update Plans" format
(`vendor/filament/blueprint/resources/markdown/planning/overview.md`), this
document is:

- **Update Resource: `Modules\Products\Filament\Resources\Products\ProductResource`**
  (`app-modules/products/src/Filament/Resources/Products/ProductResource.php`)

**Decision (confirmed with the user)**: the user was asked to choose between
(a) a left-hand resource sub-navigation page for attribute sets (the existing
`ManageProductImages`/`ManageRelatedProducts` idiom this resource already
uses) or (b) an in-page split layout where the attribute-set selector and its
dynamic attribute fields sit in a left column and the core product fields sit
in a right/main column, both part of **one** form with **one** save action.
The user chose **(b)**. This plan implements only (b).

**Verified Filament pattern** (filament/filament@4.16.0, confirmed via
`search-docs`, `packages/schemas/docs/02-layouts.md` "Flex component"): the
documented way to build a fixed-width sidebar next to a growing main column,
within a single schema, is `Filament\Schemas\Components\Flex::make([...])`
with `->grow(false)` called on the component that should stay fixed-width.
Quoting the docs example directly:

```php
Flex::make([
    Section::make([
        TextInput::make('title'),
        Textarea::make('content'),
    ]),
    Section::make([
        Toggle::make('is_published'),
        Toggle::make('is_featured'),
    ])->grow(false),
])->from('md')
```

"the first section will `grow()` to consume available horizontal space,
without affecting the amount of space needed to render the second section.
This creates a flexible width sidebar effect." Positioning left/right is
purely **array order** (Flex renders children left-to-right); growing vs.
fixed is purely the presence/absence of `->grow(false)` on that child. Since
the user wants the **attribute-set sidebar on the left**, it must be the
**first** element of the `Flex::make([...])` array, with `->grow(false)`
called on it; the product-details column is the **second** element, with no
`grow()` call (grows by default — confirmed in
`vendor/filament/support/src/Concerns/CanGrow.php:18`, `canGrow(bool $default
= true)`, and `Flex.php:105` calling `$schemaComponent->canGrow()` with no
argument, so the trait's own `true` default applies inside `Flex` unless a
child explicitly opts out with `->grow(false)`).

Flex "does not use Filament's grid system" (same docs page) — `columnSpan()`/
`columnSpanFull()` have no effect on a `Flex` child and must not be used on
either top-level column.

No resource in this codebase currently uses `Flex` — this is the first use of
this layout component here. `Filament\Schemas\Components\Group` (confirmed at
`vendor/filament/schemas/src/Components/Group.php`) is an unstyled wrapper
(no heading, no border/card chrome) used below for the right/main column so
its fields render exactly as plain, unboxed fields the way they do today —
only the left column gets a bordered `Section` (reusing the existing
"Attributes" heading).

**Scope**: restructure `ProductResource::form()` only. No new page, no new
route, no new sub-navigation entry, no change to `getPages()` or
`getRecordSubNavigation()`, no change to the save action, and **no change**
to `app-modules/products/src/Filament/Resources/Products/Pages/Concerns/PersistsAttributeValues.php`.
Because `ProductResource::form()` is shared by both `CreateProduct` and
`EditProduct` (neither page overrides `form()` today), this layout
automatically applies to both pages — see "Flagged assumptions" below.

**Verified against this checkout**: the `images` field has already been
relocated to a separate `ManageProductImages` sub-navigation page and is not
present in `ProductResource::form()` in this worktree (confirmed by reading
the file directly before writing this plan). This plan does not touch the
`images` field or `Pages/ManageProductImages.php`.

---

## Resources

### Update Resource: `ProductResource`

No scaffold command.

**Imports** — add two, keep all existing imports as-is (notably keep
`Filament\Schemas\Components\Section`, `Filament\Schemas\Components\Utilities\Get`,
`Filament\Schemas\Components\Utilities\Set` — all still used):

```php
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
```

**Replace the entire `form()` method body** (currently
`ProductResource.php:66-165`) with the following. This is a pure
restructuring — every field keeps its exact current name, validation,
`Config`, and the dynamic-attribute-building closure is copied verbatim, only
relocated:

```
Form:
  Columns: 1

  Flex:
    Component: Filament\Schemas\Components\Flex
    Docs: https://filamentphp.com/docs/4.x/schemas/layouts
    Config: ->from('md')

    Section: Attributes (fixed-width sidebar, left)
      Component: Filament\Schemas\Components\Section
      Docs: https://filamentphp.com/docs/4.x/schemas/sections
      Config: ->grow(false)
      Fields:
        Field: attribute_set_id
          Component: Filament\Forms\Components\Select
          Docs: https://filamentphp.com/docs/4.x/forms/select
          Config: ->relationship(name: 'attributeSet', titleAttribute: 'name'), ->label('Attribute set'), ->live(), ->preload(), ->searchable()
          Reactive: when changed, the Group below (dynamic attribute fields) rebuilds and its visibility is re-evaluated. Imports: Filament\Schemas\Components\Utilities\Get, Filament\Schemas\Components\Utilities\Set (Set is still needed elsewhere in the form, for the name→slug derivation below — keep the existing import).

        Group: dynamic attribute fields (unstyled, no heading/border)
          Component: Filament\Schemas\Components\Group
          Docs: https://filamentphp.com/docs/4.x/schemas/layouts
          Config: ->visible(fn (Get $get): bool => filled($get('attribute_set_id')))
          Reactive: visible only when attribute_set_id is filled; options/fields depend on the selected AttributeSet (same closure as today, unchanged)
          Schema (closure, copied verbatim from the current nested `Section::make('Attributes')->schema(...)` — identical logic, only the wrapping component changed from Section to Group and the visibility condition moved with it):
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

                        $field = match ($attribute->type->value) {
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

    Group: product details (unstyled, grows — no `grow()` call, right/main column)
      Component: Filament\Schemas\Components\Group
      Docs: https://filamentphp.com/docs/4.x/schemas/layouts
      Fields (unchanged from today — same order, same exact Config on each):
        Field: name
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/4.x/forms/text-input
          Validation: required, max:255
          Config: ->required(), ->maxLength(255), ->live(onBlur: true), ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void { if ($operation === 'create') { $set('slug', Str::slug((string) $state)); } })

        Field: sku
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/4.x/forms/text-input
          Validation: required, max:255, unique per catalog (scopedUnique)
          Config: ->required(), ->maxLength(255), ->scopedUnique(ignoreRecord: true), ->helperText('Unique stock-keeping unit code for this catalog.')

        Field: slug
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/4.x/forms/text-input
          Validation: required, max:255, alpha_dash, unique per catalog (scopedUnique)
          Config: ->required(), ->maxLength(255), ->alphaDash(), ->scopedUnique(ignoreRecord: true), ->helperText('Used in URLs. Lowercase letters, numbers, and dashes only.')

        Field: status
          Component: Filament\Forms\Components\Select
          Docs: https://filamentphp.com/docs/4.x/forms/select
          Validation: required, must be a valid ProductStatus enum value
          Config: ->required(), ->options(ProductStatus::class), ->default(ProductStatus::Draft)

        Field: price
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/4.x/forms/text-input
          Validation: nullable, numeric, min:0
          Config: ->numeric(), ->minValue(0), ->prefix('cents'), ->helperText('Stored as integer cents, e.g. 1999 = $19.99.')

        Field: description
          Component: Filament\Forms\Components\Textarea
          Docs: https://filamentphp.com/docs/4.x/forms/textarea
          Validation: nullable
          Config: ->rows(4), ->columnSpanFull() — kept for parity with today's code even though it is a no-op in a single-column Group; do not remove, do not add columns to this Group that would make it meaningful (not asked for).

        Field: categories
          Component: Filament\Forms\Components\Select
          Docs: https://filamentphp.com/docs/4.x/forms/select
          Validation: none beyond relationship existence
          Config: ->relationship(name: 'categories', titleAttribute: 'name'), ->multiple(), ->searchable(), ->preload()
```

Exact resulting PHP (`form()` method, replacing lines 66-165 as a whole):

```php
public static function form(Schema $schema): Schema
{
    return $schema
        ->columns(1)
        ->components([
            Flex::make([
                Section::make('Attributes')
                    ->grow(false)
                    ->schema([
                        Select::make('attribute_set_id')
                            ->relationship(name: 'attributeSet', titleAttribute: 'name')
                            ->label('Attribute set')
                            ->live()
                            ->preload()
                            ->searchable(),

                        Group::make(function (Get $get): array {
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

                                    $field = match ($attribute->type->value) {
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
                        })
                            ->visible(fn (Get $get): bool => filled($get('attribute_set_id'))),
                    ]),

                Group::make([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                            if ($operation === 'create') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    TextInput::make('sku')
                        ->required()
                        ->maxLength(255)
                        ->scopedUnique(ignoreRecord: true)
                        ->helperText('Unique stock-keeping unit code for this catalog.'),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->alphaDash()
                        ->scopedUnique(ignoreRecord: true)
                        ->helperText('Used in URLs. Lowercase letters, numbers, and dashes only.'),

                    Select::make('status')
                        ->required()
                        ->options(ProductStatus::class)
                        ->default(ProductStatus::Draft),

                    TextInput::make('price')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('cents')
                        ->helperText('Stored as integer cents, e.g. 1999 = $19.99.'),

                    Textarea::make('description')
                        ->rows(4)
                        ->columnSpanFull(),

                    Select::make('categories')
                        ->relationship(name: 'categories', titleAttribute: 'name')
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ]),
            ])->from('md'),
        ]);
}
```

**What changed vs. what didn't**:
- Changed: the wrapping structure only — `Flex` → `Section('Attributes')
  (grow(false))` containing `attribute_set_id` + a `Group` for the dynamic
  fields, and `Flex` → `Group` for the seven product-detail fields.
- Unchanged: every field's name, validation, and `Config` chain; the dynamic
  attribute-field-building closure's logic (type matching, `is_required`
  from the pivot, label); the `->live()` behavior on `attribute_set_id` and
  on `name`; the slug-derivation `afterStateUpdated` callback.
- The dynamic fields' wrapper changed from `Section::make('Attributes')
  ->columnSpanFull()->visible(...)->schema(...)` to `Group::make(...)
  ->visible(...)`, because the outer `Attributes` heading now belongs to the
  sidebar `Section` itself — nesting a second heading-less `Section` inside
  it would render a visually redundant nested card border; `Group` has no
  border/heading so the dynamic fields render as plain fields inside the one
  "Attributes" card, exactly as the static `attribute_set_id` select does
  today (today it is *not* inside any card at all — now both share the same
  card). `columnSpanFull()` is dropped on the dynamic-fields wrapper because
  it has no effect inside `Flex`/a non-multi-column `Section` and was already
  meaningless once nested one level deeper; dropping it changes nothing
  visually.

No change to `table()`, `getRelations()`, `getGloballySearchableAttributes()`,
`getRecordRouteBindingEloquentQuery()`, `getPages()`, or
`getRecordSubNavigation()`.

### No change to `CreateProduct` or `EditProduct` page classes

Neither overrides `form()` today; both continue to delegate to
`static::getResource()::form($schema)`, so this layout applies automatically
to both Create and Edit — see "Flagged assumptions" below for why this is
intentional and not something to special-case.

---

## Authorization

```
Resource: ProductResource (form() layout change)
  Authorization: No change from today. This is a pure visual/structural
  rearrangement of fields already present in the form; no field's
  authorization-relevant behavior (CatalogScope tenant scoping, which
  fields are required, what gets persisted via PersistsAttributeValues)
  changes. No new Policy, no new gate.
```

---

## Tests

This is a layout-only change — per `testing.md`'s "What Not to Test", form
layout rendering itself (e.g. that a `Flex`/`Section`/`Group` renders its
CSS/HTML a particular way) is Filament's own concern and must not get a new
test. However, the existing test suite already contains the discriminating
tests that would catch a wrong implementation of this relocation (broken
field-key resolution after nesting, broken `->live()` reactivity, broken
pivot-driven `required()`, broken attribute-value save/reload roundtrip).
**None of these need to change** — Filament resolves form components by
dot-path key via `getFlatComponents()` regardless of nesting depth, so
`fillForm(['attribute_set_id' => ...])`, `fillForm(['attribute_values' =>
[...]])`, `assertSchemaComponentExists('attribute_set_id', ...)`, and
`assertFormFieldExists('attribute_values.material', ...)` all keep working
unmodified. Run the **existing, unmodified** `ProductResourceTest.php` and
confirm every test still passes, with particular attention to these specific
`it(...)` tests (all currently in
`app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`),
since they are the ones that actually exercise the relocated structure:

- `'configures the attribute_set_id select as live so the attributes section reacts to changes'`
  — must still pass: `attribute_set_id` is now nested one level deeper
  (`Flex` → `Section('Attributes')`), `assertSchemaComponentExists` must
  still find it by key and see `isLive() === true`.
- `'required attribute fields reflect that attribute set\'s own pivot is_required value'`
  — must still pass against `CreateProduct`: the dynamic field's
  `required()` state, now built inside the `Group` instead of the old nested
  `Section`, must still reflect the pivot's `is_required`.
- `'switching attribute sets away and back preserves the original set\'s saved values and keeps both sets\' data'`
  — must still pass against `CreateProduct`/`EditProduct`: the full hydrate
  → change `attribute_set_id` → fill `attribute_values` → save → switch
  again → reload roundtrip must be unaffected by the new wrapper.
- `'silently skips an attribute_values code that does not belong to the current attribute set and still saves the product'`
  — must still pass against `EditProduct`.
- `'derives the slug from the name only on create'` and `'does not overwrite
  an existing slug when the name changes on edit'` — must still pass:
  `name`/`slug` are now inside the right-column `Group` instead of directly
  in the form's top-level `->components([...])`; the `Set`-based
  `afterStateUpdated` callback and `assertSchemaStateSet` key lookups are
  unaffected by that nesting.
- All `'product field validation'` dataset cases — must still pass: `sku`,
  `name`, `slug`, `status`, `price` are now inside the Group, validation
  rules are per-field and unaffected by wrapper nesting.
- `'persists category assignment on create'` / `'replaces category
  assignment on edit rather than merging it'` — must still pass: `categories`
  moved into the Group, relationship-save behavior is unaffected by layout
  nesting.
- All image-related tests targeting `ManageProductImages::class` and the
  `'shows an Images sub-navigation tab...'` test — unaffected by this
  change (different page/method entirely); confirm they still pass simply
  because they share the same test file/run.

If you add a test, add exactly one — confirming the one genuinely new,
architecturally significant fact this document introduces (that the
sidebar is the fixed-width column, not the growing one), since that's a
one-line config flip (`grow(false)` on the wrong side) that no existing test
would otherwise catch:

```php
it('keeps the Attributes sidebar fixed-width while the product details column grows', function () {
    actingAsCatalogUser();

    Livewire::test(CreateProduct::class)
        ->assertSchemaComponentExists(
            'Attributes',
            checkComponentUsing: fn (Filament\Schemas\Components\Section $section): bool => ! $section->canGrow(),
        );
});
```

(`assertSchemaComponentExists` resolves `Section`/`Group` components by their
`key()`, which for an unkeyed `Section` defaults to a slug of its heading —
confirmed at `vendor/filament/schemas/src/Components/Section.php:106-116`:
`Str::slug(...) . '::' . ($statePath ? "{$statePath}::section" : 'section')`
— if this exact key string doesn't resolve in practice when the implementing
agent runs it, use `Str::slug('Attributes') . '::section'` or locate the
component via `$test->instance()->getSchema('form')->getFlatComponents(withHidden:
true)` and assert on the found instance directly, the same technique already
used in the existing `'changes the primary image when media is reordered...'`
test in this file for locating a component by walking the flattened schema.)

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

1. **Create page gets the same split layout as Edit.** The user's request
   was specifically about the Product *edit* level, but `form()` is shared
   by `CreateProduct` and `EditProduct` with neither overriding it — splitting
   them would require duplicating the entire field list into a second
   method, which is a much larger, unrequested change. Applying the split
   uniformly is the minimal, cosmetic choice and is trivially reversible
   (override `CreateProduct::form()` later to render the old flat layout) if
   the user decides Create should look different. Flagged, not assumed away.
2. **Breakpoint: `->from('md')`.** Matches the only example in this
   codebase's own planning guideline (`schema-layouts.md`'s Flex format) and
   the official docs example. Not specified by the user; trivially changed
   to `sm`/`lg`/etc. if the sidebar should start stacking at a different
   screen width.
3. **The dynamic attribute fields' wrapper is an unstyled `Group`, not a
   nested `Section`.** Chosen to avoid a visually redundant nested card
   border inside the "Attributes" sidebar card. If the user wants a visual
   separation between the attribute-set picker and the fields generated from
   it (e.g. a divider or sub-heading), that's a follow-up styling request,
   not implemented here.
4. **No new Policy/gate.** Confirmed in the Authorization section — this is
   a pure layout change with no new fields and no new capability.
