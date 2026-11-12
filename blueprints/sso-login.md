# Blueprint: SSO Login (Generic OIDC, Per-Tenant)

## Summary

Add Single Sign-On login to the `admin` Filament panel using generic OpenID
Connect (OIDC), so a user on `acme.pim.test/login` can authenticate against
their own company's identity provider (Okta, Entra ID, Google Workspace,
Auth0, Keycloak, etc.) instead of (or in addition to) a local password.

Decisions already made (do not re-ask; implement exactly as specified):

1. **Protocol**: generic OIDC via `socialiteproviders/openidconnect`. Not
   SAML, not a single hard-coded vendor.
2. **Tenant scope**: per-tenant IdP config. Each `Subdomain` row stores its
   own issuer/client id/client secret. There is no global IdP config.
3. **Login flow**: SSO is **alongside** password login, not a replacement.
   The existing email/password form stays; an "Log in with SSO" link is
   added, visible only when the current tenant has SSO configured.
4. **SSO settings UI**: a self-service custom Filament page inside the
   existing `admin` panel (already tenant-scoped via `IdentifySubdomain`),
   gated by a `manage sso settings` permission (spatie/laravel-permission,
   teams already enabled and scoped by `subdomain_id` — see
   `App\Http\Middleware\IdentifySubdomain::handle()`, which calls
   `app(PermissionRegistrar::class)->setPermissionsTeamId($subdomainModel->id)`).
5. **User provisioning**: SSO **never auto-creates** users. If no `User` row
   matches the IdP's email within the current tenant, the login is rejected
   with an error — the account must already exist (e.g. created by an admin
   or invited some other way; that flow is out of scope here).
6. **Email trust**: the login is rejected unless the IdP's ID token asserts
   `email_verified = true`. An email claim without that assertion must never
   be used to match or log in a user.
7. **MFA interaction**: a successful SSO login **supersedes** the panel's
   local app-authentication MFA (`AppAuthentication::make()` in
   `AdminPanelProvider`). SSO logins log the user in directly via the guard
   and never go through Filament's `Login::authenticate()` MFA challenge.
   This is a deliberate security posture (the external IdP is treated as a
   sufficiently strong authentication event), not an oversight — do not add
   an MFA challenge to the SSO callback.

## Why this deviates from the generic "use spatie/laravel-settings" guidance

The planning guideline for settings pages defaults to
`spatie/laravel-settings` for app-wide settings classes. That package is not
installed in this project (`composer show spatie/laravel-settings` → not
found), and more importantly the SSO config here is **per-tenant row data**
that already has a natural home: the existing `Subdomain` model. Bolting on
a settings-class package to store four columns that belong on an existing
Eloquent model would be the wrong abstraction. Instead, add columns directly
to `subdomains` and build a plain custom Filament page whose form reads from
and writes to the current tenant's `Subdomain` record.

## Package and verified facts about `socialiteproviders/openidconnect`

- Composer package: `socialiteproviders/openidconnect` (pulls in
  `laravel/socialite` and `socialiteproviders/manager` as dependencies).
- A single generic issuer can be configured **without** the package's own
  `config/oidc.php` connections file, by placing an `openidconnect` array
  under `config/services.php` with keys `base_url` (the issuer — discovery
  is fetched from `{base_url}/.well-known/openid-configuration`),
  `client_id`, `client_secret`, `redirect`.
- Registration: in a service provider's `boot()`, listen for
  `SocialiteProviders\Manager\SocialiteWasCalled` and call
  `$event->extendSocialite('openidconnect', \SocialiteProviders\OpenIDConnect\Provider::class)`.
- PKCE and nonce are **on by default**; state is always validated. The
  package validates the ID token's signature, `iss`, `aud`, `azp`, `exp`,
  `nonce`, and `at_hash` itself — do not re-implement any of that.
