<?php

declare(strict_types=1);

use App\Filament\Resources\Catalogs\Pages\ListCatalogs;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\CatalogsRelationManager;
use App\Models\Catalog;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Modules\Users\Models\User;

it('excludes a catalog from another subdomain from the attach record select search results', function () {
    $foreignCatalog = Catalog::factory()->create(['name' => 'Foreign Spring']);

    $catalog = actingAsSubdomainUser(isAdmin: true);
    $user = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $ownCatalog = Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id, 'name' => 'Own Spring']);

    Livewire::test(CatalogsRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => EditUser::class,
    ])
        ->mountTableAction('attach')
        ->assertFormFieldExists('recordId', function (Select $field) use ($ownCatalog, $foreignCatalog): bool {
            $options = $field->getSearchResults('Spring');

            return array_key_exists($ownCatalog->id, $options)
                && ! array_key_exists($foreignCatalog->id, $options);
        });
});

it('rejects attaching a catalog from another subdomain by id', function () {
    $foreignCatalog = Catalog::factory()->create();

    $catalog = actingAsSubdomainUser(isAdmin: true);
    $user = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);

    Livewire::test(CatalogsRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => EditUser::class,
    ])->callAction(TestAction::make('attach')->table(), data: [
        'recordId' => $foreignCatalog->id,
    ])->assertHasActionErrors(['recordId']);

    $this->assertDatabaseMissing('catalog_user', ['user_id' => $user->id]);
});

it('attaches a catalog to a user and counts the user on the catalog', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $user = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $otherCatalog = Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id]);

    Livewire::test(CatalogsRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => EditUser::class,
    ])
        ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $otherCatalog->id])
        ->assertHasNoActionErrors()
        ->assertCanSeeTableRecords([$otherCatalog]);

    expect($user->catalogs()->pluck('catalogs.id')->all())->toBe([$otherCatalog->id]);

    Livewire::test(ListCatalogs::class)
        ->assertTableColumnStateSet('users_count', '1', $otherCatalog);
});

it('detaches only the selected user\'s assignment, leaving the catalog and other users untouched', function () {
    $catalog = actingAsSubdomainUser(isAdmin: true);
    $user = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $otherUser = User::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $sharedCatalog = Catalog::factory()->create(['subdomain_id' => $catalog->subdomain_id]);
    $sharedCatalog->users()->attach([$user->id, $otherUser->id]);

    Livewire::test(CatalogsRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => EditUser::class,
    ])->callAction(TestAction::make('detach')->table($sharedCatalog));

    $this->assertDatabaseMissing('catalog_user', ['user_id' => $user->id, 'catalog_id' => $sharedCatalog->id]);
    $this->assertDatabaseHas('catalog_user', ['user_id' => $otherUser->id, 'catalog_id' => $sharedCatalog->id]);
    $this->assertDatabaseHas('catalogs', ['id' => $sharedCatalog->id]);
});
