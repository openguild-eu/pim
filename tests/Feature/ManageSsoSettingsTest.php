<?php

declare(strict_types=1);

use App\Filament\Pages\ManageSsoSettings;
use App\Models\Catalog;
use App\Models\Subdomain;
use Livewire\Livewire;
use Modules\Users\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the given User the 'manage sso settings' permission within the
 * given Subdomain's permission team, mirroring how DatabaseSeeder grants it
 * to the 'admin' role in production.
 */
function grantManageSsoSettingsPermission(Subdomain $subdomain, User $user): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($subdomain->id);
    $user->givePermissionTo(Permission::findOrCreate('manage sso settings'));
}

/**
 * Builds a Subdomain with SSO fully configured (unless overridden), a
 * Catalog under it, and authenticates as a member User of that Catalog —
 * mirroring actingAsSubdomainUser() in tests/Pest.php, but starting from a
 * Subdomain we control so its sso_* columns are set before
 * `app('current_subdomain')` is bound.
 *
 * @return array{subdomain: Subdomain, catalog: Catalog, user: User}
 */
function actingAsSsoConfiguredSubdomainUser(array $subdomainAttributes = []): array
{
    $subdomain = Subdomain::factory()->create([
        'sso_enabled' => true,
        'sso_issuer' => 'https://idp.example.test',
        'sso_client_id' => 'existing-client-id',
        'sso_client_secret' => 'existing-secret',
        ...$subdomainAttributes,
    ]);
    $catalog = Catalog::factory()->create(['subdomain_id' => $subdomain->id]);

    actingAsSubdomainUser(catalog: $catalog);

    /** @var User $user */
    $user = auth()->user();
    grantManageSsoSettingsPermission($subdomain, $user);

    return ['subdomain' => $subdomain, 'catalog' => $catalog, 'user' => $user];
}

it('forbids a user without the manage sso settings permission from mounting the page, and hides it from navigation', function () {
    actingAsSubdomainUser();

    Livewire::test(ManageSsoSettings::class)->assertForbidden();
    expect(ManageSsoSettings::canAccess())->toBeFalse();
});

it('allows a user with the manage sso settings permission to load the page and see existing non-secret values, with the secret rendering blank', function () {
    actingAsSsoConfiguredSubdomainUser([
        'sso_issuer' => 'https://idp.example.test',
        'sso_client_id' => 'existing-client-id',
    ]);

    expect(ManageSsoSettings::canAccess())->toBeTrue();

    Livewire::test(ManageSsoSettings::class)
        ->assertOk()
        ->assertSet('data.sso_enabled', true)
        ->assertSet('data.sso_issuer', 'https://idp.example.test')
        ->assertSet('data.sso_client_id', 'existing-client-id')
        ->assertSet('data.sso_client_secret', null);
});

it('fails validation and does not persist a change when sso_enabled is true but sso_issuer is blank', function () {
    ['subdomain' => $subdomain] = actingAsSsoConfiguredSubdomainUser([
        'sso_enabled' => false,
        'sso_issuer' => null,
        'sso_client_id' => null,
        'sso_client_secret' => null,
    ]);

    Livewire::test(ManageSsoSettings::class)
        ->fillForm([
            'sso_enabled' => true,
            'sso_issuer' => '',
            'sso_client_id' => 'some-client-id',
            'sso_client_secret' => 'some-secret',
        ])
        ->call('save')
        ->assertHasFormErrors(['sso_issuer' => 'required_if']);

    $subdomain->refresh();
    expect($subdomain->sso_enabled)->toBeFalse();
    expect($subdomain->sso_client_id)->toBeNull();
    expect($subdomain->sso_client_secret)->toBeNull();
});

it('updates the stored encrypted secret when a new sso_client_secret value is saved', function () {
    ['subdomain' => $subdomain] = actingAsSsoConfiguredSubdomainUser(['sso_client_secret' => 'old-secret']);

    Livewire::test(ManageSsoSettings::class)
        ->fillForm(['sso_client_secret' => 'brand-new-secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subdomain->refresh()->sso_client_secret)->toBe('brand-new-secret');
});

it('keeps the stored secret unchanged when saving again with the secret field left blank', function () {
    ['subdomain' => $subdomain] = actingAsSsoConfiguredSubdomainUser(['sso_client_secret' => 'first-secret']);

    // First save: secret resupplied with its current value (simulating an
    // initial save), confirming the baseline is as expected.
    Livewire::test(ManageSsoSettings::class)
        ->fillForm(['sso_client_secret' => 'first-secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subdomain->refresh()->sso_client_secret)->toBe('first-secret');

    // Second save (a separate page load/submit): only sso_issuer changes,
    // the secret field is left blank as it always renders.
    Livewire::test(ManageSsoSettings::class)
        ->fillForm([
            'sso_issuer' => 'https://new-idp.example.test',
            'sso_client_secret' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $subdomain->refresh();
    expect($subdomain->sso_client_secret)->toBe('first-secret');
    expect($subdomain->sso_issuer)->toBe('https://new-idp.example.test');
});

it('keeps hasSsoConfigured() true after disabling and re-enabling sso without resupplying the secret', function () {
    ['subdomain' => $subdomain] = actingAsSsoConfiguredSubdomainUser(['sso_client_secret' => 'persisted-secret']);

    Livewire::test(ManageSsoSettings::class)
        ->fillForm(['sso_enabled' => false, 'sso_client_secret' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subdomain->refresh()->sso_enabled)->toBeFalse();
    expect($subdomain->sso_client_secret)->toBe('persisted-secret');

    Livewire::test(ManageSsoSettings::class)
        ->fillForm(['sso_enabled' => true, 'sso_client_secret' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $subdomain->refresh();
    expect($subdomain->sso_enabled)->toBeTrue();
    expect($subdomain->sso_client_secret)->toBe('persisted-secret');
    expect($subdomain->hasSsoConfigured())->toBeTrue();
});