- There is **no documented runtime `setConfig()`-style method** for
  per-tenant dynamic config. The documented pattern is one static connection
  per IdP. To get per-tenant config, set `config(['services.openidconnect' =>
  [...]])` to the current tenant's values **before every call** to
  `Socialite::driver('openidconnect')` — both on the redirect leg and the
  callback leg, since each is a separate HTTP request with a freshly built
  container under standard (non-Octane) PHP-FPM, which is how this app runs.
  **Caveat to carry forward**: if this app is ever moved to a long-running
  worker runtime (Laravel Octane), this dynamic-`config()` pattern becomes
  unsafe (a cached Socialite driver instance could leak one tenant's client
  secret into another tenant's request) and would need revisiting. Note this
  in a comment where the config is set.
- The nonce/PKCE verifier are stored in the session between redirect and
  callback, so both legs must run through the `web` middleware group
  (session + cookies). They already do — see below.

## Commands

Run in this order:

```
composer require socialiteproviders/openidconnect --no-interaction
php artisan make:migration add_sso_fields_to_subdomains_table --no-interaction
php artisan make:controller SsoController --no-interaction
php artisan make:filament-page ManageSsoSettings --no-interaction
php artisan make:test --pest SsoLoginTest --no-interaction
php artisan make:test --pest ManageSsoSettingsTest --no-interaction
```

## Models

### Update Existing Model: Subdomain

```
Migration: add_sso_fields_to_subdomains_table
  Table: subdomains
  Add: sso_enabled (boolean, default:false)
  Add: sso_issuer (string, nullable)
  Add: sso_client_id (string, nullable)
  Add: sso_client_secret (text, nullable)
```

Update `app/Models/Subdomain.php`:

- Add to `$fillable`: `sso_enabled`, `sso_issuer`, `sso_client_id`,
  `sso_client_secret`.
- Add a `casts()` method (this model currently has none) returning:
  ```php
  return [
      'sso_enabled' => 'boolean',
      'sso_client_secret' => 'encrypted',
  ];
  ```
  Follow the existing convention from `Modules\Users\Models\User::casts()`,
  which already uses `'app_authentication_secret' => 'encrypted'` for a
  single confidential string field — same pattern here.
- Add a method:
  ```php
  public function hasSsoConfigured(): bool
  {
      return $this->sso_enabled
          && filled($this->sso_issuer)
          && filled($this->sso_client_id)
          && filled($this->sso_client_secret);
  }
  ```
  This is the **single canonical rule** for "is SSO usable for this tenant
  right now" — reuse it everywhere (login page button visibility, the
  `/sso/redirect` route guard, and the settings page), never re-derive it
  independently.

Do **not** add `sso_client_id` or `sso_issuer` to `$hidden` — they are not
secret (issuer URL and client id are not confidential; only the client
secret is). Do add `sso_client_secret` to `$hidden` alongside `password`,
`remember_token`, etc., so it never serializes.

## Permission seeding

`config/permission.php` already has `'teams' => true`, and roles are already
scoped per-tenant (see `DatabaseSeeder`: `Role::create(['name' => 'admin',
'subdomain_id' => $subdomain->id])`). The `permissions` table itself is not
team-scoped (only role/model assignments are) — a permission only needs to
be created once, then granted to whichever tenant-scoped role should have
it.

Update `database/seeders/DatabaseSeeder.php`:

- Import `Spatie\Permission\Models\Permission`.
- Immediately after `Role::create(['name' => 'admin', 'subdomain_id' =>
  $subdomain->id])` and the `setPermissionsTeamId($subdomain->id)` call,
  add:
  ```php
  $role->givePermissionTo(Permission::findOrCreate('manage sso settings'));
  ```
  (capture `Role::create(...)` into a `$role` variable — currently its
  return value is discarded).

This mirrors the existing bootstrap pattern exactly; no new seeding
infrastructure is introduced.

## Routes & Controller

`bootstrap/app.php` already appends `IdentifySubdomain::class` to the global
`web` middleware group, so **any** plain route in `routes/web.php`
automatically gets subdomain resolution, `URL::forceRootUrl()` to the
correct tenant host, and the `current_subdomain` container binding — no
extra middleware needs to be attached to these routes.

Add to `routes/web.php` (outside the existing `Route::domain(...)` central
domain group — these must work on tenant subdomains, not the central
domain):

