# Channels: API Keys & Public Product API (implemented)

## Context

**This document was written after the fact.** The subsystem below was built
directly in the checkout without a prior Blueprint — this file closes that
gap by documenting it as-built, the same way `blueprints/pim.md` §1–2
document already-existing foundations. It does not redesign anything or add
new requirements beyond what's already implemented and tested.

**Scope**: per-`Channel` API keys that gate a public, read-only JSON API for
published Products. This is a **sibling, independent concern** to
`blueprints/channels.md` — that document covers only the `Channel` model,
its CRUD resource, and the `channel_product` publishing pivot. This document
covers the authentication layer and public API built on top of it. Both
stand together; neither replaces the other.

Builds on `blueprints/pim.md` §1 (two-layer multi-tenancy — the middleware
here performs its own cross-subdomain check independently of the normal
Filament-panel tenant resolution, since API requests never go through the
panel) and §2 (Activity History convention — `ApiKey` logs activity the same
way, with the secret excluded).

## Models

### Model: ApiKey (implemented)

```
Model: ApiKey
  Namespace: Modules\Channels\Models\ApiKey
  Table: api_keys
  Attributes:
    - id: bigint, primary
    - channel_id: bigint, foreign(channels.id), required, cascadeOnDelete
    - name: string, required
    - key_hash: string(64), required, unique
    - is_active: boolean, required, default:true
    - last_used_at: timestamp, nullable
    - expires_at: timestamp, nullable
    - allowed_ips: json, nullable
    - created_at: timestamp
    - updated_at: timestamp
  Relationships:
    - belongsTo: Channel via channel_id (method `channel()`)
  Traits:
    - Illuminate\Database\Eloquent\Factories\HasFactory
    - Spatie\Activitylog\Models\Concerns\LogsActivity
  Factory: Modules\Channels\Database\Factories\ApiKeyFactory
```

**Migration** — `app-modules/channels/database/migrations/2026_11_04_133812_create_api_keys_table.php`:
```php
Schema::create('api_keys', function (Blueprint $table) {
    $table->id();
    $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('key_hash', 64)->unique();
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->json('allowed_ips')->nullable();
    $table->timestamps();
});
```

**Never-store-plaintext invariant**: the plaintext key exists only transiently
in memory at creation time (see `ApiKeysRelationManager` below) and in the
request header sent by a caller. Only `sha256(plaintext)` is ever persisted,
to `key_hash`. `ApiKeyFactory` follows the same rule when generating test
fixtures — it hashes a random 64-char string and never stores the plaintext.

**Model body**:
```php
class ApiKey extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'channel_id', 'name', 'key_hash', 'is_active',
        'last_used_at', 'expires_at', 'allowed_ips',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'allowed_ips' => 'array',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('api_key')
            ->logOnly(['name', 'is_active', 'last_used_at', 'expires_at', 'allowed_ips'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->name;
    }
}
```
Per `blueprints/pim.md` §2's sensitive-field exclusion rule, `key_hash` is
deliberately absent from `logOnly()` — the activity log never records the
hash, let alone the plaintext.

### Update Existing Model: Channel (implemented)

```
Migration: none (relationship method only)
Model: Modules\Channels\Models\Channel
  Add relationship:
    - hasMany: ApiKey via channel_id (method `apiKeys()`)
```

## Authentication layer (implemented)

### Middleware: AuthenticateApiKey

```
Location: App\Http\Middleware\AuthenticateApiKey
Alias: api.key (registered in bootstrap/app.php's ->withMiddleware() via
  $middleware->alias(['api.key' => AuthenticateApiKey::class]))
```

Reads the key from `X-Api-Key` header, falling back to the standard
`Authorization: Bearer` token. Hashes it with `sha256` and looks up `ApiKey`
by `key_hash`, eager-loading `channel.catalog` with `TenantScope` explicitly
removed (`withoutGlobalScope(TenantScope::class)`) — this request path never
goes through `IdentifySubdomain`'s normal panel-tenant resolution, so the
query must be able to see across subdomains in order to then check the
correct one explicitly in the next step.

