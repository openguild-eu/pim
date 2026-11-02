# Media Module

## Context

This is **not** a new module and defines **no new model and no new Filament
Resource**. Per the `planning-filament` skill's "New vs Update Plans" format
(`vendor/filament/blueprint/resources/markdown/planning/overview.md` /
`models.md`), this entire document is:

- **Update Existing Model: `Modules\Products\Models\Product`** (defined in
  the sibling `blueprints/products.md`, written in parallel — treat its
  `Product` model, `products` table, and `ProductResource` as already
  authoritative; this document only layers media capability on top)
- **Update Resource: `ProductResource`** (same sibling document)

Builds on `blueprints/pim.md` §1 (two-layer multi-tenancy: `Subdomain` →
`Catalog`, `CatalogScope`, `BelongsToCatalog`, no-Policy-classes convention).
**Not** adding a §2 Activity History tab here — that convention is "one
History tab per top-level Filament Resource," and `ProductResource` already
has (or will have, per `products.md`) its own; media is a field/column on the
existing Product form and table, not a new top-level resource, so it gets no
separate History tab. Media mutations are still visible in `ProductResource`'s
existing History tab as part of whatever activity logging `products.md`
configures on `Product` (no changes needed here — Spatie Media Library does
not hook into `spatie/laravel-activitylog` on its own, and adding that wiring
is out of scope for this document since it would be a `Product`-level
decision, not a Media one).

**Scope**: give `Product` a single ordered `images` media collection (via
`spatie/laravel-medialibrary`) with a `thumb` conversion, and give
`ProductResource` an upload field and a leading thumbnail column for it (via
`filament/spatie-laravel-media-library-plugin`). No DAM-style cross-product
media reuse, no additional collections (e.g. no separate "documents"
collection) — those are explicitly deferred to a future iteration if needed.

---

## Dependencies (new — already approved, do not re-ask)

Two Composer packages are required. **Verified against Packagist/GitHub**
(not merely assumed) on 2026-11-02, against this project's actual installed
constraints (`filament/filament: ^4.0`, `laravel/framework: ^13.17`, PHP
`^8.3`, both from the root `composer.json`):

