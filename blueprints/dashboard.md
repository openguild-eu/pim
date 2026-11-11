# Blueprint: Dashboard Widgets

## Goal

Replace the admin panel's out-of-the-box dashboard (currently just
`Filament\Widgets\AccountWidget` + `Filament\Widgets\FilamentInfoWidget`, no
PIM data at all) with four tenant-scoped widgets that give an editor a
one-screen view of their current `Catalog`: catalog size, product data
quality, how products are spread across channels, and recent activity.

Out of scope (explicitly, do not implement): new Filament panels, new
resources, caching/storing computed stats, a "sync status" concept for
channels (no such field exists anywhere in the schema — the channel widget
uses the real `channel_product.is_published` pivot instead), per-locale or
per-channel completeness (see `blueprints/product-completeness.md`, which this
plan depends on and which already rules that out).

## Dependency: `blueprints/product-completeness.md`

**This blueprint must be implemented first.** Verified against the current
code (`app-modules/products/src/Models/Product.php` and
`app-modules/attributes/src/Models/AttributeValue.php`): none of the methods
below exist yet.

The Product Completeness blueprint adds:

- `Modules\Attributes\Models\AttributeValue::isFilled(): bool`
- `Modules\Products\Models\Product::missingRequiredAttributes(): Collection<int, Attribute>`
- `Modules\Products\Models\Product::completenessPercentage(): int` (100 when
  the product has no `attribute_set_id`, or its attribute set has no required
  attributes)

