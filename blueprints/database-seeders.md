# Database Seeders — All Modules

## Goal

Give every module (`users`, `categories`, `attributes`, `products`, `channels`, `relations`) a seeder that produces a realistic, internally-consistent demo catalog, wired together from the root `DatabaseSeeder`. Root-level tenancy (`Subdomain`, `Catalog`, admin `User`, `admin` role) already exists in `database/seeders/DatabaseSeeder.php` and stays as-is.

## Dependency order (from FKs / unique constraints)

`Subdomain` → `Catalog` → `users` → `categories` → `attributes` (sets + attributes) → `products` (+ attribute values + category attach) → `channels` (+ product attach + api keys) → `relations` (product_relations).

Everything below is scoped to **one `Catalog`** (the same `$catalog` created in `DatabaseSeeder`). `Category`/`AttributeSet`/`Attribute`/`Product`/`Channel`/`ProductRelation` all carry `catalog_id` and must stay within that one catalog — the various unique indexes (`[catalog_id, slug]`, `[catalog_id, sku]`, etc.) and `CatalogScope` assume that.

## Wiring (root `database/seeders/DatabaseSeeder.php`)

After the existing admin bootstrap (`$subdomain`, `$catalog`, admin `$user`, `admin` role — unchanged), add:

```php
$this->callWith(\Modules\Users\Database\Seeders\UsersSeeder::class, ['catalog' => $catalog]);
$this->callWith(\Modules\Categories\Database\Seeders\CategoriesSeeder::class, ['catalog' => $catalog]);
$this->callWith(\Modules\Attributes\Database\Seeders\AttributesSeeder::class, ['catalog' => $catalog]);
$this->callWith(\Modules\Products\Database\Seeders\ProductsSeeder::class, ['catalog' => $catalog]);
$this->callWith(\Modules\Channels\Database\Seeders\ChannelsSeeder::class, ['catalog' => $catalog]);
$this->callWith(\Modules\Relations\Database\Seeders\RelationsSeeder::class, ['catalog' => $catalog]);
```

`Seeder::callWith()` (Laravel ≥9, confirmed present in installed `laravel/framework v13.39.0`) resolves each seeder's `run()` extra parameters through the container, so each module seeder below declares `run(Catalog $catalog): void`.

Do **not** add `WithoutModelEvents` to the module seeders (unlike the root seeder) — `Category::booted()` (auto `position`) and `ProductRelation::booted()` (auto `position`) rely on model events firing.

Generate each module seeder file with the module's own `make:seeder --module` command (per the `modular` skill), then fill in the logic described below:

```bash
php artisan make:seeder UsersSeeder --module=users --no-interaction
php artisan make:seeder CategoriesSeeder --module=categories --no-interaction
php artisan make:seeder AttributesSeeder --module=attributes --no-interaction
php artisan make:seeder ProductsSeeder --module=products --no-interaction
php artisan make:seeder ChannelsSeeder --module=channels --no-interaction
php artisan make:seeder RelationsSeeder --module=relations --no-interaction
```

These land in `app-modules/<module>/database/seeders/<Name>Seeder.php`, namespace `Modules\<Module>\Database\Seeders`.

## Module seeders

### `Modules\Users\Database\Seeders\UsersSeeder`

`run(Catalog $catalog): void`

- Create 4 additional demo users with `User::factory()->count(4)->create(['subdomain_id' => $catalog->subdomain_id])` (factory already generates unique email/name; do not assign the `admin` role — per `blueprints/administration.md` only the bootstrap admin needs it).
- Attach all 4 to `$catalog` via `$catalog->users()->attach($users)` (or loop `$user->catalogs()->attach($catalog)`).

### `Modules\Categories\Database\Seeders\CategoriesSeeder`

`run(Catalog $catalog): void`

- Create 3 root categories: `Category::factory()->count(3)->create(['catalog_id' => $catalog->id, 'parent_id' => null])`.
- For each root, create 2–3 children with `'parent_id' => $root->id, 'catalog_id' => $catalog->id`. Leave `position` to the model's `booted()` auto-assignment (don't pass it).
- Result: ~9–12 categories, two levels deep.

### `Modules\Attributes\Database\Seeders\AttributesSeeder`

`run(Catalog $catalog): void`

- Create 2 `AttributeSet`s, e.g. `AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'General', 'slug' => 'general'])` and one more (e.g. `'Apparel'`/`'apparel'`).
- Create attributes covering every `AttributeType` case so `ProductsSeeder` can exercise every `value_*` column:
  - text: `code: 'material', label: 'Material', type: text`
  - number: `code: 'weight', label: 'Weight (g)', type: number`
  - boolean: `code: 'is_featured', label: 'Featured', type: boolean`
  - date: `code: 'release_date', label: 'Release Date', type: date`
  - select: use the `select()` factory state, e.g. `code: 'color', label: 'Color'`
  - multiselect: use the `multiselect()` factory state, e.g. `code: 'tags', label: 'Tags'`
  - All with `catalog_id => $catalog->id`.
