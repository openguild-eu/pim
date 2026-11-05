<?php

declare(strict_types=1);

use App\Filament\Resources\Catalogs\Pages\CreateCatalog;
use App\Filament\Resources\Catalogs\Pages\EditCatalog;
use App\Filament\Resources\Catalogs\Pages\ListCatalogs;
use App\Models\Catalog;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Modules\Users\Models\User;

it('returns 403 when a non-admin opens the catalogs list page', function () {
    $catalog = actingAsSubdomainUser();

    $this->get(catalogPanelUrl($catalog, 'catalogs'))->assertForbidden();
});

it('renders the catalogs list page for an admin', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);

    $this->get(catalogPanelUrl($catalog, 'catalogs'))->assertOk();
});

it('forbids a non-admin from mounting the create catalog page', function () {
    actingAsSubdomainUser();

    Livewire::test(CreateCatalog::class)->assertForbidden();
});

it('forbids a non-admin from mounting the edit catalog page', function () {
    $catalog = actingAsSubdomainUser();

    Livewire::test(EditCatalog::class, ['record' => $catalog->getRouteKey()])->assertForbidden();
});

it('does not list catalogs from another subdomain', function () {
    $foreignCatalog = Catalog::factory()->create();

    $catalog = actingAsSubdomainUser(isAdmin: true);

    Livewire::test(ListCatalogs::class)
        ->assertCanSeeTableRecords([$catalog])
        ->assertCanNotSeeTableRecords([$foreignCatalog]);
});

it('creates a catalog in the current subdomain', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('catalogs', [
        'name' => 'Spring Collection',
        'subdomain_id' => $catalog->subdomain_id,
    ]);
});

it('requires a catalog name', function () {
    actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('fails validation when creating a catalog with a name already used in the same subdomain', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id, 'name' => 'Spring Collection']);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

it('allows creating a catalog with a name already used in a different subdomain', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    Catalog::factory()->create(['name' => 'Spring Collection']);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('catalogs', [
        'name' => 'Spring Collection',
        'subdomain_id' => $catalog->subdomain_id,
    ]);
});

it('does not trigger a false-positive unique error when saving a catalog without changing its name', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $otherCatalog = Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id, 'name' => 'Unchanged']);

    Livewire::test(EditCatalog::class, ['record' => $otherCatalog->getRouteKey()])
        ->fillForm(['name' => 'Unchanged'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('renames a catalog', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $otherCatalog = Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id, 'name' => 'Old']);

    Livewire::test(EditCatalog::class, ['record' => $otherCatalog->getRouteKey()])
        ->fillForm(['name' => 'New'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($otherCatalog->fresh()->name)->toBe('New');
});

it('deletes a catalog with users attached, keeping the users', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $otherCatalog = Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $user = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $otherCatalog->users()->attach($user);

    Livewire::test(ListCatalogs::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($otherCatalog));

    $this->assertDatabaseMissing('catalogs', ['id' => $otherCatalog->id]);
    $this->assertDatabaseMissing('catalog_user', ['catalog_id' => $otherCatalog->id]);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});
