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
        'slug' => 'spring-collection',
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

it('derives the slug from the name only on create', function () {
    actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection'])
        ->assertSchemaStateSet(['slug' => 'spring-collection']);
});

it('does not overwrite an existing slug when the name changes on edit', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $otherCatalog = Catalog::factory()->create([
        'subdomain_id' => $catalog->subdomain_id,
        'name' => 'Old Name',
        'slug' => 'custom-slug',
    ]);

    Livewire::test(EditCatalog::class, ['record' => $otherCatalog->getRouteKey()])
        ->fillForm(['name' => 'New Name'])
        ->assertSchemaStateSet(['slug' => 'custom-slug']);
});

it('requires a slug', function () {
    actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection', 'slug' => ''])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'required']);
});

it('requires the slug to be alpha-dash', function () {
    actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection', 'slug' => 'not a slug!'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'alpha_dash']);
});

it('rejects a slug over 255 characters', function () {
    actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection', 'slug' => str_repeat('a', 256)])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'max']);
});

it('requires the slug to be unique within the same subdomain', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id, 'slug' => 'spring-collection']);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Other Collection', 'slug' => 'spring-collection'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('allows the same slug in two different subdomains', function () {
    Catalog::factory()->create(['slug' => 'spring-collection']);

    $catalog = actingAsSubdomainUser(isAdmin: true);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection', 'slug' => 'spring-collection'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('catalogs', [
        'subdomain_id' => $catalog->subdomain_id,
        'slug' => 'spring-collection',
    ]);
});

it('allows two catalogs with the same name in the same subdomain when their slugs differ', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    Catalog::factory()->create([
        'subdomain_id' => $catalog->subdomain_id,
        'name' => 'Spring Collection',
        'slug' => 'spring-collection',
    ]);

    Livewire::test(CreateCatalog::class)
        ->fillForm(['name' => 'Spring Collection', 'slug' => 'spring-collection-2'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('catalogs', [
        'subdomain_id' => $catalog->subdomain_id,
        'name' => 'Spring Collection',
        'slug' => 'spring-collection-2',
    ]);
});

it('does not trigger a false-positive unique error when saving a catalog without changing its slug', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $otherCatalog = Catalog::factory()->create([
        'subdomain_id' => $catalog->subdomain_id,
        'name' => 'Unchanged',
        'slug' => 'unchanged',
    ]);

    Livewire::test(EditCatalog::class, ['record' => $otherCatalog->getRouteKey()])
        ->fillForm(['slug' => 'unchanged'])
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
