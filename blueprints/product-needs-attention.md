# Blueprint: Product "Needs Attention" Page

## Summary

A new, standalone Filament page listing every product that has at least one
data-quality issue (missing images, missing categories, missing required
attributes, or published while incomplete), so a user can see at a glance
which products need action and jump straight to fixing them. This is the
first non-resource, cross-product table page in this app.

Builds directly on the existing product-completeness feature
(`blueprints/product-completeness.md`, commit `6146cf5`). Does not change
that feature's behavior — see "Known limitation inherited" below, a decision
the user explicitly confirmed.

## Decisions already confirmed with the user (do not re-litigate)

1. **Required-attributes scope**: the new page's "missing required
   attributes" issue reuses `Product::missingRequiredAttributes()` exactly as
   it exists today — i.e. it only reads the product's single legacy
   `attributeSet()` relation, **not** the newer `attributeSets()`
   many-to-many. This keeps the new page consistent with the existing
   completeness badge column and dashboard widget, which have the same
   limitation. Do not fix this gap as part of this blueprint.
2. **"Published but incomplete" scope**: trigger this issue when the product
   is `is_published = true` on **any** attached channel (not necessarily
   all), AND it has at least one of the other three issues.

## Known limitation inherited (do not fix here)

`Product::missingRequiredAttributes()` / `completenessPercentage()`
(`app-modules/products/src/Models/Product.php:96-152`) only consider the
product's legacy single `attributeSet()` BelongsTo relation, not the newer
`attributeSets()` BelongsToMany (added in migration
`2026_11_11_120000_create_attribute_set_product_table.php`). A product whose
required attributes live only in a secondary attribute set will show as
"complete" on this new page too, exactly as it already does on the
`ProductResource` table badge and the `ProductCompletenessOverview` widget.

## Verified API notes (read before implementing)

- **Filament version installed**: `filament/filament` v4.16.2. All namespaces
  and URLs below are v4.x paths, confirmed via `search-docs` against this
  version.
- **`Filter::make(...)->schema([...])` is current, not deprecated.** Live
  v4.16.2 docs (`packages/tables/docs/03-filters/05-custom.md`,
  `03-filters/06-layout.md`) consistently use `->schema([...])` for custom
  filter form fields. Do not use `->form([...])` for this — despite what a
  generic planning note might say, `->schema()` is the verified-current
  method for `Filament\Tables\Filters\Filter` in this installed version.
- **Multi-value badge columns**: confirmed via
  `packages/tables/docs/02-columns/02-text.md` ("Listing multiple values",
  "Displaying as a badge") — when a `TextColumn`'s state is an array,
  `->badge()` renders one badge per item, and per-item `color()`/`icon()`
  closures receive each individual item as `$state`.
