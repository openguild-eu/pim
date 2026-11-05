<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Catalog;
use App\Models\Subdomain;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Modules\Users\Models\User;

it('returns 403 when a non-admin opens the users list page', function () {
    $catalog = actingAsSubdomainUser();

    $this->get(catalogPanelUrl($catalog, 'users'))->assertForbidden();
});

it('renders the users list page for an admin', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);

    $this->get(catalogPanelUrl($catalog, 'users'))->assertOk();
});

it('forbids a non-admin from mounting the create user page', function () {
    actingAsSubdomainUser();

    Livewire::test(CreateUser::class)->assertForbidden();
});

it('forbids a non-admin from mounting the edit user page', function () {
    $catalog = actingAsSubdomainUser();
    $otherUser = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);

    Livewire::test(EditUser::class, ['record' => $otherUser->getRouteKey()])->assertForbidden();
});

it('does not list users from another subdomain', function () {
    $foreignUser = User::factory()->create();

    $catalog = actingAsSubdomainUser(isAdmin: true);
    $ownUser = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$ownUser])
        ->assertCanNotSeeTableRecords([$foreignUser]);
});

it('creates a user with the admin role when is_admin is on', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'is_admin' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'new-admin@example.com')->firstOrFail();

    expect($user->subdomain_id)->toBe($catalog->subdomain_id)
        ->and($user->hasRole('admin'))->toBeTrue();
    $this->assertDatabaseHas('model_has_roles', [
        'model_id' => $user->id,
        'subdomain_id' => $catalog->subdomain_id,
    ]);
});

it('creates a user without any role when is_admin is off', function () {
    actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Editor',
            'email' => 'new-editor@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'is_admin' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'new-editor@example.com')->firstOrFail();

    expect($user->roles)->toBeEmpty();
});

it('removes the admin role when is_admin is turned off on edit', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $otherAdmin = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $otherAdmin->assignRole('admin');

    Livewire::test(EditUser::class, ['record' => $otherAdmin->getRouteKey()])
        ->fillForm(['is_admin' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($otherAdmin->fresh()->hasRole('admin'))->toBeFalse();
});

it('grants the admin role when is_admin is turned on on edit', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $editor = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);

    Livewire::test(EditUser::class, ['record' => $editor->getRouteKey()])
        ->fillForm(['is_admin' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($editor->fresh()->hasRole('admin'))->toBeTrue();
});

it('hydrates is_admin on the edit form from the user\'s current role', function (bool $isAdmin) {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $user = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);

    if ($isAdmin) {
        $user->assignRole('admin');
    }

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertSchemaStateSet(['is_admin' => $isAdmin]);
})->with([
    'admin' => true,
    'non-admin' => false,
]);

it('rejects an email that is already used in a different subdomain', function () {
    User::factory()->create([
        'subdomain_id' => Subdomain::factory(),
        'email' => 'taken@example.com',
    ]);

    actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});

it('deletes a user with catalogs attached, keeping the catalogs and removing its role assignment', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $user = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $secondCatalog = Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $user->catalogs()->attach([$catalog->id, $secondCatalog->id]);
    $user->assignRole('admin');

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($user));

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('catalog_user', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id]);
    $this->assertDatabaseHas('catalogs', ['id' => $catalog->id]);
    $this->assertDatabaseHas('catalogs', ['id' => $secondCatalog->id]);
});
