# Administration Module

## Context

A per-subdomain `admin` role that can manage Users and Catalogs within its
own Subdomain, and assign existing Catalogs to Users. Builds on
`blueprints/pim.md` §1 (two-layer multi-tenancy: Subdomain → Catalog) and §2
(Activity History convention).

**New direct dependency**: `spatie/laravel-permission`. Not previously
installed (confirmed via `composer show --direct`). This package's "teams"
feature is not indexed by this project's `search-docs` (it only indexes
installed packages) — the exact config-file shape below is written from the
package's long-stable, documented public API, not a verified search-docs
hit. Before implementing, run `composer show spatie/laravel-permission`
after installing to confirm the resolved version and sanity-check the
`config/permission.php` shape (particularly the `teams` / `team_foreign_key`
keys) still matches what's below; if it doesn't, stop and reconcile rather
than silently adapting.

**Role model, deliberately minimal**: exactly one role, `admin`. No broader
permissions catalog, no Filament Shield-style permissions-matrix UI. Gating
is a direct `hasRole('admin')` check at the Resource level (see
Authorization), not a Laravel Policy class — this app's existing convention
(`blueprints/pim.md` §1) avoids Policy classes everywhere else, and this
blueprint continues that rather than introducing the one place policies
exist. This is the first resource-level authorization restriction this app
has anywhere — every other existing resource is implicitly "all
authenticated users." `User::canAccessPanel()` and `canAccessTenant()`
(`app-modules/users/src/Models/User.php`) are **unchanged** — every
authenticated user still logs in and sees their own Catalog-scoped
resources exactly as today; only the two new resources below are additionally
gated.

**Teams scoped to Subdomain, not Catalog**: a role granted to a user must
apply across every Catalog in their Subdomain (an admin manages the whole
company, not one catalog), and must never leak to another Subdomain. The
package's team foreign key is configured as `subdomain_id`, reusing the
exact value already bound into the container as `current_subdomain` by
`App\Http\Middleware\IdentifySubdomain` (`app/Http/Middleware/IdentifySubdomain.php`)
— the same binding `App\Models\Scopes\TenantScope` already reads (see
`app/Models/Scopes/TenantScope.php`, which does
`$builder->where($model->qualifyColumn('subdomain_id'), app('current_subdomain')->id)`
whenever that binding exists). This blueprint does not add a second
subdomain-scoping mechanism — permission "teams" and the existing
`TenantScope` are two independent systems that both key off the same
already-resolved value, by design.

**Resource placement**: `App\Models\Catalog` already lives at the app root
(not a module), so `CatalogResource` belongs in `app/Filament/Resources`.
`Modules\Users\Models\User` lives in the `users` module, but that module has
no `Filament/Resources` directory and no Filament resource of any kind today
(confirmed: `app-modules/users/src/` contains only `Models/`, `Providers/`,
and non-Filament `routes/` — see the directory listing). Rather than
introduce the module's first Filament resource for a cross-cutting
administrative concern, `UserResource` is placed in `app/Filament/Resources`
alongside `CatalogResource` — both are "manage the tenancy" concerns, not
Users-module business logic. This is a judgment call with no existing
precedent to follow either way; reconsider if a future blueprint establishes
one.

**Out of scope**: bootstrapping a brand-new Subdomain's very first admin
*User*. No self-service "create a new subdomain" flow exists yet —
`App\Http\Controllers\SubdomainLookupController` only looks up existing
subdomains (confirmed by reading it). The first admin per subdomain is still
assumed to be created out-of-band (e.g. `php artisan tinker`, or a future
seeder/command not specified here) — this blueprint builds no UI for that.
However, that out-of-band step no longer also needs to manually create a
first *Catalog* and attach the user to it — see "Tenant Registration" below,
which handles "a user with zero Catalogs" automatically via Filament's own
built-in mechanism for that case.

**Existing schema already covers catalog assignment** — no migration needed
for that part. `catalog_user` (`database/migrations/2026_11_02_000001_create_catalogs_table.php`):
```php
Schema::create('catalog_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users')->onUpdate('set null')->onDelete('cascade');
    $table->foreignId('catalog_id')->nullable()->constrained('catalogs')->onUpdate('set null')->onDelete('cascade');
    $table->timestamps();
});
```
No columns beyond the two foreign keys and timestamps — **no pivot-sync
rule is needed anywhere in this blueprint** (unlike `blueprints/channels.md`'s
`is_published`/`published_at` pair) — don't invent one. `User::catalogs()`
and `Catalog::users()` both already exist and already specify this pivot
correctly (`app-modules/users/src/Models/User.php`,
`app/Models/Catalog.php`) — reuse them as-is.

