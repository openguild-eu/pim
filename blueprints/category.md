# Categories Module

## Context

Tree-structured product categories for the PIM. Builds on the foundations in
`blueprints/pim.md` §1 (two-layer multi-tenancy) and §2 (Activity History
convention). `Category` is the **first** domain model to use
`App\Models\Concerns\BelongsToCatalog` and the **first** module with a
Filament Resource — `AdminPanelProvider` needs a new `discoverResources()`
call (see Commands).

**Scope**: Category model, tree (adjacency list via `parent_id`, unlimited
depth), CRUD resource, drag-reorder, soft delete. Product-category assignment
(many-to-many `category_product` pivot) is deferred to the Products
blueprint — it must add that relationship to `Category` and reference this
document for the tree/tenancy rules.

## Commands

1. Scaffold the module:
   ```
   php artisan make:module categories --no-interaction
   ```
   Then edit the generated `app-modules/categories/composer.json` to mirror
   `app-modules/users/composer.json` exactly in shape:
   - package name `modules/categories`
   - PSR-4: `Modules\Categories\` => `src/`, `Modules\Categories\Tests\` =>
     `tests/`, `Modules\Categories\Database\Factories\` =>
     `database/factories/`, `Modules\Categories\Database\Seeders\` =>
     `database/seeders/`
   - `extra.laravel.providers`: `Modules\Categories\Providers\CategoriesServiceProvider`

2. Add `"modules/categories": "*"` to the root `composer.json` `require`
   block (next to `"modules/users": "*"`), then:
   ```
   composer update modules/categories --no-interaction
   ```

3. **Do not** use `make:filament-resource` / `make:model` / `make:filament-page`
   for this module — their default path resolution targets `app/`, not this
   module's `src/` root, and there's no evidence `internachi/modular` patches
   that. Author every file below directly at its listed path and namespace.

4. Create `app-modules/categories/src/Providers/CategoriesServiceProvider.php`,
   matching `Modules\Users\Providers\UsersServiceProvider` exactly (empty
   `register()`, `boot()` calls `$this->loadMigrationsFrom(__DIR__.'/../../database/migrations')`).

5. In `app/Providers/Filament/AdminPanelProvider.php`, add a second resource
   discovery call (after the existing one) and a new navigation group:
   ```php
   ->discoverResources(in: base_path('app-modules/categories/src/Filament/Resources'), for: 'Modules\Categories\Filament\Resources')
   ```
   and add to the existing `navigationGroups([...])` array (before the
   `Settings` group):
   ```php
   NavigationGroup::make()->label('Catalog'),
   ```

6. In `app/Filament/Resources/ActivityLogs/ActivityLogResource.php`, extend
   `scopeEloquentQueryToTenant()`'s `where()` closure with a third branch
   (Category has a direct `catalog_id`, per the "Direct catalog_id" shape in
   `blueprints/pim.md` §2):
   ```php
   ->orWhereHasMorph('subject', [Category::class], function (Builder $categoryQuery) use ($tenant): void {
       $categoryQuery->where('catalog_id', $tenant->getKey());
   })
   ```
   (add `use Modules\Categories\Models\Category;` to the imports)

7. After creating the migration (step below): `php artisan migrate`

8. `vendor/bin/pint --dirty --format agent`

## Models

### New Model

```
Model: Category
  Namespace: Modules\Categories\Models\Category
  Table: categories
  Attributes:
    - id: bigint, primary
    - catalog_id: bigint, foreign(catalogs.id), required
    - parent_id: bigint, foreign(categories.id), nullable
    - name: string, required
    - slug: string, required
    - position: integer, required, default:0
    - created_at: timestamp
    - updated_at: timestamp
    - deleted_at: timestamp, nullable
  Indexes:
    - unique: [catalog_id, slug]
    - index: [parent_id]
  Relationships:
    - belongsTo: Catalog via catalog_id (method `catalog()` — required name,
      it's the Filament tenant ownership relationship)
    - belongsTo: Category via parent_id (method `parent()`)
    - hasMany: Category via parent_id (method `children()`, orderBy position)
  Traits:
    - App\Models\Concerns\BelongsToCatalog
    - Illuminate\Database\Eloquent\SoftDeletes
    - Spatie\Activitylog\Models\Concerns\LogsActivity
  Factory: Modules\Categories\Database\Factories\CategoryFactory
```

Migration FK modifiers: `catalog_id` — `constrained('catalogs')` (no cascade
specified; Catalog deletion isn't a defined feature yet). `parent_id` —
`nullable()->constrained('categories')->nullOnDelete()` (only reachable via
`forceDelete`, since normal delete is blocked while children exist — see
Deletion rule below).

### Model body (exact — covers the non-trivial rules; copy as-is)

```php
<?php

declare(strict_types=1);

namespace Modules\Categories\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Categories\Database\Factories\CategoryFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Category extends Model
{
    use BelongsToCatalog;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'catalog_id',
        'parent_id',
        'name',
        'slug',
        'position',
    ];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('position');
    }

    /**
     * IDs of every descendant (not including $this), via BFS over `children`.
     * Excludes already-soft-deleted descendants (default SoftDeletingScope
     * applies to the query), which is intentional: a trashed branch is not a
     * live cycle risk.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->getKey()];

        while ($frontier !== []) {
            $frontier = static::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            $category->position ??= (static::query()->where('parent_id', $category->parent_id)->max('position') ?? -1) + 1;
        });

        // Canonical enforcement of "cannot delete a category that still has
        // subcategories" — returning false cancels the delete. This is the
        // ONLY place this rule lives; the Delete actions' before() hooks
        // just re-check it early for a clean notification instead of a
        // silent no-op.
        static::deleting(function (Category $category): bool {
            return $category->children()->doesntExist();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('category')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->name;
    }
}
```

## Resources

```
Resource: CategoryResource
  Location: Modules\Categories\Filament\Resources\Categories\CategoryResource
  Docs: https://filamentphp.com/docs/4.x/resources/overview
  RecordTitleAttribute: name
  GloballySearchableAttributes: [name, slug]

  Navigation:
    Group: Catalog
    Icon: Filament\Support\Icons\Heroicon::OutlinedRectangleStack
    Sort: 1
```

**Form** (used by Create and Edit pages — `Columns: 1`):

```
Imports:
  - Filament\Schemas\Components\Utilities\Set
  - Illuminate\Support\Str

Field: parent_id
  Component: Filament\Forms\Components\Select
  Docs: https://filamentphp.com/docs/4.x/forms/select
  Validation: nullable
  Config:
    ->label('Parent category')
    ->relationship(
        name: 'parent',
        titleAttribute: 'name',
        modifyQueryUsing: function (Illuminate\Database\Eloquent\Builder $query, ?Category $record): Illuminate\Database\Eloquent\Builder {
            if (! $record) {
                return $query;
            }

            return $query->whereKeyNot([$record->getKey(), ...$record->descendantIds()]);
        },
    )
    ->searchable()
    ->preload()
  Note: no separate ->scopedExists() needed — this modifyQueryUsing is
  Filament's actual validation boundary for the submitted value (per the
  Filament tenancy docs: "Filament validates submitted values against that
  query, so records outside it are rejected"), and it already excludes
  cross-tenant records (CatalogScope applies to this query) plus the record
  itself and its descendants (cycle prevention). One rule, one place.

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
  Validation: required, max:255, alpha_dash
  Config:
    ->required()
    ->maxLength(255)
    ->alphaDash()
    ->scopedUnique(ignoreRecord: true)
    ->helperText('Used in URLs. Lowercase letters, numbers, and dashes only.')
```

Reactive-fields consistency check: `name`'s `afterStateUpdated` only writes
`slug` when `$operation === 'create'`. On Edit, changing `name` never
overwrites a slug the catalog manager may have already published elsewhere —
same rule applies whether the user types once or edits `name` repeatedly
before saving (each keystroke re-derives `slug` from the *current* `name`,
not from the original).

**Table** (`ListCategories` page):

```
Query: $table->modifyQueryUsing(fn (Builder $query) => $query->whereNull('parent_id'))
  — scopes ONLY this table's listing, not CategoryResource::getEloquentQuery().
    Edit/View routing and global search must still resolve categories at any
    depth; only the root list page is restricted to top-level rows.

Config: ->reorderable('position')->defaultSort('position')

Column: name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: slug
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->searchable()->toggleable(isToggledHiddenByDefault: true)

Column: children_count
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->counts('children')->label('Subcategories')->sortable()

Column: created_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/4.x/tables/columns/text
  Config: ->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)

Filter: TrashedFilter
  Component: Filament\Tables\Filters\TrashedFilter
  Docs: https://filamentphp.com/docs/4.x/tables/filters/ternary

Action: Delete (row)
  Component: Filament\Actions\DeleteAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Location: table row
  Authorization: all authenticated catalog members (see Authorization section)
  Behavior:
    - before(): if $record->children()->exists(), send a
      Filament\Notifications\Notification danger title "Cannot delete —
      this category still has subcategories", then $action->halt().
      (UI-level early check; the model's deleting() guard is the real
      enforcement if this is ever bypassed.)

Bulk Action: Delete
  Component: Filament\Actions\BulkActions\DeleteBulkAction
  Docs: https://filamentphp.com/docs/4.x/actions/delete
  Behavior:
    - before(): if any record in the selected Collection has
      children()->exists(), send a danger Notification naming the blocked
      categories and $action->halt() for the WHOLE bulk action — all or
      nothing, same rule as the single-row action, no partial deletes.

Standard --soft-deletes additions: RestoreAction/RestoreBulkAction,
ForceDeleteAction/ForceDeleteBulkAction (default behavior, no customization).
```

**Pages** (`getPages()`):

```
'index' => ListCategories::route('/')
'create' => CreateCategory::route('/create')
'edit' => EditCategory::route('/{record}/edit')
'subcategories' => ManageSubcategories::route('/{record}/subcategories')
'activities' => ManageCategoryActivities::route('/{record}/activities')
```

Add to `CategoryResource`:
```php
public static function getRecordSubNavigation(Page $page): array
{
    return $page->generateNavigationItems([
        EditCategory::class,
        ManageSubcategories::class,
        ManageCategoryActivities::class,
    ]);
}
```
(`Imports: Filament\Resources\Pages\Page`)

`ManageSubcategories` (`Filament\Resources\Pages\ManageRelatedRecords`):
```
Location: Modules\Categories\Filament\Resources\Categories\Pages\ManageSubcategories
Relationship: children (hasMany Category)
Title attribute: name
Navigation label: Subcategories
Can create: yes — new records get this page's record as parent_id automatically
  (ManageRelatedRecords fills the relationship's foreign key from the owner
  record; no extra config needed since `children()` is a plain hasMany)
Can edit: yes (same form fields as CategoryResource, minus `parent_id` — the
  parent is fixed to this page's record, don't show a parent picker here)
Can delete: yes (same before()-halt behavior as the main table, re-specified
  on this page's table)
Can reorder: yes — ->reorderable('position') (no modifyQueryUsing needed;
  the relationship query is already scoped to this record's direct children)
Table/Filters: same columns as the main CategoryResource table
  (name, slug, children_count, created_at) plus TrashedFilter
```

`ManageCategoryActivities` (History tab — exact pattern from
`blueprints/pim.md` §2):
```
Location: Modules\Categories\Filament\Resources\Categories\Pages\ManageCategoryActivities
extends: Filament\Resources\Pages\ManageRelatedRecords
protected static string $resource = CategoryResource::class;
protected static string $relationship = 'activitiesAsSubject';
protected static ?string $relatedResource = App\Filament\Resources\ActivityLogs\ActivityLogResource::class;
protected static ?string $navigationLabel = 'History';
protected static ?string $breadcrumb = 'History';
protected static ?string $title = 'History';
```

## Authorization

```
Resource: CategoryResource
  Authorization: All authenticated users (no Policy class — per
  blueprints/pim.md's existing convention, tenant isolation via CatalogScope
  + User::canAccessTenant() is the only guard; a user who can access the
  current catalog can fully manage its categories)
```

## Tests

```
CategoryResource:
  Authorization:
    - a user who is not a member of the current catalog gets a 404 on a
      direct /categories/{id}/edit URL for a category in that catalog
    - categories belonging to a different catalog never appear in the list,
      even if their IDs are guessed in the URL

  Validation (use dataset pattern):
    - name: required, max:255
    - slug: required, max:255, alpha_dash
    - slug must be unique within the same catalog (create two categories in
      the same catalog with the same slug — second fails; same slug in two
      DIFFERENT catalogs both succeed)

  Component Config:
    - name field's afterStateUpdated sets slug from name only when
      $operation is 'create'; editing name on an existing record does not
      change an already-saved slug
    - list table defaultSort is 'position' and ->reorderable('position') is
      enabled
    - parent_id Select excludes the record's own key and all descendant keys
      from its options (3-level fixture: root -> child -> grandchild;
      attempting to set root's parent to grandchild is rejected)

  Tree / position:
    - creating a category under a parent with existing children assigns
      position = max(sibling position) + 1
    - two categories under two DIFFERENT parents can independently hold
      position = 0 without colliding (position is scoped per-parent)

  Deletion:
    - deleting a category with no subcategories succeeds, soft-deletes it,
      and it disappears from the default (non-trashed) list
    - deleting a category with at least one non-trashed subcategory is
      blocked: assert the record still exists (not soft-deleted), a danger
      notification was sent, and no redirect occurred
    - a category whose only subcategories are already soft-deleted CAN be
      deleted (the children()->doesntExist() check only counts live rows)
    - bulk-deleting a selection where one record has children blocks the
      ENTIRE bulk action — assert none of the selected records were deleted,
      not just the blocked one
    - restoring a soft-deleted category makes it selectable again as a
      parent in the Select options

  ManageSubcategories page:
    - renders only the direct children of the given category (not
      grandchildren, not unrelated root categories)
    - creating a subcategory from this page persists with parent_id equal to
      the page's owner record
    - reordering on this page only updates position among that owner
      record's direct children

  Filters:
    - TrashedFilter, selected to "Only Trashed", shows soft-deleted root
      categories and hides live ones

  Global search:
    - searching by name finds a deeply-nested category (not just roots) and
      its result links to that category's Edit page
```
