<?php

declare(strict_types=1);

use App\Filament\Pages\Tenancy\RegisterCatalog;
use App\Models\Catalog;
use App\Models\Subdomain;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Users\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a Subdomain and a User belonging to it who is not attached to any
 * Catalog, authenticates as that user, and boots the Filament "admin" panel
 * context the same way actingAsCatalogUser()/actingAsSubdomainUser() do in
 * tests/Pest.php. Those helpers always create and attach a Catalog, so this
 * local variant exists to reach the zero-Catalog state this page handles.
 *
 * @return array{subdomain: Subdomain, user: User, baseUrl: string}
 */
function actingAsUserWithZeroCatalogs(): array
{
    $subdomain = Subdomain::factory()->create();
    $user = User::factory()->create(['subdomain_id' => $subdomain->id]);

    app()->instance('current_subdomain', $subdomain);
    app(PermissionRegistrar::class)->setPermissionsTeamId($subdomain->id);

    test()->actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    return [
        'subdomain' => $subdomain,
        'user' => $user,
        'baseUrl' => 'http://'.$subdomain->subdomain.'.'.config('app.central_domain'),
    ];
}

it('redirects a user with zero catalogs to the registration page on login', function () {
    ['baseUrl' => $baseUrl] = actingAsUserWithZeroCatalogs();

    $this->get($baseUrl.'/')->assertRedirect($baseUrl.'/new');
});

it('creates a catalog in the current subdomain and attaches the registering user when submitting the registration form', function () {
    ['subdomain' => $subdomain, 'user' => $user] = actingAsUserWithZeroCatalogs();

    Livewire::test(RegisterCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->call('register')
        ->assertHasNoFormErrors();

    $catalog = Catalog::where('name', 'Spring Collection')->sole();

    expect($catalog->subdomain_id)->toBe($subdomain->id);
    expect($catalog->users()->whereKey($user->id)->exists())->toBeTrue();
});

it('lands in the panel with the newly registered catalog as the active tenant, not redirected back to registration', function () {
    ['subdomain' => $subdomain, 'baseUrl' => $baseUrl] = actingAsUserWithZeroCatalogs();

    Livewire::test(RegisterCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->call('register')
        ->assertHasNoFormErrors();

    $catalog = Catalog::where('name', 'Spring Collection')->sole();

    $this->get($baseUrl.'/')->assertRedirect($baseUrl.'/'.rawurlencode($catalog->slug));
});

it('allows registering a catalog with a name already used in a different subdomain', function () {
    ['subdomain' => $subdomain] = actingAsUserWithZeroCatalogs();
    Catalog::factory()->create(['name' => 'Spring Collection']);

    Livewire::test(RegisterCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->call('register')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('catalogs', [
        'name' => 'Spring Collection',
        'subdomain_id' => $subdomain->id,
    ]);
});

it('does not redirect a user who already has at least one catalog to the registration page on login', function () {
    $catalog = Catalog::factory()->create();
    $user = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $catalog->users()->attach($user);

    $baseUrl = 'http://'.$catalog->subdomain->subdomain.'.'.config('app.central_domain');

    $this->actingAs($user)
        ->get($baseUrl.'/')
        ->assertRedirect($baseUrl.'/'.rawurlencode($catalog->slug));
});

it('registers two catalogs with the same name in the same subdomain with distinct auto-resolved slugs', function () {
    ['subdomain' => $subdomain, 'baseUrl' => $baseUrl] = actingAsUserWithZeroCatalogs();

    Livewire::test(RegisterCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->call('register')
        ->assertHasNoFormErrors();

    $firstCatalog = Catalog::where('subdomain_id', $subdomain->id)->where('name', 'Spring Collection')->sole();

    $secondUser = User::factory()->create(['subdomain_id' => $subdomain->id]);
    app()->instance('current_subdomain', $subdomain);
    app(PermissionRegistrar::class)->setPermissionsTeamId($subdomain->id);
    test()->actingAs($secondUser);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    Livewire::test(RegisterCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->call('register')
        ->assertHasNoFormErrors();

    $secondCatalog = Catalog::where('subdomain_id', $subdomain->id)
        ->where('name', 'Spring Collection')
        ->where('id', '!=', $firstCatalog->id)
        ->sole();

    expect($secondCatalog->slug)->not->toBe($firstCatalog->slug);
});