**Rejection order** (every branch returns an identical generic 401 JSON body
— `{"message": "Invalid or missing API key."}` — deliberately, so a caller
cannot distinguish "wrong key" from "right key, wrong channel" from "expired"
by response shape):
1. No token present on the request at all.
2. No matching `ApiKey` row for the hash, or it exists but `is_active` is
   false.
3. `expires_at` is set and in the past.
4. `allowed_ips` is non-empty and the request's IP isn't in the list (an
   empty/null list means "allow any IP").
5. No `current_subdomain` is bound in the container (set by
   `IdentifySubdomain`, which still runs on the `api` middleware group per
   `bootstrap/app.php`'s `$middleware->api(append: [IdentifySubdomain::class])`
   — this determines which subdomain the *request's host* belongs to,
   independent of which subdomain the key's channel belongs to).
6. The key's `channel` is missing (shouldn't happen given the FK) or
   `is_active` is false.
7. **Cross-tenant guard**: the channel's `catalog->subdomain_id` does not
   match the bound `current_subdomain`'s id — i.e. a key only authenticates
   requests made against the subdomain its own channel's catalog actually
   belongs to.

On success: `$apiKey->forceFill(['last_used_at' => now()])->save()`, then
`$request->attributes->set('channel', $channel)` for the controller to read.

### Routes (implemented)

```
Location: routes/api.php
```
```php
Route::prefix('v1')->middleware(['api.key', 'throttle:60,1'])->group(function () {
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);
});
```

### Controller: ProductController (implemented)

```
Location: App\Http\Controllers\Api\ProductController
```

Both actions resolve the authenticated `Channel` from `$request->attributes`
and scope to `$channel->products()`, filtered by **both**:
- the pivot's `is_published = true` (per-channel publishing flag, from
  `blueprints/channels.md`'s canonical pivot-sync rule), **and**
- the Product's own `status = Published` (an unpublished-on-this-channel
  pivot never overrides a Draft product, and vice versa — both gates must
  pass).

`index` paginates 50/page; `show` uses `firstOrFail()` against the same
doubly-filtered query (so a syntactically valid but invisible-to-this-channel
product id 404s, it does not leak a 403/different-shaped error). Both eager
load `categories`, `attributeSet`, `media`.

### API Resource: ProductResource (implemented)

```
Location: App\Http\Resources\ProductResource (JsonResource)
```
Distinct from the Filament `ProductResource` (different namespace, different
purpose). Shape:
```php
[
    'id', 'sku', 'name', 'slug', 'status' => $this->status->value,
    'description',
    'price' => cents-to-decimal (null stays null, else round($price / 100, 2)),
    'attribute_set' => name only, via whenLoaded('attributeSet'),
    'categories' => names only, via whenLoaded('categories'),
    'images' => via whenLoaded('media'), filtered to the 'images' collection,
        each entry ['url' => $media->getUrl(), 'thumb_url' => $media->getUrl('thumb')],
    'channel' => via whenPivotLoaded('channel_product'),
        ['is_published' => (bool), 'published_at'],
    'created_at', 'updated_at',
]
```

## Resources (Filament — managing keys)

### Update Existing Resource: ChannelResource

```
RelationManager: ApiKeysRelationManager
  Location: Modules\Channels\Filament\Resources\Channels\RelationManagers\ApiKeysRelationManager
  Relationship: apiKeys (hasMany ApiKey, defined above on Channel)
  Title: API Keys
```
Registered in `ChannelResource::getRelations()`.

