<?php

declare(strict_types=1);

use App\Filament\Widgets\ProductCompletenessOverview;
use Livewire\Livewire;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;

it('reports average completeness and missing-attribute count across products', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);

    Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]); // 100%
    Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]); // 0%, missing

    Livewire::test(ProductCompletenessOverview::class)
        ->assertSee('50%')
        ->assertSee('1')
        ->assertSee('out of 2 products');
});

it('counts products missing images and categories', function () {
    $catalog = actingAsCatalogUser();
    Product::factory()->create(['catalog_id' => $catalog->id]);
    $withCategory = Product::factory()->create(['catalog_id' => $catalog->id]);
    $withCategory->categories()->attach(
        Category::factory()->create(['catalog_id' => $catalog->id])
    );

    Livewire::test(ProductCompletenessOverview::class)
        ->assertSee('Missing Images')
        ->assertSee('Missing Categories');
});