- **Custom page with a table**: confirmed via
  `docs/12-components/02-table.md`. A Livewire component (which a Filament
  `Page` is) needs to implement `Filament\Tables\Contracts\HasTable` and use
  `Filament\Tables\Concerns\InteractsWithTable`, define a `table(Table
  $table): Table` method that sets `->query(...)`, and render `{{ $this->table
  }}` in its Blade view. `Filament\Pages\Page` already provides
  `HasSchemas`/`InteractsWithSchemas` and `HasActions`/`InteractsWithActions`
  (used for the page's own header/content), so only `HasTable` +
  `InteractsWithTable` need to be added on top.
- **Stat widget links**: searched `search-docs` across the full
  `widgets/02-stats-overview.md` page (introduction, color, description,
  chart, extra attributes, placeholder, polling, lazy loading, styling
  sections all returned) — there is **no** `Stat::make(...)->url()` method in
  this version. The only documented way to make a stat clickable is
  `extraAttributes()` (confirmed, "Adding extra HTML attributes to a stat").
  Use that, not a fictitious `->url()` call.
- **Tenant scoping**: the panel uses `->tenant(Catalog::class,
  ownershipRelationship: 'catalog', slugAttribute: 'slug')`
  (`app/Providers/Filament/AdminPanelProvider.php:52`). Per Filament's
  multi-tenancy docs, Eloquent queries against `Product` are automatically
  scoped to the current tenant while a matching panel/tenant is active,
  because `Product` has a tenant-scoped resource (`ProductResource`) in this
  panel — this applies to queries from custom pages too, not just resource
  queries. No manual `catalog_id` filtering is needed anywhere in this
  blueprint. This matches the existing precedent in
  `ProductCompletenessOverview`, which already queries `Product::query()`
  with no manual tenant filter.
- **Performance precedent, carried forward, not introduced here**: computing
  "which products have required-attribute issues" has no SQL equivalent
  (the logic spans typed `attribute_values` columns + `is_required` pivot
  flags, decided in PHP via `AttributeValue::isFilled()`). The existing
  `ProductCompletenessOverview` widget already handles this by loading
  **all** tenant products into memory once per render
  (`Product::query()->with([...])->get()`) and computing in PHP. This
  blueprint uses the same approach for the new page (one such pass per
  request, memoized), for the same reason. This is an accepted tradeoff at
  this app's current catalog scale, not a new decision — flag it for
  revisiting only if it becomes a real bottleneck.

## Commands

```
php artisan make:filament-page NeedsAttention --no-interaction
```

This is a **standalone** page (no `--resource` flag) — it is not scoped to
any one resource.

## Models

### New enum: `Modules\Products\Enums\ProductIssue`

Location: `app-modules/products/src/Enums/ProductIssue.php` (sibling to the
existing `app-modules/products/src/Enums/ProductStatus.php`, same
conventions).

```php
<?php

declare(strict_types=1);

namespace Modules\Products\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ProductIssue: string implements HasColor, HasIcon, HasLabel
{
    case MissingImages = 'missing_images';
    case MissingCategories = 'missing_categories';
    case MissingRequiredAttributes = 'missing_required_attributes';
    case PublishedButIncomplete = 'published_but_incomplete';

    public function getLabel(): string
    {
        return match ($this) {
            self::MissingImages => 'Missing images',
            self::MissingCategories => 'Missing categories',
            self::MissingRequiredAttributes => 'Missing required attributes',
            self::PublishedButIncomplete => 'Published but incomplete',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PublishedButIncomplete => 'danger',
            default => 'warning',
        };
    }

    public function getIcon(): string|\BackedEnum
    {
        return match ($this) {
            self::MissingImages => Heroicon::OutlinedPhoto,
            self::MissingCategories => Heroicon::OutlinedTag,
            self::MissingRequiredAttributes => Heroicon::OutlinedExclamationTriangle,
            self::PublishedButIncomplete => Heroicon::OutlinedExclamationCircle,
        };
    }
}
```

`TitleCase` case names per this project's PHP conventions (matches
`ProductStatus`'s `Draft`/`Published` case naming).

### Update: `Modules\Products\Models\Product`

Add a new public method, placed near `missingRequiredAttributes()` /
`completenessPercentage()` (`app-modules/products/src/Models/Product.php`,
after line 152), following the same eager-load-dependency documentation
style already used by those methods:

```php
use Modules\Products\Enums\ProductIssue;

/**
 * Data-quality issues affecting this product, for the "Needs Attention"
 * page. Reads from already-loaded relations — callers must eager-load
 * `media`, `categories`, `attributeSet.attributes`,
 * `attributeValues.attribute`, and `channels` to avoid N+1 (see
 * App\Filament\Pages\NeedsAttention).
 *
 * @return Collection<int, ProductIssue>
 */
public function issues(): Collection
{
    $issues = collect();

    if ($this->getMedia('images')->isEmpty()) {
        $issues->push(ProductIssue::MissingImages);
    }

    if ($this->categories->isEmpty()) {
        $issues->push(ProductIssue::MissingCategories);
    }

    if ($this->missingRequiredAttributes()->isNotEmpty()) {
        $issues->push(ProductIssue::MissingRequiredAttributes);
    }

    $isPublishedOnAnyChannel = $this->channels
        ->contains(fn (Channel $channel): bool => (bool) $channel->pivot->is_published);

    if ($isPublishedOnAnyChannel && $issues->isNotEmpty()) {
        $issues->push(ProductIssue::PublishedButIncomplete);
    }

    return $issues->values();
}
```

Notes for the implementing agent:

- `getMedia('images')` (from `Spatie\MediaLibrary\InteractsWithMedia`, already
  used by this model) reads from the already-loaded `media` relation without
  an extra query **if** `media` has been eager-loaded on the query — add
  `media` to the eager-load list below. If not loaded, it will issue its own
  query per row (N+1); the page's query must eager-load it.
- `Channel` here is `Modules\Channels\Models\Channel`, already imported in
  this file for the existing `channels(): BelongsToMany` relation.
- `$this->categories`, `$this->attributeValues` are accessed as loaded
  relations (no `()`), same pattern as `missingRequiredAttributes()` already
  uses for `$this->attributeValues`.
- `PublishedButIncomplete` is only added when at least one of the three
  preceding issues was already pushed — i.e. it means "live somewhere, but
  not actually complete", not a standalone data-quality check of its own.

## Custom Page: NeedsAttention

```
Page: NeedsAttention
  Command: php artisan make:filament-page NeedsAttention --no-interaction
  Location: App\Filament\Pages\NeedsAttention
  Docs:
    - https://filamentphp.com/docs/4.x/resources/custom-pages
    - https://filamentphp.com/docs/4.x/components/table
    - https://filamentphp.com/docs/4.x/tables/columns/text
    - https://filamentphp.com/docs/4.x/tables/filters/custom

  Implements (in addition to what Filament\Pages\Page already provides):
    Filament\Tables\Contracts\HasTable
  Uses (in addition to the base Page class):
    Filament\Tables\Concerns\InteractsWithTable

  Navigation:
    Group: 'Catalog'   (static property: protected static string|\UnitEnum|null $navigationGroup = 'Catalog';  — matches ProductResource and siblings)
    Icon: Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle
      (Imports: Filament\Support\Icons\Heroicon)
    Label: 'Needs Attention'  (protected static ?string $navigationLabel)
    Sort: 5  (ProductResource uses 4; this places it directly after Products in the Catalog group — cosmetic, easily changed later)

  Title: 'Needs Attention'  (protected static ?string $title)

  Authorization: All authenticated users.
    No policy — this app has no policy classes anywhere (confirmed: no
    `*Policy.php` file exists in app/ or app-modules/). Matches existing
    convention; do not add a ProductPolicy as part of this blueprint.

  Content: a single table of products with ≥1 issue (see Table below).

  Blade view: resources/views/filament/pages/needs-attention.blade.php
    Content:
      <x-filament-panels::page>
          {{ $this->table }}
      </x-filament-panels::page>
```

### Page class body

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Products\Enums\ProductIssue;
use Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use Modules\Products\Models\Product;

class NeedsAttention extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Needs Attention';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Needs Attention';

    protected string $view = 'filament.pages.needs-attention';

    /**
     * One pass over every tenant product, computing issues per product.
     * Memoized per request — both the base table query and the issue-type
     * filter need this map, and it must only run once. Same eager-load list
     * Product::issues() depends on (see its docblock).
     *
     * @var array<string, Collection<int, int>>|null issue value => product IDs
     */
    protected ?array $productIdsByIssueCache = null;

    /**
     * @return array<string, Collection<int, int>>
     */
    protected function productIdsByIssue(): array
    {
        if ($this->productIdsByIssueCache !== null) {
            return $this->productIdsByIssueCache;
        }

        $map = [];

        foreach (ProductIssue::cases() as $issue) {
            $map[$issue->value] = collect();
        }

        Product::query()
            ->with(['media', 'categories', 'attributeSet.attributes', 'attributeValues.attribute', 'channels'])
            ->get()
            ->each(function (Product $product) use (&$map): void {
                foreach ($product->issues() as $issue) {
                    $map[$issue->value]->push($product->id);
                }
            });

        return $this->productIdsByIssueCache = $map;
    }

    protected function allProductIdsWithIssues(): Collection
    {
        return collect($this->productIdsByIssue())
            ->flatten()
            ->unique()
            ->values();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Product::query()
                ->with(['media', 'categories', 'attributeSet.attributes', 'attributeValues.attribute', 'channels'])
                ->whereIn('id', $this->allProductIdsWithIssues()))
            ->columns([
                SpatieMediaLibraryImageColumn::make('images')
                    ->collection('images')
                    ->conversion('thumb')
                    ->limit(1),

                TextColumn::make('sku')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('issues')
                    ->label('Issues')
                    ->state(fn (Product $record): array => $record->issues()->all())
                    ->badge()
                    ->formatStateUsing(fn (ProductIssue $state): string => $state->getLabel())
                    ->color(fn (ProductIssue $state): string => $state->getColor())
                    ->icon(fn (ProductIssue $state): string|\BackedEnum => $state->getIcon()),
            ])
            ->filters([
                Filter::make('issue')
                    ->schema([
                        Select::make('types')
                            ->label('Issue type')
                            ->multiple()
                            ->options(ProductIssue::class),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $selectedTypes = $data['types'] ?? [];

                        if (empty($selectedTypes)) {
                            return $query;
                        }

                        $byIssue = $this->productIdsByIssue();

                        $ids = collect($selectedTypes)
                            ->flatMap(fn (string $type): Collection => $byIssue[$type] ?? collect())
                            ->unique();

                        return $query->whereIn('id', $ids);
                    }),
            ])
            ->recordUrl(fn (Product $record): string => EditProduct::getUrl(['record' => $record]));
    }
}
```

Notes for the implementing agent:

- `EditProduct` is `Modules\Products\Filament\Resources\Products\Pages\EditProduct`
  — the row link goes to the product's main edit page, which already has
  sub-navigation to Images / Attributes / Related Products etc.
  (`ProductResource::getRecordSubNavigation()`), so landing there gives the
  user a path to every specific fix without this page needing per-issue-type
  routing logic.
- `->columns` deliberately mirrors `ProductResource::table()`'s
  `images`/`sku`/`name` columns for visual consistency with the main product
  list.
- Do not add toolbar/bulk actions — this is a read-only worklist, not a CRUD
  table. No `DeleteAction`, no `BulkActionGroup`.

## Widget update: `App\Filament\Widgets\ProductCompletenessOverview`

Make the three "problem count" stats clickable, linking to the new page
(unfiltered — see "Verified API notes" above for why this does not
pre-filter by issue type: `Stat` has no `->url()` method in this version,
and deep-linking table filter state via URL query parameters is not
confirmed in the installed version's docs, so it is not attempted here).
`Average Completeness` stays as-is (not a single filterable issue type).

```php
use App\Filament\Pages\NeedsAttention;