**Form** (`Columns: 1`):
```
Field: name
  Component: Filament\Forms\Components\TextInput
  Validation: required, max:255
  Config: ->required()->maxLength(255)

Field: is_active
  Component: Filament\Forms\Components\Toggle
  Config: ->default(true)

Field: expires_at
  Component: Filament\Forms\Components\DateTimePicker
  Validation: nullable

Field: allowed_ips
  Component: Filament\Forms\Components\TagsInput
  Validation: nullable, array
  Config: ->helperText('Leave empty to allow any IP address.')
```
Note `key_hash` has no form field at all — it is never editable, only
generated (see Action: Create below).

**Table**:
```
Column: name — Filament\Tables\Columns\TextColumn — ->searchable()->sortable()
Column: is_active — Filament\Tables\Columns\IconColumn — ->boolean()->sortable()
Column: last_used_at — Filament\Tables\Columns\TextColumn — ->dateTime()->placeholder('Never')->sortable()
Column: expires_at — Filament\Tables\Columns\TextColumn — ->dateTime()->placeholder('Never')->sortable()
```

**Actions**:
```
Action: Create (header)
  Component: Filament\Actions\CreateAction
  Behavior:
    1. ->mutateDataUsing(): generate Str::random(64) as the plaintext key,
       set $data['key_hash'] = hash('sha256', $plaintext), capture the
       plaintext in a closure-scoped variable (never persisted anywhere
       itself).
    2. ->after(): show a persistent Filament notification containing the
       plaintext key once. Closing/dismissing it is the only way to see it
       — there is no "view key" action afterward, by design (only key_hash
       is ever stored, so it's not retrievable even by the record's own
       owner).

Action: Edit (row) — Filament\Actions\EditAction — edits name/is_active/
  expires_at/allowed_ips only, never key_hash.

Action: Delete (row) — Filament\Actions\DeleteAction
Bulk Action: Delete — Filament\Actions\DeleteBulkAction
```

## Authorization (implemented)

```
Resource: ApiKeysRelationManager (on ChannelResource)
  Authorization: All authenticated catalog users — same "no Policy class"
  convention as the rest of this application (CatalogScope + tenant
  membership is the only guard); a user who can manage a Channel can fully
  manage its API keys.

Public API (routes/api.php):
  Authorization: no Laravel auth guard — authenticated entirely by
  AuthenticateApiKey's key-hash lookup + the cross-tenant/active/expiry/
  IP-allowlist checks above. Rate-limited at 60 requests/minute per
  throttle:60,1.
```

## Tests (implemented)

```
tests/Feature/Api/AuthenticateApiKeyTest.php:
  - 401 when no key is provided
  - 401 for a bogus key
  - 401 for an inactive key
  - 401 for an expired key
  - 401 when the request IP isn't in a non-empty allowed_ips list
  - 401 on the central domain even with an otherwise-valid key
  - 401 when a valid key is used against a DIFFERENT channel's subdomain
    (cross-tenant guard)
  - 401 for a key belonging to an inactive channel
  - 200 for a valid key via the X-Api-Key header
  - 200 for a valid key via an Authorization bearer token
  - a successful request touches last_used_at to the current time
    (Carbon::setTestNow pinned, then asserted exactly)

tests/Feature/Api/ProductControllerTest.php:
  - excludes a product never attached to the authenticated channel
  - excludes a product whose channel_product pivot has is_published=false
  - excludes a Draft product even when its channel pivot is_published=true
  - excludes a soft-deleted product even when its channel pivot is_published=true
  - paginates at 50/page (55 created, asserts 50 returned + meta.total=55)
  - show returns 404 for a product invisible to this channel (any of the
    above exclusion reasons)
  - returns the full resource shape for a visible product: id/sku/price
    (cents-to-decimal)/attribute_set name/categories.0 name/channel.is_published/
    exactly one image with both url and thumb_url present
```

No scope beyond the above is covered by this subsystem today (e.g. no key
rotation/regeneration action, no per-key scoping to a subset of Products, no
usage-count/analytics). If any of that is wanted, scope it as a new addendum
to this file rather than assuming it from the name "API Keys."
