<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Modules\Attributes\Models\AttributeSet;
use Modules\Categories\Models\Category;
use Modules\Products\Filament\Exports\ProductExporter;
use Modules\Products\Filament\Resources\Products\Pages\ListProducts;
use Modules\Products\Models\Product;

it('formats the status column with a capitalized label', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'status' => 'draft']);

    expect(ProductExporter::test()->export($product))->toContain('Draft');
});

it('formats a stored integer-cents price as a decimal dollar string', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'price' => 1999]);

    expect(ProductExporter::test()->export($product))->toContain('19.99');
});

it('exports an empty string for a null price', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'price' => null]);

    expect(ProductExporter::test()->export($product))->toContain('');
});

it('exports the attribute set name when one is assigned, and empty when none is', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Footwear']);
    $withSet = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id])->load('attributeSet');
    $withoutSet = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null])->load('attributeSet');

    expect(ProductExporter::test()->export($withSet))->toContain('Footwear')
        ->and(collect(ProductExporter::test()->export($withoutSet))[4])->toBeEmpty();
});

it('exports category names joined by a comma, and empty when none are attached', function () {
    $catalog = actingAsCatalogUser();
    $categoryA = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Alpha']);
    $categoryB = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Beta']);
    $withCategories = Product::factory()->create(['catalog_id' => $catalog->id]);
    $withCategories->categories()->attach([$categoryA->id, $categoryB->id]);
    $withoutCategories = Product::factory()->create(['catalog_id' => $catalog->id]);

    // Columns are, in order: sku, name, status, price, attributeSet.name,
    // categories, created_at — categories is index 5, not the last column.
    $joined = ProductExporter::test()->export($withCategories->load('categories'));
    $empty = ProductExporter::test()->export($withoutCategories->load('categories'));

    expect(explode(', ', collect($joined)[5]))->toEqualCanonicalizing(['Alpha', 'Beta'])
        ->and(collect($empty)[5])->toBeEmpty();
});

it('formats created_at as Y-m-d H:i', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'created_at' => '2026-01-15 09:30:00']);

    expect(ProductExporter::test()->export($product))->toContain('2026-01-15 09:30');
});

it('only includes products from the current catalog in the export query', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreignProduct = Product::factory()->create(['catalog_id' => $otherCatalog->id]);

    $catalog = actingAsCatalogUser();
    $ownProduct = Product::factory()->create(['catalog_id' => $catalog->id]);

    $exports = ExportAction::fake();

    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(ProductExporter::class, function (mixed $export, Builder $query) use ($ownProduct, $foreignProduct): bool {
        $ids = $query->pluck('id')->all();

        expect($ids)->toContain($ownProduct->id)
            ->and($ids)->not->toContain($foreignProduct->id);

        return true;
    });
});

it('captures only the bulk-selected records, unlike a plain header export', function () {
    $catalog = actingAsCatalogUser();
    $products = Product::factory()->count(3)->create(['catalog_id' => $catalog->id]);

    $bulkExports = ExportBulkAction::fake();

    Livewire::test(ListProducts::class)
        ->selectTableRecords([$products[1]->getKey()])
        ->callAction(TestAction::make('export')->table()->bulk())
        ->assertHasNoFormErrors();

    $bulkExports->assertDispatched(ProductExporter::class, function (mixed $export, Builder $query, array $columnMap, array $options, array $formats, ?array $records) use ($products): bool {
        expect($records)->toBe([$products[1]->getKey()]);

        return true;
    });

    $headerExports = ExportAction::fake();

    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $headerExports->assertDispatched(ProductExporter::class, function (mixed $export, Builder $query, array $columnMap, array $options, array $formats, ?array $records): bool {
        expect($records)->toBeNull();

        return true;
    });
});
