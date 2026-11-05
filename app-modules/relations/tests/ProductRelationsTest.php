<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Modules\Products\Filament\Resources\Products\Pages\ManageCrossSellProducts;
use Modules\Products\Filament\Resources\Products\Pages\ManageRelatedProducts;
use Modules\Products\Filament\Resources\Products\Pages\ManageUpSellProducts;
use Modules\Products\Models\Product;

dataset('relation_pages', [
    'related products' => [ManageRelatedProducts::class],
    'cross-sell products' => [ManageCrossSellProducts::class],
    'up-sell products' => [ManageUpSellProducts::class],
]);

it('excludes the owning product\'s own key from the attach record select', function (string $pageClass) {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test($pageClass, ['record' => $product->getRouteKey()])
        ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $product->id])
        ->assertHasActionErrors(['recordId']);
})->with('relation_pages');

it('excludes a product from a different catalog from the attach record select', function (string $pageClass) {
    $otherCatalog = Catalog::factory()->create();
    $foreignProduct = Product::factory()->create(['catalog_id' => $otherCatalog->id]);

    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test($pageClass, ['record' => $product->getRouteKey()])
        ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $foreignProduct->id])
        ->assertHasActionErrors(['recordId']);
})->with('relation_pages');

it('excludes a product from a different catalog from the attach record select\'s search results, even when searching by its sku or name', function (string $pageClass) {
    $otherCatalog = Catalog::factory()->create();
    $foreignProduct = Product::factory()->create(['catalog_id' => $otherCatalog->id]);

    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    $livewire = Livewire::test($pageClass, ['record' => $product->getRouteKey()])
        ->mountAction(TestAction::make('attach')->table());

    $livewire->assertSchemaComponentExists(
        'recordId',
        checkComponentUsing: fn (Select $component): bool => ! array_key_exists(
            $foreignProduct->id,
            $component->getSearchResults($foreignProduct->sku),
        ),
    );

    $livewire->assertSchemaComponentExists(
        'recordId',
        checkComponentUsing: fn (Select $component): bool => ! array_key_exists(
            $foreignProduct->id,
            $component->getSearchResults($foreignProduct->name),
        ),
    );
})->with('relation_pages');

it('attaching under one type does not leak into the other two types', function () {
    $catalog = actingAsCatalogUser();
    $a = Product::factory()->create(['catalog_id' => $catalog->id]);
    $b = Product::factory()->create(['catalog_id' => $catalog->id]);

    $a->relatedProducts()->attach($b->id);

    expect($a->relatedProducts->pluck('id')->all())->toBe([$b->id])
        ->and($a->crossSellProducts->pluck('id')->all())->toBe([])
        ->and($a->upSellProducts->pluck('id')->all())->toBe([]);
});

it('attaching A to B under related does not make B related to A', function () {
    $catalog = actingAsCatalogUser();
    $a = Product::factory()->create(['catalog_id' => $catalog->id]);
    $b = Product::factory()->create(['catalog_id' => $catalog->id]);

    $a->relatedProducts()->attach($b->id);

    expect($a->relatedProducts->pluck('id')->all())->toBe([$b->id])
        ->and($b->relatedProducts->pluck('id')->all())->toBe([]);
});

it('scopes position auto-increment by [product_id, type], not just product_id', function () {
    $catalog = actingAsCatalogUser();
    $a = Product::factory()->create(['catalog_id' => $catalog->id]);
    $related1 = Product::factory()->create(['catalog_id' => $catalog->id]);
    $related2 = Product::factory()->create(['catalog_id' => $catalog->id]);
    $related3 = Product::factory()->create(['catalog_id' => $catalog->id]);
    $crossSell1 = Product::factory()->create(['catalog_id' => $catalog->id]);

    $a->relatedProducts()->attach($related1->id);
    $a->relatedProducts()->attach($related2->id);
    $a->relatedProducts()->attach($related3->id);
    $a->crossSellProducts()->attach($crossSell1->id);

    expect($a->relatedProducts()->find($related1->id)->pivot->position)->toBe(0)
        ->and($a->relatedProducts()->find($related2->id)->pivot->position)->toBe(1)
        ->and($a->relatedProducts()->find($related3->id)->pivot->position)->toBe(2)
        ->and($a->crossSellProducts()->find($crossSell1->id)->pivot->position)->toBe(0);
});

it('reordering one tab does not affect another tab or another product\'s rows of any type', function () {
    $catalog = actingAsCatalogUser();
    $a = Product::factory()->create(['catalog_id' => $catalog->id]);
    $otherProduct = Product::factory()->create(['catalog_id' => $catalog->id]);
    $first = Product::factory()->create(['catalog_id' => $catalog->id]);
    $second = Product::factory()->create(['catalog_id' => $catalog->id]);
    $relatedUntouched = Product::factory()->create(['catalog_id' => $catalog->id]);
    $otherProductsCrossSell = Product::factory()->create(['catalog_id' => $catalog->id]);

    $a->crossSellProducts()->attach($first->id);
    $a->crossSellProducts()->attach($second->id);
    $a->relatedProducts()->attach($relatedUntouched->id);
    $otherProduct->crossSellProducts()->attach($otherProductsCrossSell->id);

    Livewire::test(ManageCrossSellProducts::class, ['record' => $a->getRouteKey()])
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($a->crossSellProducts()->find($second->id)->pivot->position)->toBe(1)
        ->and($a->crossSellProducts()->find($first->id)->pivot->position)->toBe(2)
        ->and($a->relatedProducts()->find($relatedUntouched->id)->pivot->position)->toBe(0)
        ->and($otherProduct->crossSellProducts()->find($otherProductsCrossSell->id)->pivot->position)->toBe(0);
});

it('detaching removes only the exact (product, related, type) row, not every type between the same pair', function () {
    $catalog = actingAsCatalogUser();
    $a = Product::factory()->create(['catalog_id' => $catalog->id]);
    $b = Product::factory()->create(['catalog_id' => $catalog->id]);

    $a->relatedProducts()->attach($b->id);
    $a->crossSellProducts()->attach($b->id);

    Livewire::test(ManageRelatedProducts::class, ['record' => $a->getRouteKey()])
        ->callAction(TestAction::make('detach')->table($b));

    expect($a->relatedProducts->pluck('id')->all())->toBe([])
        ->and($a->crossSellProducts->pluck('id')->all())->toBe([$b->id]);
});

it('does not resolve a product belonging to a different catalog on the related-products page', function (string $pageClass) {
    $otherCatalog = Catalog::factory()->create();
    $product = Product::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    expect(fn () => Livewire::test($pageClass, ['record' => $product->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
})->with('relation_pages');