// inside getStats(), after computing $missingRequiredAttributes, $missingImages, $missingCategories as today:

$needsAttentionUrl = NeedsAttention::getUrl();

// ...

Stat::make('Missing Required Attributes', (string) $missingRequiredAttributes)
    ->description("out of {$totalProducts} products")
    ->icon(Heroicon::OutlinedExclamationTriangle)
    ->color($missingRequiredAttributes > 0 ? 'warning' : 'success')
    ->extraAttributes([
        'class' => 'cursor-pointer',
        'onclick' => "window.location='{$needsAttentionUrl}'",
    ]),

Stat::make('Missing Images', (string) $missingImages)
    ->icon(Heroicon::OutlinedPhoto)
    ->color($missingImages > 0 ? 'warning' : 'success')
    ->extraAttributes([
        'class' => 'cursor-pointer',
        'onclick' => "window.location='{$needsAttentionUrl}'",
    ]),

Stat::make('Missing Categories', (string) $missingCategories)
    ->icon(Heroicon::OutlinedTag)
    ->color($missingCategories > 0 ? 'warning' : 'success')
    ->extraAttributes([
        'class' => 'cursor-pointer',
        'onclick' => "window.location='{$needsAttentionUrl}'",
    ]),
```

`NeedsAttention::getUrl()` returns a resolved, non-user-controlled string
(the page's own route), so no `Str::sanitizeUrl()` wrapping is needed — that
guard is for user-controlled/record-derived URLs, which this is not.

## Tests

Follow this project's existing conventions: `php artisan make:test --pest
{Name}`, use `actingAsCatalogUser()` (existing global test helper, already
used by `ProductCompletenessOverviewTest`) for tenant setup, and
`Livewire::test(...)` for page interaction. New file:
`tests/Feature/Filament/Pages/NeedsAttentionTest.php` (the
`tests/Feature/Filament/Pages/` directory already exists for other pages).

```
NeedsAttention page:
  Authorization:
    - any authenticated catalog user can access the page (assertOk via Livewire::test)

  Component Config / Query:
    - a product with no images, no categories, a complete required-attribute
      set, and no channels does NOT appear in the table
    - a product missing images only appears in the table, with exactly the
      "Missing images" badge (assertCanSeeTableRecords / assertTableColumnExists
      on 'issues' with a checkComponentUsing callback, or assertSee the badge label)
    - a product missing categories only appears, with exactly the
      "Missing categories" badge
    - a product with a required attribute set and no filled value for a
      required attribute appears, with exactly the "Missing required
      attributes" badge
    - a product that is published (is_published = true) on one channel while
      also missing images appears with BOTH "Missing images" and "Published
      but incomplete" badges
    - a product attached to a channel with is_published = false, and
      otherwise complete, does NOT get "Published but incomplete" and does
      NOT appear in the table at all
    - a product complete on its legacy attributeSet but with a required,
      unfilled attribute only on a secondary attributeSets() entry does NOT
      appear (asserts the confirmed known-limitation behavior is actually
      inherited, not accidentally fixed)

  Filters:
    - filtering by issue type 'missing_images' shows only products with that
      issue, excluding products with only other issues
    - filtering by multiple issue types shows the union of matching products
    - clearing the filter restores the full needs-attention list

  Row link:
    - clicking/visiting a row's record URL resolves to that product's
      EditProduct page URL
```

### Discriminating test note

For the "published but incomplete" cases, use two products that are
otherwise identical (same missing-images state) but differ only in their
channel's `is_published` pivot value, and assert the issue list by exact
badge presence/absence — not just a row count — so a wrong implementation
that ignores the pivot (e.g. treating "has any channel" as "published")
would fail a planted test.

For the known-limitation test, create a product with:

- a legacy `attribute_set_id` pointing to an attribute set with no required
  attributes (or none at all), and
- a separate attribute set attached via `attributeSets()` that does have an
  unfilled required attribute.

Assert the product does **not** appear in the needs-attention list. This
pins the currently-agreed behavior so a future, unrelated change to
`Product::issues()` doesn't silently start disagreeing with the completeness
widget/column without that being a deliberate, visible decision.