`users` table (`app-modules/users/database/migrations/0001_01_01_000000_create_users_table.php`)
has a **plain global** unique index on `email` (`$table->string('email')->unique();`)
— not scoped per subdomain. This is a pre-existing constraint this blueprint
does not change: an email must be globally unique across every Subdomain,
not just the current one. Use plain `->unique(ignoreRecord: true)` in the
form, **not** `->scopedUnique()` — using `scopedUnique` here would validate
against a narrower set than the actual DB constraint enforces and could let
a later `QueryException` surface as an ugly 500 instead of a form error.

No new resource needs a reverse "Users" relation manager on `CatalogResource`
— this blueprint's stated scope is "assign catalogs to users," one direction
only. Don't add the inverse unless asked.

## Commands

1. Install the package:
   ```
   composer require spatie/laravel-permission
   ```
2. Publish its config and migration:
   ```
   php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --no-interaction
   ```
3. Edit the published `config/permission.php`:
   ```php
   'teams' => true,
   'team_foreign_key' => 'subdomain_id',
   ```
   Verify these are the correct keys for the resolved installed version
   (step above) before proceeding — if the config shape differs, stop and
   reconcile rather than guessing.
4. Run the published migration:
   ```
   php artisan migrate
   ```
   This adds a `subdomain_id` column to `roles`, `model_has_roles`, and
   `model_has_permissions` (exact table list per the package's own stub —
   confirm against the actual published migration file, don't assume which
   tables get the column without checking). The column is a plain unsigned
   big integer with no FK — adding `->constrained('subdomains')` afterward
   in a follow-up migration is optional hardening, not required by this
   blueprint.
5. Add the `admin` role itself — do **not** build a Role-management UI for
   this (out of scope; exactly one role, created once). Add it via a
   `DatabaseSeeder` call or a one-off `php artisan tinker --execute` at
   implementation time:
   ```php
   Spatie\Permission\Models\Role::create(['name' => 'admin']);
   ```
   Because `teams` is enabled, this role creation must happen while a
   `subdomain_id` is resolvable (either explicitly passed if the package's
   installed version supports it on `Role::create()`, or with
   `PermissionRegistrar::setPermissionsTeamId()` called first) — **per
   subdomain**, an `admin` role row must exist before any user in that
   subdomain can be assigned it. Confirm the exact mechanics against the
   installed version's docs; this is the one piece of this blueprint most
   likely to need adjustment once the real version is known.
6. Add `use Spatie\Permission\Traits\HasRoles;` to
   `Modules\Users\Models\User` (`app-modules/users/src/Models/User.php`).