- Attach a sensible subset of attributes to each `AttributeSet` through the `attribute_set_attributes` pivot with `position` (0-based, in attachment order) and `is_required` (e.g. `material`/`color` required, rest optional):
  ```php
  $generalSet->attributes()->attach([
      $material->id => ['position' => 0, 'is_required' => true],
      $color->id => ['position' => 1, 'is_required' => true],
      $weight->id => ['position' => 2, 'is_required' => false],
      ...
  ]);
  ```

### `Modules\Products\Database\Seeders\ProductsSeeder`

`run(Catalog $catalog): void`

- Load the attribute sets created above: `AttributeSet::where('catalog_id', $catalog->id)->get()`.
- Create ~24 products: for each, `Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSets->random()->id])`; use the `published()` state for roughly 2/3 of them (e.g. every product where `$i % 3 !== 0` uses `->published()`), leave the rest at the factory's default `draft`.
- Attach 1–3 random `Category` (same catalog) to each product via `$product->categories()->attach(...)`.
- For every product that has an `attribute_set_id`, create one `AttributeValue` per attribute in that set (respecting the `[product_id, attribute_id]` unique constraint — one row per pair). `AttributeValue` has **no factory**, so build manually, e.g.:
  ```php
  foreach ($attributeSet->attributes as $attribute) {
      AttributeValue::create([
          'catalog_id' => $catalog->id,
          'product_id' => $product->id,
          'attribute_id' => $attribute->id,
          ...match ($attribute->type) {
              AttributeType::Text, AttributeType::Textarea => ['value_text' => fake()->words(3, true)],
              AttributeType::Number => ['value_integer' => fake()->numberBetween(1, 5000)],
              AttributeType::Boolean => ['value_boolean' => fake()->boolean()],
              AttributeType::Date => ['value_date' => fake()->date()],
              AttributeType::Select => ['value_text' => fake()->randomElement($attribute->options ?? ['red', 'blue'])],
              AttributeType::Multiselect => ['value_text' => implode(',', fake()->randomElements($attribute->options ?? ['red', 'blue'], 2))],
          },
      ]);
  }
  ```
  (Confirm the exact `AttributeType` enum case names and the `Attribute::options` shape by reading the model/enum before writing this — the sketch above is illustrative, not literal.)

### `Modules\Channels\Database\Seeders\ChannelsSeeder`

`run(Catalog $catalog): void`

- Create 3 `Channel`s for the catalog (e.g. `Online Store`, `Marketplace`, `Wholesale`), `is_active => true`.
- For each channel, attach a random subset of **published** products from this catalog via the `channel_product` pivot with `is_published => true, published_at => now()->subDays(random)` for most, and a couple attached with `is_published => false, published_at => null` to show the unpublished state.
- Create 1 `ApiKey` per channel via `ApiKey::factory()->create(['channel_id' => $channel->id])`.

### `Modules\Relations\Database\Seeders\RelationsSeeder`

`run(Catalog $catalog): void`

- Load this catalog's products.
- For ~8 products, create 1–2 `ProductRelation` rows per `ProductRelationType` case (`Related`, `CrossSell`, `UpSell`) pointing at a different random product in the same catalog. Always set `catalog_id => $catalog->id` explicitly (don't rely on `booted()`'s `Filament::getTenant()` fallback, which won't be bound during seeding). Respect the unique constraint `[product_id, related_product_id, type]` — skip/retry on collision rather than looping forever (e.g. pick `related_product_id` from the candidate list minus already-used ones for that `[product_id, type]`). Leave `position` unset so `booted()` auto-assigns it.

## Verification (execution agent must run before reporting back)

1. `vendor/bin/pint --dirty --format agent`
2. `php artisan migrate:fresh --seed` against the local Herd-served DB and confirm it completes without errors.
3. Spot-check row counts via `database-query` (Boost MCP) or `php artisan tinker --execute`, e.g. confirm `Product::count()`, `AttributeValue::count()`, `ProductRelation::count()` are non-zero and consistent with the plan above.
4. Run any existing Pest tests that touch these models/factories (e.g. `tests/Feature/Filament/**`) to confirm nothing regressed — `php artisan test --compact app-modules` and the root `tests/Feature` suite relevant to Catalog/User.
5. This is seed data, not application behavior — no new Pest tests are required for the seeders themselves unless a bug is found that indicates a model-level issue.

## Out of scope

- No multi-tenant (multiple `Subdomain`/`Catalog`) seeding — single demo catalog only, matching the existing root seeder's scope.
- No changes to factories, models, or migrations unless a factory is missing a field that blocks seeding (flag it instead of silently reshaping a model).
- No Filament UI changes.
