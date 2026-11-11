<?php

declare(strict_types=1);

use App\Filament\Widgets\CatalogStatsOverview;
use Livewire\Livewire;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;

it('shows counts scoped to the current catalog only', function () {
    $catalog = actingAsCatalogUser();
    Product::factory()->count(2)->create(['catalog_id' => $catalog->id]);
    Category::factory()->create(['catalog_id' => $catalog->id]);

    // Different catalog — must not affect the counts above.
    Product::factory()->count(5)->create();

    Livewire::test(CatalogStatsOverview::class)
        ->assertSee('2')
        ->assertSee('1');
});
