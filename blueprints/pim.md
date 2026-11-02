# PIM Filament Blueprint

## Context

This is a Filament Blueprint (per the `planning-filament` skill) for the PIM
(Product Information Management) Filament admin panel.

**Decisions locked in before this blueprint was written** (built with the
same foundation as a sibling WMS project — Laravel, Filament, multi-tenancy,
and activity logging all follow the same approach):

- **Two-layer multi-tenancy**: a root `Subdomain` (the company) contains one
  or more `Catalog`s (the Filament tenant). A company can run more than one
  product catalog side by side, switchable in the panel — this is the one
  deliberate difference from the single-warehouse-per-request shape used
  elsewhere: `Catalog` plays the same structural role `Warehouse` plays in
  the sibling WMS project.
- Codebase stays a **modular monolith** via `internachi/modular`: each PIM
  domain (Products, Attributes, Categories, Channels, Media, ...) gets its
  own `app-modules/<name>` composer package, discovered per-module in
  `AdminPanelProvider`. Only the `users` module exists so far (minimal — just
  the `User` model and its migration, enough for login). Every other domain
  module is deliberately **not yet created** — each is scoped and blueprinted
  here module-by-module before it's built.
- No multi-factor authentication, no GraphQL API, no barcode/QR scanning —
  none of those WMS-specific extras were carried over. Add them later only if
  the PIM design calls for them.

---

## 1. Tenancy & Authorization Foundations (existing — reference only)

These rules apply across every module documented in this Blueprint.

- **Two-layer multi-tenancy**:
  - Subdomain (tenant root): `App\Models\Subdomain` (`id`, `subdomain`
    unique, `name` unique). Resolved per-request by
    `App\Http\Middleware\IdentifySubdomain`, which binds
    `app()->instance('current_subdomain', $subdomain)`.
    `App\Models\Scopes\TenantScope` applies `where subdomain_id =
    current_subdomain.id` — used by `Catalog` and `Modules\Users\Models\User`.
  - Catalog (Filament tenant): configured in
    `app/Providers/Filament/AdminPanelProvider.php` via
    `->tenant(App\Models\Catalog::class, ownershipRelationship: 'catalog',
    slugAttribute: 'name')`. `App\Models\Scopes\CatalogScope` applies
    `catalog_id = Filament::getTenant()->getKey()` to every model using the
    `App\Models\Concerns\BelongsToCatalog` trait. **No domain model uses this
    trait yet** — the first one to need it is added when that module is
    blueprinted.
  - Login is additionally scoped per-subdomain by a custom auth provider,
    `App\Auth\SubdomainUserProvider` (registered as the `subdomain` driver in
    `config/auth.php`, wired up in `AppServiceProvider::boot()`): it restricts
    credential lookup to the bound `current_subdomain`, so a user from one
    company can never authenticate into another company's data even if
    `TenantScope` were somehow bypassed.
- **No Policy classes** — authorization is via Eloquent global scopes
  (tenant/catalog isolation) and `User::canAccessPanel()`/`canAccessTenant()`
  overrides. Follow this same convention for new work unless a module
  blueprint says otherwise.
- Navigation groups: only one is registered so far,
  `NavigationGroup::make()->label('Settings')`, in `AdminPanelProvider`.

---

## 2. Activity History Convention (implemented)

Every module's top-level Filament Resource should get a "History"
sub-navigation tab backed by `spatie/laravel-activitylog`, shown via
`alizharb/filament-activity-log`'s `ActivityLogResource`
(`app/Filament/Resources/ActivityLogs/ActivityLogResource.php`). This section
documents the pattern once; each module file should state only its own
specifics (which model(s), any field exclusions) and link back here.

**Model side** — add to any model that should have a History tab:
```php
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class X extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('x')
            ->logFillable() // or ->logOnly([...]) — see per-model exclusion notes
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

**Filament side** — one new page per resource:
```php
namespace Modules\<Module>\Filament\Resources\<X>\Pages;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Modules\<Module>\Filament\Resources\<X>\<X>Resource;

class Manage<X>Activities extends ManageRelatedRecords
{
    protected static string $resource = <X>Resource::class;
    protected static string $relationship = 'activitiesAsSubject';
    protected static ?string $relatedResource = ActivityLogResource::class;
    protected static ?string $navigationLabel = 'History';
    protected static ?string $breadcrumb = 'History';
    protected static ?string $title = 'History';
}
```
Note the relationship is `activitiesAsSubject` (Spatie `LogsActivity`'s real
method) — **not** `activities`, which doesn't exist on these models.

**Tenant scoping** —
`App\Filament\Resources\ActivityLogs\ActivityLogResource::scopeEloquentQueryToTenant()`
filters the History tab's rows to the current catalog. Every model newly
given `LogsActivity` must be added to this method's subject-type list, with
a shape depending on how it relates to a `Catalog`:
- **Direct `catalog_id`**: add to a `whereBelongsTo($tenant, 'catalog')`
  group.
- **`Catalog` itself**: the subject *is* the tenant — branch on
  `whereKey($tenant->getKey())` (already implemented).
- **`User`**: no `catalog_id` — scoped via the `catalog_user` pivot (already
  implemented).

**Sensitive-field exclusions**: never log passwords/tokens/secrets.
`User::getActivitylogOptions()` already uses `->logOnly(['name', 'email'])`
to exclude `password`. Follow the same pattern for any future model holding
credentials or API keys.

**Scope of this convention**: one History tab per top-level Filament
Resource only. Child/line-item models don't get their own tab.

---

## Module Blueprints

Each PIM domain is designed and documented here as it's scoped out —
nothing is listed yet. Candidate domains to work through together:

- **Products & SKUs** — simple vs. configurable/variant products
- **Attributes & Attribute Sets/Families** — flexible per-category product
  data
- [category.md](category.md) — tree-structured categories (adjacency list,
  unlimited depth) with drag-reorder and a deferred product-assignment pivot
- **Channels & Locales** — multi-channel publishing, per-locale/per-channel
  content overrides
- **Media/DAM** — product images/assets, reuse across products
- **Relations** — related/cross-sell/up-sell products, variant grouping
- **Import/Export** — feed ingestion, channel export formats
- **Completeness/workflow** — draft → ready → published states, data
  quality scoring

As each is designed, add it here as `[module.md](module.md) — one-line
description`, following the Model → Resource (Form/Table/Actions) →
notable implementation details shape used throughout this Blueprint.
