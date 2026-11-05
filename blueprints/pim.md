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
- No GraphQL API, no barcode/QR scanning — those WMS-specific extras were not
  carried over.
- **Multi-factor authentication (implemented)**: unlike the initial decision
  to leave MFA out, it was added to the Admin panel —
  `->multiFactorAuthentication([AppAuthentication::make()])` in
  `AdminPanelProvider`, with `Modules\Users\Models\User` implementing
  `HasAppAuthentication`, `HasAppAuthenticationRecovery`, and
  `HasEmailAuthentication` (TOTP app-based + email-based factors). The
  `app_authentication_secret` and `app_authentication_recovery_codes`
  columns are `encrypted`/`encrypted:array`-cast and listed in `$hidden`;
  neither is ever added to any model's `logOnly()` activity-log list, per
  §2's sensitive-field exclusion rule.

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
- Navigation groups: two are registered in `AdminPanelProvider` —
  `NavigationGroup::make()->label('Catalog')` (added once the first Catalog
  domain module was built) and `NavigationGroup::make()->label('Settings')`.

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

Each PIM domain is designed and documented here as it's scoped out.
Candidate domains to work through together:

- [category.md](category.md) — tree-structured categories (adjacency list,
  unlimited depth) with drag-reorder and a deferred product-assignment pivot
- [attributes.md](attributes.md) — EAV attributes + attribute sets
  (`AttributeSet`/`Attribute`/`AttributeValue`), the typed-column
  type→value_* mapping shared with products.md, no per-category defaults
- [products.md](products.md) — simple products only (variants deferred);
  `attribute_set_id` + the dynamic per-attribute-set form section; category
  assignment via `category_product`
- [media.md](media.md) — `spatie/laravel-medialibrary` +
  `filament/spatie-laravel-media-library-plugin` integration on Product,
  no new schema of its own
- [channels.md](channels.md) — `Channel` model + `channel_product`
  assignment pivot (`is_published`/`published_at`) on Product
- [channels-api-keys.md](channels-api-keys.md) — per-channel API keys
  gating a public, read-only Products JSON API (documented after the fact;
  stands alongside channels.md, doesn't replace it)
- [relations.md](relations.md) — `product_relations` pivot-model powering
  three directional relationship types (related/cross-sell/up-sell) as
  three Product sub-navigation tabs
- [product-import-export.md](product-import-export.md) — CSV/XLSX
  import (upsert by SKU) and export for Products, using Filament's
  already-installed import/export actions (no new dependency)
- **Completeness/workflow** — draft → ready → published states, data
  quality scoring (not yet designed)

As each is designed, add it here as `[module.md](module.md) — one-line
description`, following the Model → Resource (Form/Table/Actions) →
notable implementation details shape used throughout this Blueprint.