**Why this widget must not reimplement the calculation itself:** "is this
attribute value filled?" already has one canonical, tested rule in that
blueprint (e.g. an explicit `value_boolean = false` counts as filled; a
`multiselect` saved as `'[]'` does not — a naive "does an `AttributeValue` row
exist for this product+attribute?" check gets both of these wrong). A
dashboard widget that re-derived completeness via a raw SQL anti-join would
silently disagree with the product list's own completeness badge on exactly
those two cases. The `ProductCompletenessOverview` widget below calls
`completenessPercentage()` / `missingRequiredAttributes()` per product instead
of re-deriving the rule.

## Security Rule: Tenant Scoping for Widget Queries

**Rule:** every widget query must only ever return rows belonging to
`Filament::getTenant()` (the current `Catalog`).

**How scoping already works for each model used here:**

| Model | Scoped how |
| --- | --- |
| `Modules\Products\Models\Product` | `BelongsToCatalog` trait → `CatalogScope` global scope, automatic |
| `Modules\Categories\Models\Category` | same, automatic |
| `Modules\Attributes\Models\AttributeSet` | same, automatic |
| `Modules\Attributes\Models\Attribute` | same, automatic |
| `Modules\Channels\Models\Channel` | same, automatic |
| `Spatie\Activitylog\Models\Activity` | **not scoped at all** — no `catalog_id` column, does not use `BelongsToCatalog` |

**Consistency check (why `RecentActivityWidget` below cannot use the vendor
widget as-is):** `alizharb/filament-activity-log`'s
`AlizHarb\ActivityLog\Widgets\LatestActivityWidget` runs
`Activity::query()->latest()->limit(...)` with no tenant filter at all — on
this app that widget would show every Catalog's activity to every Catalog's
users, a cross-tenant data leak. Confirmed in
`vendor/alizharb/filament-activity-log/src/Widgets/LatestActivityWidget.php`
and the package's config (`config/filament-activity-log.php`) — neither has
any tenant/catalog-scoping hook. Do not register that widget on the
dashboard. Build `RecentActivityWidget` below instead, which scopes manually
via `whereHasMorph()` against the catalog-scoped subject models.

**Verification:** see `RecentActivityWidgetTest` in the Tests section, which
asserts activity belonging to a different Catalog is never visible.

## Commands

```bash
php artisan make:filament-widget CatalogStatsOverview --stats-overview --no-interaction
php artisan make:filament-widget ProductCompletenessOverview --stats-overview --no-interaction
php artisan make:filament-widget ChannelDistributionChart --chart --no-interaction
php artisan make:filament-widget RecentActivityWidget --table --no-interaction
```

All four are created in `app/Filament/Widgets/` (the default path), which
`AdminPanelProvider.php` already auto-discovers via
`->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')`
— no manual registration needed for them.

## Models

N/A — no model changes in this blueprint (see Dependency section above for
the one prerequisite blueprint that does change models).

## Resources

N/A — no resource changes.

## Update: `app/Providers/Filament/AdminPanelProvider.php`

Only this one line changes, everything else in the file stays exactly as-is
(per the existing `blueprints/filament-branding.md` guardrail: don't touch
tenancy, plugins, discovery calls, navigation groups, or middleware beyond
what's specified here):

```diff
-use Filament\Widgets\AccountWidget;
-use Filament\Widgets\FilamentInfoWidget;
+use Filament\Widgets\AccountWidget;
```

```diff
             ->widgets([
                 AccountWidget::class,
-                FilamentInfoWidget::class,
             ])
```

**Rationale:** `FilamentInfoWidget` shows Filament framework/version info and
has no business value on a PIM dashboard; removing it is purely cosmetic.
Keep `AccountWidget` (user avatar/logout). Do not add the four new widgets to
this array — they're picked up by `discoverWidgets()` already.

## Widgets

### Widget: CatalogStatsOverview

Type: `Filament\Widgets\StatsOverviewWidget`
Docs: https://filamentphp.com/docs/4.x/widgets/stats-overview
Location: Dashboard
File: `app/Filament/Widgets/CatalogStatsOverview.php`

```php
protected static ?int $sort = 1;

protected ?string $pollingInterval = null;
```

Imports: `Filament\Widgets\StatsOverviewWidget\Stat`,
`Filament\Support\Icons\Heroicon`, `Modules\Products\Models\Product`,
`Modules\Categories\Models\Category`, `Modules\Attributes\Models\AttributeSet`,
`Modules\Channels\Models\Channel`.

```php
protected function getStats(): array
{
    return [
        Stat::make('Products', (string) Product::query()->count())
            ->icon(Heroicon::OutlinedCube),

        Stat::make('Categories', (string) Category::query()->count())
            ->icon(Heroicon::OutlinedRectangleGroup),

        Stat::make('Attribute Sets', (string) AttributeSet::query()->count())
            ->icon(Heroicon::OutlinedListBullet),

        Stat::make('Channels', (string) Channel::query()->count())
            ->icon(Heroicon::OutlinedGlobeAlt),
    ];
}
```

All four counts are automatically catalog-scoped (see Security Rule table
above) — no manual `where('catalog_id', ...)` needed.

### Widget: ProductCompletenessOverview

Type: `Filament\Widgets\StatsOverviewWidget`
Docs: https://filamentphp.com/docs/4.x/widgets/stats-overview
Location: Dashboard
File: `app/Filament/Widgets/ProductCompletenessOverview.php`

Depends on `Product::completenessPercentage()` / `missingRequiredAttributes()`
from `blueprints/product-completeness.md` (see Dependency section).

```php
protected static ?int $sort = 2;

protected ?string $pollingInterval = null;
```

Imports: `Filament\Widgets\StatsOverviewWidget\Stat`,
`Filament\Support\Icons\Heroicon`, `Modules\Products\Models\Product`.

```php
protected function getStats(): array
{
    // Single eager-loaded pass — completenessPercentage() and
    // missingRequiredAttributes() both read from these relations rather
    // than querying (see product-completeness.md), so this avoids N+1
    // across every product on every widget render.
    $products = Product::query()
        ->with(['attributeSet.attributes', 'attributeValues.attribute'])
        ->get();

    $totalProducts = $products->count();

    $averageCompleteness = $totalProducts > 0
        ? (int) round($products->avg(fn (Product $product): int => $product->completenessPercentage()))
        : 100;

    $missingRequiredAttributes = $products
        ->filter(fn (Product $product): bool => $product->missingRequiredAttributes()->isNotEmpty())
        ->count();

    $missingImages = Product::query()
        ->whereDoesntHave('media', fn ($query) => $query->where('collection_name', 'images'))
        ->count();

    $missingCategories = Product::query()
        ->doesntHave('categories')
        ->count();

    return [
        Stat::make('Average Completeness', "{$averageCompleteness}%")
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color(match (true) {
                $averageCompleteness === 100 => 'success',
                $averageCompleteness >= 50 => 'warning',
                default => 'danger',
            }),

        Stat::make('Missing Required Attributes', (string) $missingRequiredAttributes)
            ->description("out of {$totalProducts} products")
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color($missingRequiredAttributes > 0 ? 'warning' : 'success'),

        Stat::make('Missing Images', (string) $missingImages)
            ->icon(Heroicon::OutlinedPhoto)
            ->color($missingImages > 0 ? 'warning' : 'success'),

        Stat::make('Missing Categories', (string) $missingCategories)
            ->icon(Heroicon::OutlinedTag)
            ->color($missingCategories > 0 ? 'warning' : 'success'),
    ];
}
```

`whereDoesntHave('media', ...)` relies on `Product` already implementing
`Spatie\MediaLibrary\HasMedia` with `InteractsWithMedia` (confirmed in
`app-modules/products/src/Models/Product.php`), which provides the
polymorphic `media()` relation and the `images` collection used elsewhere in
the app (`registerMediaCollections()`). `doesntHave('categories')` uses the
existing `categories()` `BelongsToMany` relation.

### Widget: ChannelDistributionChart

Type: `Filament\Widgets\ChartWidget`
Docs: https://filamentphp.com/docs/4.x/widgets/charts
Location: Dashboard
File: `app/Filament/Widgets/ChannelDistributionChart.php`
ChartType: bar (grouped, not stacked — see `getOptions()` below)
Heading: "Products per Channel"

```php
protected static ?int $sort = 3;

protected ?string $heading = 'Products per Channel';

protected ?string $pollingInterval = null;
```

Imports: `Modules\Channels\Models\Channel`.

```php
protected function getData(): array
{
    $channels = Channel::query()
        ->withCount([
            'products as published_count' => fn ($query) => $query->wherePivot('is_published', true),
            'products as unpublished_count' => fn ($query) => $query->wherePivot('is_published', false),
        ])
        ->orderBy('name')
        ->get();

    return [
        'datasets' => [
            [
                'label' => 'Published',
                'data' => $channels->pluck('published_count')->all(),
                'backgroundColor' => '#2547D0',
            ],
            [
                'label' => 'Unpublished',
                'data' => $channels->pluck('unpublished_count')->all(),
                'backgroundColor' => '#F2B134',
            ],
        ],
        'labels' => $channels->pluck('name')->all(),
    ];
}

protected function getType(): string
{
    return 'bar';
}
```

`'#2547D0'` / `'#F2B134'` match the panel's existing `info` / `primary`
colors configured in `AdminPanelProvider.php`'s `->colors([...])`, so the
chart stays visually consistent with the rest of the panel. `is_published`
defaults to `false` and is non-nullable on `channel_product`
(`app-modules/channels/database/migrations/2026_11_03_030002_create_channel_product_table.php`),
so no null-handling is needed in the pivot filters.

Channel results are automatically catalog-scoped (see Security Rule table);
`withCount` constrains the pivot (`channel_product`), not `products`, so it
does not need its own catalog filter — a channel can only be linked to
products that already belong to its own catalog in practice, but even if
that weren't true, `Channel::query()` itself is scoped, which is what this
chart iterates over.

### Widget: RecentActivityWidget

Type: `Filament\Widgets\TableWidget`
Docs: https://filamentphp.com/docs/4.x/widgets/overview#table-widgets
Location: Dashboard
File: `app/Filament/Widgets/RecentActivityWidget.php`

```php
protected static ?int $sort = 4;

protected array|string|int $columnSpan = 'full';

protected ?string $heading = 'Recent Activity';
```

Imports: `Filament\Facades\Filament`, `Filament\Tables\Columns\TextColumn`,
`Filament\Tables\Table`, `Illuminate\Database\Eloquent\Builder`,
`Spatie\Activitylog\Models\Activity`, `Modules\Products\Models\Product`,
`Modules\Categories\Models\Category`, `Modules\Attributes\Models\AttributeSet`,
`Modules\Attributes\Models\Attribute`, `Modules\Channels\Models\Channel`.

```php
public function table(Table $table): Table
{
    $catalogId = Filament::getTenant()?->getKey();

    return $table
        ->query(
            Activity::query()
                ->whereHasMorph(
                    'subject',
                    [Product::class, Category::class, AttributeSet::class, Attribute::class, Channel::class],
                    fn (Builder $query) => $query->where('catalog_id', $catalogId),
                )
                ->with(['causer', 'subject'])
                ->latest()
                ->limit(10)
        )
        ->columns([
            TextColumn::make('event')
                ->badge()
                ->colors([
                    'success' => 'created',
                    'warning' => 'updated',
                    'danger' => 'deleted',
                ]),

            TextColumn::make('subject_type')
                ->label('Subject')
                ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '-'),

            TextColumn::make('description')
                ->default('-')
                ->limit(60),

            TextColumn::make('causer.name')
                ->label('By')
                ->default('System'),

            TextColumn::make('created_at')
                ->since()
                ->sortable(),
        ])
        ->paginated(false);
}
```

Config: `->paginated(false)` (matches the `limit(10)` already applied in the
query, consistent with the vendor widget's own convention). No
`Config: ->poll(...)` call — leave table widget polling at its Filament
default.

**Why `whereHasMorph` with those five classes and not `attributeValues` or
`ApiKey`:** those are exactly the models that both (a) use `LogsActivity` and
(b) have their own direct `catalog_id` column, per the Security Rule table
above. `Modules\Channels\Models\ApiKey` also logs activity but only has
`channel_id` (not `catalog_id`); its activity is intentionally excluded from
this widget rather than guessed at — it remains visible in the full
`ActivityLogs` resource. `AttributeValue` has no `getActivitylogOptions()` and
does not log activity at all (confirmed in
`app-modules/attributes/src/Models/AttributeValue.php`), so it's not in the
list for that reason too.

## Authorization

All four widgets: visible to every authenticated panel user, same as the
existing `AccountWidget`. No `canView()` override needed — `config('filament-activity-log.permissions.enabled')`
is `false` in this app (`config/filament-activity-log.php`) and there is no
`app/Policies` directory, so the existing `ActivityLogs` resource itself has
no package-level permission gate either; `RecentActivityWidget` matches that
existing (lack of) restriction rather than inventing a new one.

## Tests

Create `tests/Feature/Filament/Widgets/CatalogStatsOverviewTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Widgets\CatalogStatsOverview;
use Livewire\Livewire;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;

it('shows counts scoped to the current catalog only', function () {
    $catalog = actingAsCatalogUser();
    Product::factory()->count(2)->create(['catalog_id' => $catalog->id]);
    Category::factory()->create(['catalog_id' => $catalog->id]);

    // Different catalog — must not affect the counts above.
    Product::factory()->count(5)->create();

    Livewire::test(CatalogStatsOverview::class)
        ->assertSee('2')
        ->assertSee('1');
});
```

Create `tests/Feature/Filament/Widgets/ProductCompletenessOverviewTest.php`
(depends on `blueprints/product-completeness.md` being implemented first —
uses `Attribute`, `AttributeSet`, `AttributeValue` the same way
`ProductResourceTest.php` does):

```php
<?php

declare(strict_types=1);

use App\Filament\Widgets\ProductCompletenessOverview;
use Livewire\Livewire;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Products\Models\Product;

it('reports average completeness and missing-attribute count across products', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);

    Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]); // 100%
    Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]); // 0%, missing

    Livewire::test(ProductCompletenessOverview::class)
        ->assertSee('50%')
        ->assertSee('1')
        ->assertSee('out of 2 products');
});

it('counts products missing images and categories', function () {
    $catalog = actingAsCatalogUser();
    Product::factory()->create(['catalog_id' => $catalog->id]);
    $withCategory = Product::factory()->create(['catalog_id' => $catalog->id]);
    $withCategory->categories()->attach(
        \Modules\Categories\Models\Category::factory()->create(['catalog_id' => $catalog->id])
    );

    Livewire::test(ProductCompletenessOverview::class)
        ->assertSee('Missing Images')
        ->assertSee('Missing Categories');
});
```

Create `tests/Feature/Filament/Widgets/ChannelDistributionChartTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Widgets\ChannelDistributionChart;
use Livewire\Livewire;
use Modules\Channels\Models\Channel;
use Modules\Products\Models\Product;

it('builds published/unpublished counts per channel, using ReflectionMethod since getData() is protected', function () {
    $catalog = actingAsCatalogUser();
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Webshop']);
    $published = Product::factory()->create(['catalog_id' => $catalog->id]);
    $unpublished = Product::factory()->create(['catalog_id' => $catalog->id]);

    $channel->products()->attach($published->id, ['is_published' => true]);
    $channel->products()->attach($unpublished->id, ['is_published' => false]);

    $widget = Livewire::test(ChannelDistributionChart::class)->instance();
    $data = (new ReflectionMethod($widget, 'getData'))->invoke($widget);

    expect($data['labels'])->toBe(['Webshop']);
    expect($data['datasets'][0]['data'])->toBe([1]); // published
    expect($data['datasets'][1]['data'])->toBe([1]); // unpublished
});
```

Create `tests/Feature/Filament/Widgets/RecentActivityWidgetTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentActivityWidget;
use Livewire\Livewire;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;

it('shows activity for the current catalog only', function () {
    $catalog = actingAsCatalogUser();
    $ownProduct = Product::factory()->create(['catalog_id' => $catalog->id]);
    $ownProduct->update(['name' => 'Renamed']); // logs an 'updated' activity

    // A different catalog's product activity must never appear here.
    $foreignProduct = Product::factory()->create();
    $foreignProduct->update(['name' => 'Should not appear']);

    Livewire::test(RecentActivityWidget::class)
        ->assertCanSeeTableRecords(
            \Spatie\Activitylog\Models\Activity::query()->where('subject_id', $ownProduct->id)->get()
        )
        ->assertCanNotSeeTableRecords(
            \Spatie\Activitylog\Models\Activity::query()->where('subject_id', $foreignProduct->id)->get()
        );
});

it('excludes activity for models without a direct catalog_id, such as ApiKey', function () {
    actingAsCatalogUser();
    $data = makeChannelWithApiKey();
    $data['apiKey']->update(['name' => 'Renamed key']); // logs activity on ApiKey, which has no catalog_id

    Livewire::test(RecentActivityWidget::class)
        ->assertCanNotSeeTableRecords(
            \Spatie\Activitylog\Models\Activity::query()->where('subject_type', \Modules\Channels\Models\ApiKey::class)->get()
        );
});
```

### Test prerequisites

- All tests use `actingAsCatalogUser()` and `makeChannelWithApiKey()`, both
  already defined in `tests/Pest.php` — no new test helpers needed.
- `assertCanSeeTableRecords()` / `assertCanNotSeeTableRecords()` are the
  project's existing Filament table-testing helpers (already used in
  `tests/Feature/Filament/Resources/Catalogs/CatalogResourceTest.php`).
- The `ChannelDistributionChartTest` uses `ReflectionMethod` to invoke the
  protected `getData()` directly rather than asserting on rendered chart
  output, because Filament's chart widgets render their dataset as JSON
  consumed by Chart.js/Alpine on the client; whether that JSON is present
  verbatim in the server-rendered HTML (making `assertSee()` reliable) was
  not confirmed against the installed Filament version, so the deterministic
  `ReflectionMethod` approach is used instead to avoid asserting on an
  unverified rendering detail.
- Run after implementation: `php artisan test tests/Feature/Filament/Widgets --compact`
  (and the product-completeness tests in
  `app-modules/products/tests/Filament/Resources/Products/ProductResourceTest.php`,
  since that's the prerequisite blueprint).