```php
use App\Http\Controllers\SsoController;

Route::get('/sso/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
Route::get('/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
```

### Controller: App\Http\Controllers\SsoController

Location: `app/Http/Controllers/SsoController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subdomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Modules\Users\Models\User;
use Symfony\Component\HttpFoundation\Response;

class SsoController extends Controller
{
    public function redirect(): Response
    {
        $subdomain = $this->currentSsoConfiguredSubdomain();

        $this->configureDriver($subdomain);

        return Socialite::driver('openidconnect')->redirect();
    }

    public function callback(): RedirectResponse
    {
        $subdomain = $this->currentSsoConfiguredSubdomain();

        $this->configureDriver($subdomain);

        try {
            $oauthUser = Socialite::driver('openidconnect')->user();
        } catch (\Throwable) {
            return redirect()
                ->route('filament.admin.auth.login')
                ->with('sso_error', __('Unable to sign in with SSO. Please try again or use your password.'));
        }

        $emailVerified = (bool) ($oauthUser->user['email_verified'] ?? false);

        if (blank($oauthUser->getEmail()) || ! $emailVerified) {
            return redirect()
                ->route('filament.admin.auth.login')
                ->with('sso_error', __('Your identity provider did not confirm a verified email address.'));
        }

        $user = User::query()
            ->where('email', $oauthUser->getEmail())
            ->where('subdomain_id', $subdomain->id)
            ->first();

        if (! $user) {
            return redirect()
                ->route('filament.admin.auth.login')
                ->with('sso_error', __('No account was found for your email address.'));
        }

        Auth::guard('web')->login($user);

        request()->session()->regenerate();

        return redirect()->intended(route('filament.admin.pages.dashboard'));
    }

    private function currentSsoConfiguredSubdomain(): Subdomain
    {
        abort_unless(app()->has('current_subdomain'), 404);

        /** @var Subdomain $subdomain */
        $subdomain = app('current_subdomain');

        abort_unless($subdomain->hasSsoConfigured(), 404);

        return $subdomain;
    }

    /**
     * Dynamic, per-tenant Socialite config. Safe under standard PHP-FPM
     * (fresh container per request). Would need revisiting under Octane,
     * where a cached driver instance could leak one tenant's secret into
     * another tenant's request.
     */
    private function configureDriver(Subdomain $subdomain): void
    {
        config([
            'services.openidconnect' => [
                'base_url' => $subdomain->sso_issuer,
                'client_id' => $subdomain->sso_client_id,
                'client_secret' => $subdomain->sso_client_secret,
                'redirect' => route('sso.callback'),
            ],
        ]);
    }
}
```

Notes for the implementing agent:

- Verify the exact Socialite facade import path and the `$oauthUser->user`
  raw-claims property name against the installed `laravel/socialite` and
  `socialiteproviders/openidconnect` versions pulled in by Composer (check
  with `composer show laravel/socialite` and read the installed
  `SocialiteProviders\OpenIDConnect\Provider` source for exactly how it
  exposes the raw ID token / userinfo claims, since `email_verified` must be
  read from there, not guessed).
- `filament.admin.auth.login` and `filament.admin.pages.dashboard` are
  Filament's generated route names for panel id `admin` (`->id('admin')`);
  confirm with `php artisan route:list --name=filament.admin` before using
  them.
- Both routes rely on **the same canonical tenant resolution** used
  everywhere else in the app: `app('current_subdomain')`, bound once per
  request by `IdentifySubdomain`. Do not re-resolve the tenant from the host
  independently inside this controller.

### Update Existing Middleware: IdentifySubdomain

For defense in depth and consistency with the existing central-domain block
list (`login`, `logout`, `filament*`), update
`app/Http/Middleware/IdentifySubdomain.php`'s central-domain branch to also
block the new SSO routes:

```php
if ($request->is('login') || $request->is('logout') || $request->is('sso/*') || str_starts_with($request->path(), 'filament')) {
    abort(404);
}
```

