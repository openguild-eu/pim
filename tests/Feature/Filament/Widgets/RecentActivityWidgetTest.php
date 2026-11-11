<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentActivityWidget;
use App\Models\Catalog;
use Livewire\Livewire;
use Modules\Channels\Models\ApiKey;
use Modules\Products\Models\Product;
use Spatie\Activitylog\Models\Activity;

it('shows activity for the current catalog only', function () {
    // NOTE: deviation from the blueprint's exact code, for two reasons:
    //
    // 1. The foreign-catalog product must be created *before*
    //    actingAsCatalogUser() establishes the Filament tenant context, not
    //    after. Filament's own `BelongsToTenant::observeTenancyModelCreation()`
    //    registers a `creating` listener (active once the admin panel's
    //    tenant is booted) that forcibly associates *any* newly created
    //    Product with the *current* tenant's catalog, overriding whatever
    //    catalog_id the factory tries to set. Verified empirically: with the
    //    blueprint's original ordering, $foreignProduct ended up with the
    //    *same* catalog_id as $ownProduct, defeating the test's purpose. This
    //    matches the established pattern elsewhere in the suite (e.g.
    //    ProductResourceTest.php's "different catalog" tests), which always
    //    create the foreign-catalog fixture before calling
    //    actingAsCatalogUser().
    // 2. Added a subject_type filter to both expected-records queries.
    //    Activity `subject_id` is only unique per subject_type (every model
    //    has its own independent auto-increment sequence), so a bare
    //    where('subject_id', ...) can also match another model's activity
    //    row that happens to share the same id (e.g. the Catalog created by
    //    actingAsCatalogUser(), which is id=1 just like the first Product
    //    created in this test).
    $otherCatalog = Catalog::factory()->create();
    $foreignProduct = Product::factory()->create(['catalog_id' => $otherCatalog->id]);
    $foreignProduct->update(['name' => 'Should not appear']);

    $catalog = actingAsCatalogUser();
    $ownProduct = Product::factory()->create(['catalog_id' => $catalog->id]);
    $ownProduct->update(['name' => 'Renamed']); // logs an 'updated' activity

    Livewire::test(RecentActivityWidget::class)
        ->assertCanSeeTableRecords(
            Activity::query()->where('subject_type', Product::class)->where('subject_id', $ownProduct->id)->get()
        )
        ->assertCanNotSeeTableRecords(
            Activity::query()->where('subject_type', Product::class)->where('subject_id', $foreignProduct->id)->get()
        );
});

it('excludes activity for models without a direct catalog_id, such as ApiKey', function () {
    actingAsCatalogUser();
    $data = makeChannelWithApiKey();
    $data['apiKey']->update(['name' => 'Renamed key']); // logs activity on ApiKey, which has no catalog_id

    Livewire::test(RecentActivityWidget::class)
        ->assertCanNotSeeTableRecords(
            Activity::query()->where('subject_type', ApiKey::class)->get()
        );
});
