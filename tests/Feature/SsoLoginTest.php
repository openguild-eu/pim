<?php

declare(strict_types=1);

use App\Models\Catalog;
use App\Models\Subdomain;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\Users\Models\User;

/*
|--------------------------------------------------------------------------
| Mocking approach
|--------------------------------------------------------------------------
|
| The installed laravel/socialite (v5.32) ships a first-party fake
| (vendor/laravel/socialite/src/Testing/SocialiteFake.php, wired up via
| Laravel\Socialite\Socialite::fake()), so these tests use that instead of
| Mockery. Socialite::fake('openidconnect', $user) swaps the facade root
| with a fake factory whose driver('openidconnect') call returns a
| FakeProvider: its user() hands back the given
| Laravel\Socialite\Two\User instance (no real HTTP/JWT verification
| happens), and its redirect() always returns a canned RedirectResponse,
| so no real discovery-document HTTP call ever fires.
*/

/**
 * Fakes the "openidconnect" Socialite driver to return a user with the
 * given raw ID token claims/attributes (e.g. `email`, `email_verified`).
 */
function fakeOidcUser(array $attributes = []): void
{
    Socialite::fake('openidconnect', SocialiteUser::fake($attributes));
}

/**
 * Creates a Subdomain with SSO fully configured (unless overridden) and
 * returns it alongside the tenant base URL IdentifySubdomain resolves host
 * routing from.
 *
 * @return array{subdomain: Subdomain, baseUrl: string}
 */
function createSsoConfiguredSubdomain(array $attributes = []): array
{
    $subdomain = Subdomain::factory()->create([
        'sso_enabled' => true,
        'sso_issuer' => 'https://idp.example.test',
        'sso_client_id' => 'client-id',
        'sso_client_secret' => 'client-secret',
        ...$attributes,
    ]);

    return [
        'subdomain' => $subdomain,
        'baseUrl' => 'http://'.$subdomain->subdomain.'.'.config('app.central_domain'),
    ];
}

it('404s on /sso/redirect when the tenant has sso_enabled false', function () {
    ['baseUrl' => $baseUrl] = createSsoConfiguredSubdomain(['sso_enabled' => false]);

    $this->get($baseUrl.'/sso/redirect')->assertNotFound();
});

it('404s on /sso/redirect when sso_enabled is true but sso_client_secret is null', function () {
    ['baseUrl' => $baseUrl] = createSsoConfiguredSubdomain(['sso_client_secret' => null]);

    $this->get($baseUrl.'/sso/redirect')->assertNotFound();
});

it('404s on /sso/redirect when hit on the central domain', function () {
    $this->get('http://'.config('app.central_domain').'/sso/redirect')->assertNotFound();
});

it('returns a redirect response on /sso/redirect when the tenant has sso fully configured', function () {
    ['baseUrl' => $baseUrl] = createSsoConfiguredSubdomain();

    fakeOidcUser();

    $this->get($baseUrl.'/sso/redirect')->assertRedirect();
});

it('does not authenticate anyone on /sso/callback when email_verified is false', function () {
    ['subdomain' => $subdomain, 'baseUrl' => $baseUrl] = createSsoConfiguredSubdomain();
    $user = User::factory()->create(['subdomain_id' => $subdomain->id, 'email' => 'person@example.test']);

    fakeOidcUser(['email' => $user->email, 'email_verified' => false]);

    $response = $this->get($baseUrl.'/sso/callback');

    $response->assertRedirect($baseUrl.'/login');
    expect(Auth::guard('web')->check())->toBeFalse();
    expect(session()->has('sso_error'))->toBeTrue();
});

it('does not authenticate anyone on /sso/callback when the email matches no user in the current tenant', function () {
    ['baseUrl' => $baseUrl] = createSsoConfiguredSubdomain();

    fakeOidcUser(['email' => 'nobody@example.test', 'email_verified' => true]);

    $response = $this->get($baseUrl.'/sso/callback');

    $response->assertRedirect($baseUrl.'/login');
    expect(Auth::guard('web')->check())->toBeFalse();
    expect(session()->has('sso_error'))->toBeTrue();
});

it('does not log in a user belonging to a different subdomain even when the email matches', function () {
    ['subdomain' => $subdomainA, 'baseUrl' => $baseUrlA] = createSsoConfiguredSubdomain();
    $subdomainB = Subdomain::factory()->create();
    User::factory()->create(['subdomain_id' => $subdomainB->id, 'email' => 'shared@example.test']);

    fakeOidcUser(['email' => 'shared@example.test', 'email_verified' => true]);

    $this->get($baseUrlA.'/sso/callback');

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('logs in the exact matching user and redirects to the panel dashboard', function () {
    ['subdomain' => $subdomain, 'baseUrl' => $baseUrl] = createSsoConfiguredSubdomain();
    $catalog = Catalog::factory()->create(['subdomain_id' => $subdomain->id]);
    $user = User::factory()->create(['subdomain_id' => $subdomain->id, 'email' => 'person@example.test']);
    $catalog->users()->attach($user);

    fakeOidcUser(['email' => $user->email, 'email_verified' => true]);

    $response = $this->get($baseUrl.'/sso/callback');

    expect(Auth::guard('web')->check())->toBeTrue();
    expect(Auth::guard('web')->id())->toBe($user->id);
    $response->assertRedirect($baseUrl.'/'.rawurlencode($catalog->slug));
});

it('does not trigger the app-authentication MFA challenge on a successful SSO login', function () {
    ['subdomain' => $subdomain, 'baseUrl' => $baseUrl] = createSsoConfiguredSubdomain();
    $catalog = Catalog::factory()->create(['subdomain_id' => $subdomain->id]);
    $user = User::factory()->create([
        'subdomain_id' => $subdomain->id,
        'email' => 'person@example.test',
        'app_authentication_secret' => 'ABCDEFGHIJKLMNOP',
    ]);
    $catalog->users()->attach($user);

    fakeOidcUser(['email' => $user->email, 'email_verified' => true]);

    $response = $this->get($baseUrl.'/sso/callback');

    // The deliberate "SSO supersedes local MFA" rule: the response lands on
    // the dashboard directly via Auth::guard('web')->login(), never through
    // Filament's authenticate()/multi-factor challenge flow, even though
    // this user has app authentication enabled.
    expect(Auth::guard('web')->check())->toBeTrue();
    expect(Auth::guard('web')->id())->toBe($user->id);
    $response->assertRedirect($baseUrl.'/'.rawurlencode($catalog->slug));
});