This is belt-and-suspenders: `SsoController`'s own guard already 404s when
`current_subdomain` isn't bound (which is the case on the central domain),
so this change doesn't alter behavior, it just rejects earlier/consistently
with the sibling routes.

## Service provider registration

Update `app/Providers/AppServiceProvider.php` `boot()` (read the file first
to add alongside whatever is already there, don't replace it):

```php
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\OpenIDConnect\Provider as OpenIDConnectProvider;

Event::listen(function (SocialiteWasCalled $event): void {
    $event->extendSocialite('openidconnect', OpenIDConnectProvider::class);
});
```

## Login page (custom page overriding `->login()`)

The default login route action is `Filament\Auth\Pages\Login::class`
(`Filament\Panel\Concerns\HasAuth::login()`, default parameter). Create a
custom page that extends it and adds the SSO link, rather than rebuilding
the whole login page.

```
Page: Login
  Location: App\Filament\Pages\Auth\Login
  Docs: https://filamentphp.com/docs/4.x/users/overview (authentication features)
  Extends: Filament\Auth\Pages\Login
```

Content (`app/Filament/Pages/Auth/Login.php`):

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

class Login extends \Filament\Auth\Pages\Login
{
    /**
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            Action::make('sso')
                ->label('Log in with SSO')
                ->link()
                ->url(route('sso.redirect'))
                ->visible(fn (): bool => app()->has('current_subdomain')
                    && app('current_subdomain')->hasSsoConfigured()),
        ];
    }
}
```

Register it in `app/Providers/Filament/AdminPanelProvider.php`: change

```php
->login()
```

to

```php
->login(\App\Filament\Pages\Auth\Login::class)
```

If `session('sso_error')` is set (flashed by `SsoController::callback()` on
failure), surface it as a Filament notification on page load. Add to the
same `Login` page class:

```php
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Unexposed;

#[Unexposed]
public function mount(): void
{
    parent::mount();

    if ($error = session('sso_error')) {
        Notification::make()
            ->title($error)
            ->danger()
            ->send();
    }
}
```

(`mount()` is marked `#[Unexposed]` on the parent — verify this attribute
still applies/compiles correctly when overridden in the installed Filament
version; if it causes issues, move the notification dispatch into
`getSubheading()` or a render hook instead, but keep it off the critical
auth path either way.)

## SSO settings page (tenant self-service)

```
Page: ManageSsoSettings
  Command: php artisan make:filament-page ManageSsoSettings --no-interaction
  Location: App\Filament\Pages\ManageSsoSettings
  Docs: https://filamentphp.com/docs/4.x/navigation/custom-pages

  Navigation:
    Group: Settings
    Icon: Heroicon::OutlinedShieldCheck
    Label: SSO

  Authorization:
    canAccess(): user has 'manage sso settings' permission
```

Follow the existing custom-page conventions from
`app/Filament/Pages/NeedsAttention.php` (`declare(strict_types=1)`,
`protected static string|\BackedEnum|null $navigationIcon`, `protected
static string|\UnitEnum|null $navigationGroup = 'Settings'`).

Content:

- Not a resource — this is a single-record settings-style form bound to
  `app('current_subdomain')` (the tenant's own `Subdomain` row), not to a
  user-selectable record. Use Filament's schema/form components directly
  (not `InteractsWithRecord` — there's no route `{record}` parameter; the
  record is always "the current tenant").
- `public function mount(): void` loads `app('current_subdomain')` into
  `$this->record` (a plain property, not a route-bound record) and fills the
  form from it via `$this->form->fill($this->record->only([...]))`.
- Form schema (`Filament\Schemas\Schema` via `form(Schema $schema): Schema`):
  - **Field**: Component `Filament\Forms\Components\Toggle`, name
    `sso_enabled`, Docs:
    https://filamentphp.com/docs/4.x/forms/toggle, Validation: boolean,
    Config: `->label('Enable SSO')`
  - **Field**: Component `Filament\Forms\Components\TextInput`, name
    `sso_issuer`, Docs: https://filamentphp.com/docs/4.x/forms/text-input,
    Validation: `nullable`, `url`, `required_if:sso_enabled,true`, Config:
    `->label('Issuer URL')->url()->helperText('e.g. https://your-idp.example.com')`
  - **Field**: Component `Filament\Forms\Components\TextInput`, name
    `sso_client_id`, Docs: https://filamentphp.com/docs/4.x/forms/text-input,
    Validation: `nullable`, `string`, `max:255`,
    `required_if:sso_enabled,true`, Config: `->label('Client ID')`
  - **Field**: Component `Filament\Forms\Components\TextInput`, name
    `sso_client_secret`, Docs:
    https://filamentphp.com/docs/4.x/forms/text-input, Validation:
    `nullable`, `string`, `required_if:sso_enabled,true`, Config:
    `->label('Client secret')->password()->revealable()->dehydrated(fn ($state) => filled($state))`
    — **critical**: use `dehydrated()` so that leaving the field blank on an
    edit (to keep the existing secret unchanged) does not overwrite the
    stored secret with an empty string. This is the one place in this
    feature where "unchanged save" must be explicitly tested (see Tests).
- **Action**: Component `Filament\Actions\Action`, Docs:
  https://filamentphp.com/docs/4.x/actions/overview, Location: page header
  or form footer, Visibility: always (page itself is gated by `canAccess()`),
  Authorization: none additional (page-level check already applies),
  Behavior: validate form, then update `app('current_subdomain')` with the
  submitted fields (merging — don't overwrite `sso_client_secret` when
  blank, matching the `dehydrated()` rule above), save, show a success
  notification.

### Consistency rule (settings write ↔ login read ↔ redirect read)

**Rule**: `Subdomain::hasSsoConfigured()` is the one canonical source of
truth for "SSO is usable for this tenant." Every stage below must call it —
none may re-derive the condition independently.

**Stages**:

1. Settings page save: persists `sso_enabled`/`sso_issuer`/`sso_client_id`/
   `sso_client_secret` on the `Subdomain` row.
2. Login page render (same or a later request): the "Log in with SSO" link
   visibility reads `app('current_subdomain')->hasSsoConfigured()` — a
   **fresh** read, not a cached value from before the settings save.
3. `/sso/redirect` request: re-checks `hasSsoConfigured()` via the same
   method before building the dynamic Socialite config.

**Consistency check**: disabling SSO (toggling `sso_enabled` off) must make
the login button disappear on the next page load *and* make `/sso/redirect`
404 if hit directly — test both, not just the UI visibility (UI visibility
alone doesn't establish that the backend route is actually protected).

## Authorization

```
Page: ManageSsoSettings
  Authorization:
    canAccess: user has 'manage sso settings' permission

Routes: /sso/redirect, /sso/callback
  Authorization: none (pre-authentication, by definition) — but gated by
    Subdomain::hasSsoConfigured(), and by the IdP's own authentication plus
    the local email+subdomain_id match and email_verified check described
    above.
```

## Tests

### SsoLoginTest (Pest, feature test hitting `/sso/redirect` and `/sso/callback`)

Prerequisite: mock the Socialite facade (`Socialite::shouldReceive('driver')
->with('openidconnect')->andReturn($mockProvider)` via
`Laravel\Socialite\Facades\Socialite::fake()` if the installed Socialite
version provides a fake, otherwise `Mockery` on the facade — verify which is
available for the installed `laravel/socialite` version before writing
these tests, and state in the test file which approach was used).

- `/sso/redirect` returns 404 when the current tenant has `sso_enabled =
  false` (and separately: when `sso_enabled = true` but
  `sso_client_secret` is null) — two cases, both via `hasSsoConfigured()`
  being false for different reasons.
- `/sso/redirect` returns 404 when hit on the central domain (no
  `current_subdomain` bound).
- `/sso/redirect` returns a redirect response (not an error) when the
  tenant has SSO fully configured.
- `/sso/callback`, with a mocked Socialite user whose `email_verified` claim
  is `false`: does not authenticate anyone (`assertGuest()`), redirects back
  to the login page, and flashes an error — assert via
  `Auth::guard('web')->check()` is `false`, not just the redirect location.
- `/sso/callback`, with a mocked Socialite user whose email matches no
  `User` row in the current tenant: does not authenticate anyone, same
  assertions as above.
- `/sso/callback`, with a mocked Socialite user whose email matches a
  `User` that belongs to a **different** `Subdomain` (asymmetric fixture:
  create two subdomains, a user in subdomain B with email
  `shared@example.test`, then hit `/sso/callback` while `current_subdomain`
  is subdomain A with a mocked OIDC email of `shared@example.test`):
  must NOT log in subdomain B's user — assert `Auth::guard('web')->check()`
  is `false` (this is the critical cross-tenant isolation test; it exercises
  the same `subdomain_id` scoping rule used by `SubdomainUserProvider`
  elsewhere).
- `/sso/callback`, with a mocked Socialite user whose email matches an
  existing `User` in the current tenant and `email_verified = true`: logs in
  that exact user (assert `Auth::guard('web')->id() === $user->id`, not just
  "a user"), and redirects to the panel dashboard.
- A successful SSO login does **not** trigger the app-authentication MFA
  challenge even when the matched user has app authentication enabled
  (`app_authentication_secret` set): assert the response lands on the
  dashboard directly, not on a multi-factor challenge screen — this is the
  deliberate "SSO supersedes local MFA" rule and deserves its own explicit
  test so a future change doesn't silently reintroduce the challenge.

### ManageSsoSettingsTest (Pest, Livewire test against the page)

- A user without the `manage sso settings` permission gets a 403
  (`livewire(ManageSsoSettings::class)->assertForbidden()` or equivalent —
  verify the exact assertion method against the installed Filament testing
  helpers) and the page is hidden from navigation.
- A user with the permission can load the page and sees the current
  tenant's existing `sso_issuer`/`sso_client_id` in the form (not the
  secret, which should render blank per the `password()` component's normal
  behavior).
- Saving with `sso_enabled = true` but `sso_issuer` blank fails validation
  (`required_if:sso_enabled,true`) and does not persist any change.
- Saving with a new `sso_client_secret` value updates the stored (encrypted)
  secret.
- **Unchanged-secret round trip** (the discriminating test for the
  `dehydrated()` rule): save once with a secret set, then save again with
  the secret field left blank (only changing `sso_issuer`, say) — assert the
  stored `sso_client_secret` is **unchanged** after the second save, not
  wiped to blank. This is exactly the kind of "save unchanged data" case the
  planning checklist calls out, and it's the one place in this feature where
  getting it wrong silently breaks production SSO for a tenant.
- Toggling `sso_enabled` from true to false, then back to true without
  resupplying the secret: `hasSsoConfigured()` must still reflect the
  already-stored secret (i.e. re-enabling doesn't require re-entering
  credentials) — test this as a return-to-earlier-state case.

## Verification still required by the implementing agent

This plan was produced with `search-docs` available for Filament APIs
(confirmed `Panel::login()` accepts a page class via
`vendor/filament/filament/src/Panel/Concerns/HasAuth.php`, and
`Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` exists) and with
targeted web searches for the Socialite OIDC package landscape (no official
Laravel-ecosystem doc exists for `socialiteproviders/openidconnect` as it's
a community SocialiteProviders package, not a `search-docs`-indexed Laravel
package). Before writing code, the implementing agent must still:

1. Run `composer show socialiteproviders/openidconnect` and
   `composer show laravel/socialite` after installing, and read the actual
   installed `Provider` class source to confirm the exact method for
   reading raw ID token claims (used for `email_verified`) and the exact
   facade import path (`Laravel\Socialite\Facades\Socialite` vs
   `Socialite` alias — check what `laravel/socialite`'s installed version
   registers).
2. Confirm the generated Filament route names for the `admin` panel with
   `php artisan route:list --name=filament.admin` (used for
   `filament.admin.auth.login` and `filament.admin.pages.dashboard`).
3. Run `vendor/bin/pint --dirty --format agent` and the two new Pest test
   files before reporting back, per this project's standing rules.