| Package | Constraint to use | Why this constraint |
| --- | --- | --- |
| `spatie/laravel-medialibrary` | `^11.0` | Latest stable is `11.23.11`. Its `require` block asks for `php: ^8.2` and `illuminate/*: ^12.0\|^13.0` — compatible with this project's PHP 8.3 and Laravel `^13.17`. A `12.x-dev`/`v12.x-dev` branch exists but is **not** a tagged stable release — do not use it. |
| `filament/spatie-laravel-media-library-plugin` | `^4.0` (do **not** use the plugin's own README default of `^5.0`) | The package's *current* default/latest line is `v5.x` (latest `v5.11.0`), which targets **Filament v5** — this project is on Filament `^4.0`, so v5 would be a mismatch. The package publishes a parallel, actively maintained `4.x` line (confirmed on GitHub: latest tag `v4.16.0`, published 2026-10-26, same day as the v5 release) whose `composer.json` requires `filament/support: self.version` (i.e. it tracks whatever major you install — `^4.0` pulls in `filament/support ^4.x`, matching `filament/filament ^4.0`) and `spatie/laravel-medialibrary: ^11.0`. **This is the point most likely to silently drift**: if anyone runs the plugin's README install command (`composer require filament/spatie-laravel-media-library-plugin:"^5.0" -W`) literally, Composer will try to pull in Filament v5 alongside this project's v4 and fail (or, worse, upgrade Filament). Always pin `^4.0` explicitly. |

Confirmed still current/correct: `filament/spatie-laravel-media-library-plugin` **is** still the right, officially-maintained package name for this integration (it has its own page at
https://filamentphp.com/plugins/filament-spatie-media-library, maintained under the `filamentphp` GitHub org) — it has not been handed off to a third party the way the Spatie Translatable plugin was (per the Filament v4 upgrade-guide notes read while preparing this document).

### Image conversion driver — no third package needed

The locked design asked whether conversions imply a third dependency
(Glide/Intervention/Imagick). **Verified: no additional Composer package is
needed.**

- `spatie/laravel-medialibrary` depends on `spatie/image: ^3.3.2` for all
  manipulation/conversion work (it does **not** depend on `league/glide` or
  `intervention/image`).
- `spatie/image` v3 supports three drivers — **Imagick (default)**, **GD**,
  and **Vips** — selected via PHP extension availability, not an additional
  Composer package (Vips is the exception: it needs the `libvips` system
  library plus a separate Composer binding, but this document does not use
  Vips).
- This project's PHP environment already has both extensions enabled —
  confirmed by running `php -m` in this environment, which lists both `gd`
  and `imagick`. **No server/infra change is required.** Imagick (the
  default) will be used automatically.

### Why no `catalog_id` / `CatalogScope` on `media` rows

The package's own migration creates a polymorphic `media` table
(`model_type`, `model_id`, plus `collection_name`, `name`, `file_name`,
`mime_type`, `disk`, `conversions_disk`, `size`, `manipulations`,
`custom_properties`, `generated_conversions`, `responsive_images`,
`order_column`, timestamps) — **no edits to that migration, no additional
migration, and no `catalog_id` column on `media`.**

Reasoning (state this explicitly so a reviewer doesn't flag it as a gap): a
`media` row is only ever reached by first loading its owning `Product` via
`$product->getMedia('images')` / the `images` relationship Media Library
adds — there is no independent "browse all media" index page or query path in
this design. `Product` already applies `CatalogScope` (via
`BelongsToCatalog`, per `products.md`), so any code path that can resolve a
`Product` has already passed tenant isolation; a `media` row can never be
fetched except through a `Product` that already passed that check. Giving
`media` its own `catalog_id` and scope would duplicate enforcement that
already happens one level up, for no additional security — same reasoning
`blueprints/pim.md` §1 uses to justify *not* adding `BelongsToCatalog` to
every model.

---

## Commands

1. Add to the root `composer.json` `require` block (next to
   `"filament/filament": "^4.0"` — exact position doesn't matter,
   `"sort-packages": true` in `config` will reorder on the next
   `composer update`):
   ```json
   "filament/spatie-laravel-media-library-plugin": "^4.0",
   "spatie/laravel-medialibrary": "^11.0",
   ```

2. Install:
   ```
   composer update filament/spatie-laravel-media-library-plugin spatie/laravel-medialibrary --no-interaction
   ```

3. Publish and run the package's own migration (creates the `media` table —
   no edits to the published file):
   ```
   php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
   php artisan migrate
   ```
   No `medialibrary-config` tag publish is required — nothing in this
   document needs a config override (the `thumb` conversion is configured
   `->nonQueued()` per-conversion in code; see Models section below, not via
   the global `queue_conversions_by_default` config key).

4. No new `discoverResources()` call, no new navigation group, no
   `ActivityLogResource::scopeEloquentQueryToTenant()` edit — none of those
   are needed because this document adds no new Resource and no new
   `LogsActivity` subject type. (If `products.md` already needs one of
   these for `Product` itself, that's its concern, not this document's.)

5. After editing `Product` and `ProductResource` (per Models/Resources
   below): `vendor/bin/pint --dirty --format agent`.

---

## Models

### Update Existing Model: `Product`

```
Migration: none — spatie/laravel-medialibrary's own published migration
  (step 3 above) is the only schema change; it is NOT scoped to Product
  specifically (model_type/model_id is polymorphic), so no Product-specific
  migration file is written for this document.

Add (implements): Spatie\MediaLibrary\HasMedia
Add (trait): Spatie\MediaLibrary\InteractsWithMedia
Add (methods): registerMediaCollections(): void
               registerMediaConversions(?Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
```

**Decided behavior — ordering/primary image**: the `images` collection uses
Media Library's built-in `order_column` (already part of the published
migration — no custom column). The default retrieval order (via
`$product->getMedia('images')`, and via `SpatieMediaLibraryImageColumn`
below) is ascending `order_column`. There is **no separate boolean/"primary"
column** — the first item in that order is treated as the product's
primary/thumbnail image. Reordering (drag-and-drop, via the form field's
`->reorderable()`) rewrites `order_column`, which is how a catalog manager
changes which image is primary.

**Decided behavior — conversion queuing**: `spatie/laravel-medialibrary`
queues conversions **by default** (verified: per Spatie's docs, a
registered conversion "will be added to the connection and queue that
you've specified in configuration," so the converted file is *not*
guaranteed to exist immediately after upload unless queuing is disabled).
For a single small `150×150` thumbnail used directly in the admin table,
waiting on a queue worker (and needing one configured/running in every
environment, including local Herd dev, for the thumbnail to ever appear) is
unnecessary overhead. **Decision: mark the `thumb` conversion
`->nonQueued()`**, so it is generated synchronously, in-request, right after
upload — the table thumbnail is always present on reload with no queue
worker dependency. Revisit this (switch to the default queued behavior) only
if thumbnail generation is later found to meaningfully slow down the
Product save request.

Add these two methods to `Product` (exact — copy as-is; everything else on
the existing `Product` class from `products.md` is unchanged):

```php
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    // ...existing BelongsToCatalog, SoftDeletes, LogsActivity, HasFactory, etc. from products.md...

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('images')
            ->fit(Fit::Crop, 150, 150)
            ->nonQueued();
    }
}
```

`addMediaCollection('images')` is left at its defaults: multiple files
allowed (no `->singleFile()`), no upper limit (no `->onlyKeepLatest()`), no
`->acceptsMimeTypes()` restriction beyond what the form field's `->image()`
already enforces client+server-side.

---

## Resources

### Update Resource: `ProductResource`

No scaffold command — `ProductResource` already exists per `products.md`.
Add one Form field and one Table column to it.

**Form** (add to the existing form schema defined in `products.md`):

```
Field: images (media relationship — not a `products` table column)
  Component: Filament\Forms\Components\SpatieMediaLibraryFileUpload
  Docs: https://filamentphp.com/plugins/filament-spatie-media-library
  Validation: nullable (no product requires at least one image; images are
    optional per this document's scope)
  Config:
    ->collection('images')
    ->conversion('thumb')
    ->multiple()
    ->reorderable()
    ->image()
  Note: `SpatieMediaLibraryFileUpload extends Filament\Forms\Components\FileUpload`
  (verified against the package source on the `4.x` branch — namespace is
  exactly `Filament\Forms\Components\SpatieMediaLibraryFileUpload`, not
  nested under a `MediaLibrary` sub-namespace as one might guess) — all
  `FileUpload` config methods (e.g. `->imageEditor()`, size limits) are also
  available if `products.md` or a later iteration wants them; not specified
  here as they're outside this document's locked scope.
```

**Table** (add as the **first/leading** column of the existing table defined
in `products.md`):

```
Column: images (leading column)
  Component: Filament\Tables\Columns\SpatieMediaLibraryImageColumn
  Docs: https://filamentphp.com/plugins/filament-spatie-media-library
  Config:
    ->collection('images')
    ->conversion('thumb')
    ->limit(1)
  Note: `SpatieMediaLibraryImageColumn extends Filament\Tables\Columns\ImageColumn`
  (verified against the package source on the `4.x` branch — namespace is
  exactly `Filament\Tables\Columns\SpatieMediaLibraryImageColumn`). `->limit(1)`
  is the base `ImageColumn`'s stacking limit — without it, the column would
  render every image in the collection stacked on top of each other; `->limit(1)`
  combined with the ascending-`order_column` retrieval documented above is
  what makes this column show only the primary (first-ordered) image.
```

---

## Authorization

```
Resource: ProductResource (media fields/columns only — the resource's own
  authorization is products.md's concern)
  Authorization: All authenticated users (no Policy class — per
  blueprints/pim.md's existing convention). Media access is governed
  entirely by whether the user can already access the owning Product: there
  is no independent media index/browse page in this design, so reaching any
  media row requires first resolving a Product, and Product already enforces
  CatalogScope + User::canAccessTenant(). No separate tenant check and no
  Policy are added for media itself — same reasoning as the "Why no
  catalog_id / CatalogScope on media rows" section above, applied to
  authorization instead of query scoping.
```

---

## Tests

These scenarios extend whatever Pest feature test file `products.md` defines
for `ProductResource` (e.g. `Modules\Products\Tests\Feature\ProductResourceTest`
— Media has no Resource of its own, so it owns no separate test file; append
these cases to that file once it exists). Use the `Product` factory from
`products.md` plus Laravel's `Illuminate\Http\UploadedFile::fake()->image(...)`
for test files — do not add a new fake-file-generation helper package;
`UploadedFile::fake()` ships with the framework already in `require-dev` via
`laravel/framework`'s testing helpers.

```
ProductResource (media additions):
  Upload & persistence:
    - uploading a fake image through the `images` SpatieMediaLibraryFileUpload
      field on Create (or Edit) and saving persists a `media` row with
      model_type/model_id pointing at the saved Product; reloading the Edit
      page's form state shows that file in the `images` field
    - the `thumb` conversion is NOT dispatched to a queue — because it is
      registered `->nonQueued()`, assert (via
      `$product->getFirstMedia('images')->hasGeneratedConversion('thumb')`)
      that it is `true` immediately after the upload request completes, with
      no queue worker run and no `Queue::fake()`/`assertPushed` needed. (This
      is the opposite assertion from "assert it's queued" — document which
      one applies: per the `->nonQueued()` decision above, assert synchronous
      availability, not dispatch.)

  Ordering / primary image:
    - uploading three images in order A, B, C: `$product->getMedia('images')`
      returns them in that same order (ascending order_column), and the
      table's leading SpatieMediaLibraryImageColumn (with ->limit(1)) shows
      A's thumbnail
    - reordering via the form field's drag-and-drop (assert against the
      saved `order_column` values after an Edit-page save, e.g. moving C
      before A) changes which image is primary: after reordering to C, B, A,
      `$product->getMedia('images')->first()->is($mediaC)` is true, and the
      table's leading column now reflects C — assert the actual persisted
      order_column values, not just a UI snapshot

  Tenant isolation (through the existing Product/CatalogScope mechanism —
  do not invent a new one):
    - a user who is a member of Catalog B cannot see a Product belonging to
      Catalog A in the product list (this is really a products.md-owned
      assertion, but repeat it here scoped to media): that user also cannot
      reach Catalog A's product's Edit page to view or attach its media — a
      direct `/products/{id}/edit` URL for a Catalog A product returns 404
      for a Catalog B member, exactly like `products.md`'s own authorization
      tests, proving media is never independently reachable once Product
      access is denied
    - attempting to call the Edit page's save with a crafted `images` upload
      against a Product record ID belonging to a different catalog never
      attaches media to that record — the record resolution itself fails
      before the media relationship is ever touched (same enforcement point
      as any other field on that page; no separate media check exists or is
      needed)

  Component Config:
    - the `images` field is `SpatieMediaLibraryFileUpload`, configured
      `->collection('images')`, `->multiple()`, `->reorderable()`, `->image()`
      (via `assertSchemaComponentExists('images', checkComponentUsing: ...)`)
    - the leading table column is `SpatieMediaLibraryImageColumn`, configured
      `->collection('images')`, `->conversion('thumb')`, `->limit(1)` (via
      `assertTableColumnExists('images', ...)`)
```