7. Wire the team-id resolution into the existing subdomain-identification
   middleware (`app/Http/Middleware/IdentifySubdomain.php`) — this is
   already registered on both the `web` and `api` middleware groups
   (`bootstrap/app.php`) and explicitly listed in `AdminPanelProvider`'s
   `->middleware([...])` array, so extending it here covers every request
   path without adding a new middleware class. Add, immediately after the
   existing `app()->instance('current_subdomain', $subdomainModel);` line:
   ```php
   app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($subdomainModel->id);
   ```
   On the early-return central-domain branch (where no `$subdomainModel` is
   resolved), do not call this — there is no subdomain context, so no team
   id should be set (leave the registrar's team id unset/null for that
   path, matching how `TenantScope` already no-ops when `current_subdomain`
   isn't bound).
8. Scaffold the two resources (default path resolution works here — both
   live at the app root, not a module):
   ```
   php artisan make:filament-resource User --no-interaction
   php artisan make:filament-resource Catalog --no-interaction
   ```
   Then edit the generated files to match the specifications below exactly
   — the generated scaffolding won't include the role toggle, the relation
   manager, the tenant-scoping opt-out, or the authorization overrides.
9. Add the relation manager:
   ```
   php artisan make:filament-relation-manager UserResource catalogs name --attach --no-interaction
   ```
10. Add a new navigation group in `app/Providers/Filament/AdminPanelProvider.php`,
    alongside the existing `Catalog` and `Settings` groups:
    ```php
    ->navigationGroups([
        NavigationGroup::make()->label('Catalog'),
        NavigationGroup::make()->label('Administration'),
        NavigationGroup::make()->label('Settings'),
    ])
    ```
    (Both new resources' `$navigationGroup` is set to `'Administration'` —
    see Resources below.)
11. `vendor/bin/pint --dirty --format agent`

## Models

No new models. `Spatie\Permission\Models\Role`/`Permission` are the
package's own models — do not create app-level wrappers for them. No new
columns on `Catalog` or `User` beyond what `HasRoles` and the published
permission migration add.

### Update Existing Model: User

```
Model: Modules\Users\Models\User
  Add trait: Spatie\Permission\Traits\HasRoles
```
No change to `$fillable`, `casts()`, or any existing method.

## Resources

### New Resource: UserResource

```
Resource: UserResource
  Location: App\Filament\Resources\Users\UserResource
  Docs: https://filamentphp.com/docs/4.x/resources/overview
  Model: Modules\Users\Models\User
  RecordTitleAttribute: name
  GloballySearchableAttributes: [name, email]

  Navigation:
    Group: Administration
    Icon: Filament\Support\Icons\Heroicon::OutlinedUsers
    Sort: 1

  Property: $isScopedToTenant = false
```
`$isScopedToTenant = false` is the per-resource opt-out (confirmed current
API via `search-docs`: `filament/filament@4.x`, `07-users/03-tenancy.md`,
"Disabling tenancy for all resources" — the per-resource complement is the
`$isScopedToTenant` static property mentioned there). This resource must be
reachable without first picking a Catalog tenant — `User` already carries
its own `TenantScope` (by `subdomain_id`, independent of Filament's
Catalog-tenancy), so opting out of Filament's *Catalog*-level tenant scoping
does not remove subdomain isolation; it only stops this resource from being
hidden behind "you must select a Catalog first."

**Form** (`Columns: 2` — this project's default, no override needed):

```
Field: name
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255
  Config: ->required()->maxLength(255)

Field: email
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, email, max:255, unique (global, ignoreRecord)
  Config: ->required()->email()->maxLength(255)->unique(ignoreRecord: true)

Field: password
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required on create only, confirmed, min:8
  Config:
    ->password()
    ->revealable()
    ->required(fn (string $operation): bool => $operation === 'create')
    ->confirmed()
    ->minLength(8)
    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
    ->dehydrated(fn (?string $state): bool => filled($state))
    ->visibleOn('create')

Field: password_confirmation
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required on create only (mirrors password)
  Config:
    ->password()
    ->revealable()
    ->required(fn (string $operation): bool => $operation === 'create')
    ->dehydrated(false)
    ->visibleOn('create')

Field: is_admin
  Component: Filament\Forms\Components\Toggle
  Docs: https://filamentphp.com/docs/4.x/forms/toggle
  Validation: boolean
  Config:
    ->label('Administrator')
    ->helperText('Can manage Users and Catalogs for this subdomain.')
    ->dehydrated(false)
    ->afterStateHydrated(function (Toggle $component, ?User $record): void {
        $component->state($record?->hasRole('admin') ?? false);
    })
```
`is_admin` is **not** a database column — it's a view over the user's
`admin` role, per this blueprint's "exactly one role, direct `hasRole()`
check" decision. `->dehydrated(false)` means it is excluded from the normal
`$data` array passed to `handleRecordCreation()`/the Edit page's save
pipeline; both page overrides below read the live Livewire component state
(`$this->data['is_admin']`) directly instead, which is unaffected by
`dehydrated(false)` (that flag only governs the form's *exported* state via
`getState()`, not the raw Livewire property).

**Reactive-fields consistency check**: hydration (edit) reads the role via
`afterStateHydrated`; persistence (create/edit) writes it via `syncRoles()`
in the page overrides below — both read/write the single source of truth
(`$user->hasRole('admin')` / `$user->roles`), there is no second place this
is decided, so there's no drift risk to check between stages.

**Page overrides** (required — the scaffolded Create/Edit pages do nothing
with `is_admin` by default):
```php
// Pages/CreateUser.php
protected function handleRecordCreation(array $data): Model
{
    $isAdmin = (bool) ($this->data['is_admin'] ?? false);

    $user = static::getModel()::create($data);

    $user->syncRoles($isAdmin ? ['admin'] : []);

    return $user;
}
```
```php
// Pages/EditUser.php
protected function afterSave(): void
{
    $isAdmin = (bool) ($this->data['is_admin'] ?? false);

    $this->record->syncRoles($isAdmin ? ['admin'] : []);
}
```
Both run while the request's `current_subdomain`-derived permissions team id
is already set (per Commands step 7, middleware runs before the panel
boots), so `syncRoles()` assigns the role scoped to the correct Subdomain
automatically — no explicit team id needs to be passed here.

**Table** (`ListUsers` page):
```
Column: name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: email
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: roles.name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->badge()->label('Role')->placeholder('—')
  Note: read-only display of the role(s) this user has (expected: empty or
    "admin") — editing happens via the is_admin toggle on the form, not here.

Column: catalogs_count
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->counts('catalogs')->label('Catalogs')->sortable()

Column: created_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)

Action: Edit (row)
  Component: Filament\Actions\EditAction
Action: Delete (row)
  Component: Filament\Actions\DeleteAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Behavior: no before() guard — deleting a User cascades its catalog_user
    pivot rows (per the existing onDelete('cascade') on catalog_user.user_id)
    and its model_has_roles rows (package-managed, cascades by its own FK).
    No data beyond those assignments is lost.

Bulk Action: Delete
  Component: Filament\Actions\DeleteBulkAction
```

**Pages**:
```
'index' => ListUsers::route('/')
'create' => CreateUsers::route('/create')
'edit' => EditUsers::route('/{record}/edit')
```
(No History tab for `User` — `blueprints/pim.md` §2 scopes the Activity
History convention to "one History tab per top-level Filament Resource,"
but `User` doesn't currently use `LogsActivity`'s `activitiesAsSubject`
relationship pattern consistently with the other modules here and adding it
is a separate concern from this blueprint's stated goal; skip it unless
asked.)

### RelationManagers

```
RelationManager: CatalogsRelationManager
  Location: App\Filament\Resources\Users\RelationManagers\CatalogsRelationManager
  Relationship: catalogs (belongsToMany Catalog via catalog_user, already
    defined on Modules\Users\Models\User)
  Title attribute: name
  Can create: no — Catalogs are managed via CatalogResource; this tab only
    attaches/detaches EXISTING catalogs to this User
  Can edit: no — the pivot (catalog_user) has no columns beyond the two FKs
    and timestamps; there is nothing to edit
  Can delete: no — "delete" here would delete the Catalog record itself.
    Only Detach/DetachBulk (which remove the pivot row) are offered.
```
Register in `UserResource::getRelations()`:
```php
public static function getRelations(): array
{
    return [
        CatalogsRelationManager::class,
    ];
}
```

**Table**:
```
Column: name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()
```

**Actions**:
```
Action: Attach (header)
  Component: Filament\Actions\AttachAction
  Docs: https://filamentphp.com/docs/4.x/resources/managing-relationships#attaching-and-detaching-records
  Config: ->recordSelectSearchColumns(['name'])
  Behavior: plain AttachAction, no extra schema fields (no pivot columns to
    set — unlike blueprints/channels.md's Attach, there is no is_published-
    style field here)

Action: Detach (row)
  Component: Filament\Actions\DetachAction
  Docs: (same as above)

Bulk Action: Detach
  Component: Filament\Actions\DetachBulkAction
  Docs: (same as above)
```
No explicit `->recordSelectOptionsQuery()` for tenant scoping is needed:
`Catalog` carries its own `TenantScope` (by `subdomain_id`), which already
applies to every query built against it, including the one
`AttachAction`'s record select builds internally — same reasoning as
`blueprints/channels.md`'s Attach dropdown relying on `CatalogScope`. No
explicit `->inverseRelationship()` call is needed either — unlike
`blueprints/relations.md`'s self-referential `Product`↔`Product` case
(which crashed because `Product` has no `products()` method), this is a
`User`↔`Catalog` relation and `Catalog::users()` (`app/Models/Catalog.php`)
already exists with exactly that name, so Filament's default
pluralized-class-name inverse-relationship guess (`users`) resolves
correctly. **Verify this explicitly with a test** (see Tests) rather than
assuming it from this blueprint's reasoning alone — this exact class of bug
was previously undetected until a test exercised the real search path.

### New Resource: CatalogResource

```
Resource: CatalogResource
  Location: App\Filament\Resources\Catalogs\CatalogResource
  Docs: https://filamentphp.com/docs/4.x/resources/overview
  Model: App\Models\Catalog
  RecordTitleAttribute: name
  GloballySearchableAttributes: [name]

  Navigation:
    Group: Administration
    Icon: Filament\Support\Icons\Heroicon::OutlinedBuildingOffice
    Sort: 2

  Property: $isScopedToTenant = false
```
Same reasoning as `UserResource` above: `Catalog` already carries its own
`TenantScope` (by `subdomain_id`), independent of Filament's own
Catalog-is-the-tenant scoping. Opting this resource out of Filament's tenant
scoping is required simply because `Catalog` *is* the tenant model — a
tenant-scoped resource for the tenant itself does not make sense (there is
no "currently selected Catalog" to further scope Catalog records by), and a
brand-new Subdomain may have zero Catalogs, so this resource must be
reachable before any Catalog has ever been selected.

**No special tenant-association wiring needed on create**: `Catalog`
already sets `subdomain_id` itself in `booted()`
(`static::creating(function (Catalog $catalog) { if (! $catalog->subdomain_id
&& app()->has('current_subdomain')) { $catalog->subdomain_id =
app('current_subdomain')->id; } });`) — this fires regardless of whether the
record was created through this new Resource or anywhere else, so
`CreateCatalog` needs no override.

**Interaction with the Filament tenant switcher**: creating/editing a
Catalog here changes the set of tenants a user can switch into
(`User::getTenants()` — `app-modules/users/src/Models/User.php` — queries
`$this->catalogs()` fresh on each call). No documented caching/refresh
gotcha for "a resource managing the tenant model itself" was found via
`search-docs` for the installed Filament version — this is **not
independently verified**; if the tenant switcher is observed to show stale
data after a Catalog is created/renamed/deleted through this resource during
implementation, treat that as a real finding to resolve, not something this
blueprint already ruled out.

**Superseded note**: an earlier pass of this blueprint added a
subdomain-scoped `unique()` validation directly on `name` as a stopgap for
the tenant-slug collision problem below. That is now superseded by the real
`slug` column added in "Catalog Slug" further down — `name` no longer needs
its own uniqueness rule (two Catalogs may share a display `name`; only
`slug` must be unique per Subdomain, exactly like `Channel` and `Category`
already work). Remove the `name` uniqueness rule if implementing fresh;
`name`'s only validation now is `required, max:255`.

**Form** (`Columns: 1`, superseded by the two-field form in "Catalog Slug"
below — kept here only for the parts that didn't change):
```
Field: name
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255
  Config: ->required()->maxLength(255)
```

**Table** (`ListCatalogs` page):
```
Column: name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: users_count
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->counts('users')->label('Users')->sortable()

Column: created_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)

Action: Edit (row) — Filament\Actions\EditAction
Action: Delete (row) — Filament\Actions\DeleteAction
  Behavior: no before() guard. Deleting a Catalog cascades its
    `catalog_user` pivot rows (catalog_id FK, onDelete('cascade')) and, per
    the sibling module blueprints already built on this Catalog (products,
    categories, attributes, channels, etc.), cascades or otherwise affects
    every domain record scoped to it — this is pre-existing cascade
    behavior from those blueprints, not something newly introduced here.
    Deleting a Catalog with live data in it is a destructive, irreversible
    action by the nature of the existing schema; this blueprint does not
    add a confirmation beyond Filament's default delete-action modal, and
    does not add a new guard — flag to the user if a stronger guard
    (e.g. "block if the catalog has any products") is wanted, since that
    would be a new consequential decision, not an implementation detail.

Bulk Action: Delete — Filament\Actions\DeleteBulkAction
```

**Pages**:
```
'index' => ListCatalogs::route('/')
'create' => CreateCatalogs::route('/create')
'edit' => EditCatalogs::route('/{record}/edit')
```

## Catalog Slug (fixes the tenant-URL encoding/collision bugs)

**Confirmed real bug, found via tests**: the panel's tenant URL slug is
`Catalog::name` directly (`slugAttribute: 'name'` in
`app/Providers/Filament/AdminPanelProvider.php`). Filament builds the
tenant-redirect `Location` header by raw string interpolation of that
value — it does **not** percent-encode it. A Catalog named "Spring
Collection" produces a redirect to `.../Spring Collection` (a literal,
unencoded space), confirmed empirically via a failing test
(`tests/Feature/Filament/Pages/Tenancy/RegisterCatalogTest.php`, asserting
against `rawurlencode($catalog->name)` and getting the raw string instead).
This is the same root cause behind the earlier-flagged rename-breaks-URL and
duplicate-name-collision findings — `name` is a free-text display label,
not something URL-safe, and reusing it as the slug was always going to leak
through eventually. `Channel` and `Category` already avoid this: both keep
`name` (display) and `slug` (URL-safe, `alpha_dash`, scoped-unique) as two
separate columns. `Catalog` is the one place that shortcut was taken; this
section fixes it to match.

### Migration

New migration, `database/migrations/<timestamp>_add_slug_to_catalogs_table.php`:
```php
Schema::table('catalogs', function (Blueprint $table) {
    $table->string('slug')->nullable()->after('name');
});
```
Then, in the **same migration's `up()`**, backfill every existing row:
```php
Catalog::query()->withoutGlobalScopes()->each(function (Catalog $catalog): void {
    $base = Str::slug($catalog->name);
    $slug = $base;
    $suffix = 2;

    while (
        Catalog::query()->withoutGlobalScopes()
            ->where('subdomain_id', $catalog->subdomain_id)
            ->where('slug', $slug)
            ->where('id', '!=', $catalog->id)
            ->exists()
    ) {
        $slug = $base.'-'.$suffix;
        $suffix++;
    }

    $catalog->forceFill(['slug' => $slug])->saveQuietly();
});
```
Then, in a **separate migration** run after the backfill (so the `NOT NULL`
constraint doesn't reject pre-existing rows being backfilled in the same
statement — verify this ordering concern against the installed DB driver;
SQLite/MySQL/Postgres differ on whether a single migration can safely
add-nullable, backfill, then alter-not-null in one `up()` — if the installed
driver handles it fine in one file, combining them is acceptable, but don't
assume without checking):
```php
Schema::table('catalogs', function (Blueprint $table) {
    $table->string('slug')->nullable(false)->change();
    $table->unique(['subdomain_id', 'slug']);
});
```

### Update Existing Model: Catalog

```
Model: App\Models\Catalog
  Add to $fillable: slug
  Update creating() hook: after the existing subdomain_id assignment, if
    slug is empty, derive it from name via Str::slug and resolve any
    collision within the same subdomain by appending -2, -3, ... (same
    algorithm as the migration backfill above — extract a shared private
    method/trait if that reduces duplication, implementing agent's choice).
```
This model-level fallback (not a form-level `afterStateUpdated`, unlike
`Channel`'s pattern) is deliberate: `Catalog` has **two** creation paths —
`CatalogResource`'s Create page and `RegisterCatalog`'s registration page —
and only one of them (see below) has an editable `slug` field. Putting the
derivation-with-collision-resolution in the model covers both paths
uniformly and means `RegisterCatalog`'s form can safely stay name-only.

### Update Existing Resource: CatalogResource

Replace the `name`-only form with two fields, exactly `Channel`'s pattern
in `blueprints/channels.md` (`Columns: 1`):
```
Imports:
  - Filament\Schemas\Components\Utilities\Set
  - Illuminate\Support\Str

Field: name
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255
  Config:
    ->required()
    ->maxLength(255)
    ->live(onBlur: true)
    ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
        if ($operation === 'create') {
            $set('slug', Str::slug((string) $state));
        }
    })

Field: slug
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/4.x/forms/text-input
  Validation: required, max:255, alpha_dash, unique per subdomain (ignoring current record)
  Config:
    ->required()
    ->maxLength(255)
    ->alphaDash()
    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('subdomain_id', app('current_subdomain')->id))
    ->helperText('Used in the catalog switcher URL. Lowercase letters, numbers, and dashes only.')
```
Add `slug` to the table too, right after `name`:
```
Column: slug
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->toggleable(isToggledHiddenByDefault: true)
```

### Update Existing Page: RegisterCatalog

**No form change** — stays name-only, exactly as already built. The model's
`creating()` hook (above) derives a collision-safe `slug` automatically;
adding a second slug field here would duplicate what `Channel`'s pattern
puts in the form, but `RegisterCatalog` is a first-run, minimal-friction
page by design (see its own section above) — don't add friction that isn't
needed.

### Update Panel Config

`app/Providers/Filament/AdminPanelProvider.php`:
```php
->tenant(Catalog::class, ownershipRelationship: 'catalog', slugAttribute: 'slug')
```
(was `slugAttribute: 'name'`).

### Tests (update/add)

```
CatalogResource:
  - creating a Catalog derives slug from name on create (same
    afterStateUpdated-only-on-create check as Channel's equivalent test)
  - editing an existing Catalog's name does NOT change its already-saved
    slug (same reasoning as Channel's test)
  - slug validation: required, max:255, alpha_dash, unique per subdomain
    (ignoring current record) — replaces the removed name-uniqueness tests
  - two Catalogs with the SAME name in the same Subdomain, but different
    (manually-set or auto-derived) slugs, both succeed and are both
    independently reachable as tenants

RegisterCatalog (fix the 2 currently-failing tests, don't weaken them):
  - "lands in the panel with the newly registered catalog as the active
    tenant" and "does not redirect a user who already has at least one
    catalog" should assert against $catalog->slug (not ->name) in the
    expected redirect URL — once slugAttribute is 'slug', the comparison
    naturally stops needing rawurlencode() for names containing spaces,
    since slug is alpha_dash-safe by construction; keep the assertion
    correct for whatever the real resolved value is, don't hardcode around
    today's specific symptom
  - add: registering two Catalogs with the same name (one via
    RegisterCatalog, one via CatalogResource, or both via RegisterCatalog
    in two different sessions) in the same Subdomain succeeds for both, with
    distinct auto-resolved slugs (proves the model-level collision
    resolution actually works end-to-end through this page, not just in
    isolation)
```

## Tenant Registration (closes the zero-Catalog bootstrap gap)

**Confirmed real gap, now fixed**: `$isScopedToTenant = false` only turns
off a resource's *query* scoping — it does not remove the `{tenant:name}/...`
URL prefix that Filament's tenant-enabled panel applies to every route,
including `UserResource`/`CatalogResource` above (verified via
`php artisan route:list --path=users` / `--path=catalogs` during a prior
implementation pass: both still show `{tenant:name}/users`,
`{tenant:name}/catalogs`). A Filament tenant-enabled panel requires an
active tenant to reach *any* panel page — so an admin with zero Catalogs
could not actually reach these pages to create the first one, contradicting
this blueprint's original claim that they'd be reachable regardless.

The fix is Filament's own documented mechanism for this exact situation —
confirmed via `search-docs`, `filament/filament@4.x`,
`07-users/03-tenancy.md`, "Adding a tenant registration page": *"A
registration page will allow users to create a new tenant. When visiting
your app after logging in, users will be redirected to this page if they
don't already have a tenant."* This is not a new architecture (no second
panel needed) — it's a single page class plus one panel-config line.

```
Page: RegisterCatalog
  Location: App\Filament\Pages\Tenancy\RegisterCatalog
  Extends: Filament\Pages\Tenancy\RegisterTenant
  Docs: https://filamentphp.com/docs/4.x/users/tenancy#adding-a-tenant-registration-page
```
```php
namespace App\Filament\Pages\Tenancy;

use App\Models\Catalog;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class RegisterCatalog extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register catalog';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('subdomain_id', app('current_subdomain')->id),
                    ),
            ]);
    }

    protected function handleRegistration(array $data): Catalog
    {
        $catalog = Catalog::create($data);

        $catalog->users()->attach(auth()->user());

        return $catalog;
    }
}
```
Wire it in `app/Providers/Filament/AdminPanelProvider.php`:
```php
->tenantRegistration(RegisterCatalog::class)
```

Notes:
- No `ignoreRecord` on `unique()` here (unlike `CatalogResource`'s own form)
  — registration only ever creates, never edits.
- `Catalog::create($data)` already gets `subdomain_id` auto-set via the
  model's own `creating()` hook (the same mechanism `CatalogResource`'s
  `CreateCatalog` page already relies on) — no explicit assignment needed.
- `$catalog->users()->attach(auth()->user())` is the one new write this page
  introduces, mirroring Filament's documented
  `$team->members()->attach(auth()->user())` pattern: without it, the user
  would create a Catalog they immediately can't access (zero Catalogs would
  become zero Catalogs they're a *member* of, redirecting them right back
  here).
- This does **not** auto-grant the `admin` role. Whether a user
  self-registering a brand-new Catalog this way should automatically become
  that Catalog's/Subdomain's admin is a separate, judgment-call decision
  this blueprint does not make — in the common case, the out-of-band
  bootstrap step already created the first admin User directly, so this
  page's main audience is an already-logged-in user (admin or not) spinning
  up an additional Catalog, or a second admin invited into an existing
  Subdomain who hasn't been attached to any Catalog yet. If auto-granting
  'admin' on self-registration is wanted, that needs an explicit decision
  before implementing, not an inference from this page's existence.

### Tests (add to the Tests section below)

```
RegisterCatalog:
  - a user with zero Catalogs is redirected to the registration page on
    login (not left on a blank/broken panel)
  - submitting the form creates a Catalog with subdomain_id set to the
    current Subdomain, and attaches the registering user to it
  - after registering, the user lands in the panel with that new Catalog as
    the active tenant (not redirected back to registration)
  - the name field's per-subdomain uniqueness rule applies here too (same
    discriminating same-name-different-subdomain case as CatalogResource's
    equivalent test)
  - a user who already has at least one Catalog is NOT redirected here on
    login (proves the redirect is conditional on having zero tenants, not
    unconditional)
```

## Authorization

```
Resource: UserResource
  Authorization: user has the 'admin' role (auth()->user()->hasRole('admin'))
  Applies to: viewAny, view, create, update, delete, deleteAny — single rule,
    no finer per-ability distinction requested or implemented.
  Implementation: override canViewAny()/canCreate()/canEdit()/canDelete()
    (and canDeleteAny() for the bulk action) on the Resource class directly,
    each returning the same hasRole('admin') check — per this app's
    no-Policy-class convention. Do NOT create App\Policies\UserPolicy.

Resource: CatalogResource
  Authorization: user has the 'admin' role — identical rule and
    implementation approach to UserResource above.

RelationManager: CatalogsRelationManager (on UserResource)
  Authorization: inherited — Filament only shows/allows a relation manager
    on a resource page the user can already reach, so UserResource's own
    canView()/canEdit() gate (above) already covers this. Do not add a
    second, separate hasRole() check inside the RelationManager itself; one
    canonical rule, enforced once, at the Resource level.

Unchanged: User::canAccessPanel() and User::canAccessTenant()
  (app-modules/users/src/Models/User.php) are NOT modified by this
  blueprint. Every authenticated user, admin or not, still logs into the
  panel and accesses their own Catalog-scoped resources exactly as before —
  only the 'Administration' nav group and its two resources are additionally
  gated.
```

## Tests

```
UserResource & CatalogResource — role gating:
  - a non-admin user cannot view the Users list page (assert the response
    is a 403, not just that the nav link is hidden — hitting the route
    directly bypasses any nav-only check)
  - a non-admin user cannot view the Catalogs list page (same assertion)
  - a non-admin user cannot submit a create/edit form for either resource
    via a direct Livewire component mount + call, even without visiting the
    list page first (proves the gate is on the Resource/page, not just the
    list route)
  - an admin user CAN view and fully use both resources (positive case —
    don't only test the negative)

UserResource — is_admin toggle lifecycle:
  - creating a User with is_admin checked results in that user having the
    'admin' role (assert via $user->hasRole('admin'), not just that the
    form submitted successfully)
  - creating a User with is_admin unchecked results in no role assigned
  - editing an existing admin User to uncheck is_admin removes the role
    (assert the role is actually gone, not just that save succeeded)
  - editing a non-admin User to check is_admin grants the role
  - the is_admin toggle's hydrated state on the Edit form matches the
    user's actual current role (assertSchemaComponentExists or equivalent
    state assertion — don't only test write, test read-back too)

UserResource — email uniqueness:
  - creating a user with an email that already exists in a DIFFERENT
    subdomain fails validation (proves the global, non-scoped unique rule
    from the actual DB constraint is enforced — this is the discriminating
    case that would silently pass if scopedUnique had been used instead)

Role team-scoping (the core multi-tenancy guarantee this blueprint adds):
  - a user granted 'admin' in Subdomain A does NOT have the 'admin' role
    when the permissions team context is switched to Subdomain B (assert
    via two separate requests/contexts with PermissionRegistrar's team id
    set to each subdomain in turn — not just checking the pivot row exists,
    since the team-scoped role check is what actually matters)
  - creating a second 'admin' role row scoped to a different subdomain does
    not collide with/overwrite the first (if the installed package version
    enforces a per-team unique constraint on role name, as expected)

CatalogsRelationManager (on UserResource) — tenant isolation:
  - a Catalog belonging to Subdomain B does not appear in the AttachAction's
    record select options when managing a User in Subdomain A
  - the same Catalog is also excluded from the Attach dropdown's SEARCH
    results specifically (call the Select's actual getSearchResults() /
    the exact Livewire-exposed search path, not just the unfiltered option
    list) — per the lesson from blueprints/relations.md, a crash or a
    scoping gap in this exact code path previously went undetected by tests
    that only checked direct-id submission; do not skip this case
  - submitting Subdomain B's Catalog id directly as the AttachAction's
    record (bypassing the UI) is rejected — proves the enforcement is the
    CatalogScope-derived query, not merely a UI filter

CatalogsRelationManager — attach/detach:
  - attaching a Catalog to a User makes it appear in that User's catalogs
    list and in Catalog::users_count
  - detaching removes only that one catalog_user pivot row; the Catalog
    record and any OTHER User's assignment to that same Catalog are
    untouched

Deletion:
  - deleting a User with Catalogs attached succeeds; the catalog_user pivot
    rows for that user are gone, but the Catalog record(s) themselves are
    untouched
  - deleting a Catalog with Users attached succeeds; the catalog_user pivot
    rows for that catalog are gone, but the User record(s) themselves are
    untouched
```
