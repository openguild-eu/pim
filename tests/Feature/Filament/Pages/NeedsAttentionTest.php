<?php

declare(strict_types=1);

use App\Filament\Pages\NeedsAttention;
use App\Models\Catalog;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Categories\Models\Category;
use Modules\Channels\Models\Channel;
use Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use Modules\Products\Models\Product;

/**
 * A product with no data-quality issues at all: has an image, a category,
 * and no attribute set (so there are no required attributes to miss) and no
 * channels. The shared "does not need attention" baseline for several tests
 * below.
 */
function createCompleteProduct(Catalog $catalog): Product
{
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $product->addMedia(UploadedFile::fake()->image('photo.jpg'))->preservingOriginal()->toMediaCollection('images');
    $product->categories()->attach(Category::factory()->create(['catalog_id' => $catalog->id]));

    return $product;
}

/**
 * Reads the rendered `issues` badge values for one record, by binding the
 * table's `issues` column to that record and reading its raw (pre-format)
 * state — the same pattern already used for the `images` column in
 * ProductResourceTest.
 *
 * @return list<string>
 */
function tableIssueValues(Testable $test, Product $product): array
{
    $column = $test->instance()->getTable()->getColumn('issues');
    $column->record($test->instance()->getTableRecord((string) $product->getKey()));

    return collect($column->getState())->map(fn ($issue) => $issue->value)->all();
}

it('allows any authenticated catalog user to access the page', function () {
    actingAsCatalogUser();

    Livewire::test(NeedsAttention::class)->assertOk();
});

it('does not list a fully complete product with no issues', function () {
    $catalog = actingAsCatalogUser();
    $product = createCompleteProduct($catalog);

    Livewire::test(NeedsAttention::class)
        ->assertCanNotSeeTableRecords([$product]);
});

it('lists a product missing images only, with exactly the missing-images badge', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $product->categories()->attach(Category::factory()->create(['catalog_id' => $catalog->id]));

    $test = Livewire::test(NeedsAttention::class)
        ->assertCanSeeTableRecords([$product]);

    expect(tableIssueValues($test, $product))->toBe(['missing_images']);
});

it('lists a product missing categories only, with exactly the missing-categories badge', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $product->addMedia(UploadedFile::fake()->image('photo.jpg'))->preservingOriginal()->toMediaCollection('images');

    $test = Livewire::test(NeedsAttention::class)
        ->assertCanSeeTableRecords([$product]);

    expect(tableIssueValues($test, $product))->toBe(['missing_categories']);
});

it('lists a product with an unfilled required attribute, with exactly the missing-required-attributes badge', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);

    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);
    $product->addMedia(UploadedFile::fake()->image('photo.jpg'))->preservingOriginal()->toMediaCollection('images');
    $product->categories()->attach(Category::factory()->create(['catalog_id' => $catalog->id]));

    $test = Livewire::test(NeedsAttention::class)
        ->assertCanSeeTableRecords([$product]);

    expect(tableIssueValues($test, $product))->toBe(['missing_required_attributes']);
});

it('lists a product published on a channel while missing images, with both the missing-images and published-but-incomplete badges', function () {
    $catalog = actingAsCatalogUser();
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);

    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $product->categories()->attach(Category::factory()->create(['catalog_id' => $catalog->id]));
    $product->channels()->attach($channel->id, ['is_published' => true, 'published_at' => now()]);

    $test = Livewire::test(NeedsAttention::class)
        ->assertCanSeeTableRecords([$product]);

    expect(tableIssueValues($test, $product))->toBe(['missing_images', 'published_but_incomplete']);
});

it('does not flag a product attached to an unpublished channel, and otherwise complete, as needing attention', function () {
    $catalog = actingAsCatalogUser();
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);

    $product = createCompleteProduct($catalog);
    $product->channels()->attach($channel->id, ['is_published' => false]);

    Livewire::test(NeedsAttention::class)
        ->assertCanNotSeeTableRecords([$product]);
});

it('does not list a product whose only unfilled required attribute lives on a secondary attribute set', function () {
    $catalog = actingAsCatalogUser();

    // Legacy single attributeSet(): no required attributes, so it reports
    // as complete per missingRequiredAttributes()'s known scope.
    $legacyAttributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);

    // Secondary attributeSets() entry: has an unfilled required attribute,
    // which `Product::issues()` does not read (inherited known limitation).
    $secondaryAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $secondaryAttributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $secondaryAttributeSet->attributes()->attach($secondaryAttribute->id, ['position' => 0, 'is_required' => true]);

    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $legacyAttributeSet->id]);
    $product->attributeSets()->attach($secondaryAttributeSet->id);
    $product->addMedia(UploadedFile::fake()->image('photo.jpg'))->preservingOriginal()->toMediaCollection('images');
    $product->categories()->attach(Category::factory()->create(['catalog_id' => $catalog->id]));

    Livewire::test(NeedsAttention::class)
        ->assertCanNotSeeTableRecords([$product]);
});

it('filters the table down to products with a single selected issue type', function () {
    $catalog = actingAsCatalogUser();
    $missingImages = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $missingImages->categories()->attach(Category::factory()->create(['catalog_id' => $catalog->id]));

    $missingCategories = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $missingCategories->addMedia(UploadedFile::fake()->image('photo.jpg'))->preservingOriginal()->toMediaCollection('images');

    Livewire::test(NeedsAttention::class)
        ->filterTable('issue', ['types' => ['missing_images']])
        ->assertCanSeeTableRecords([$missingImages])
        ->assertCanNotSeeTableRecords([$missingCategories]);
});

it('filters the table to the union of multiple selected issue types', function () {
    $catalog = actingAsCatalogUser();
    $missingImages = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $missingImages->categories()->attach(Category::factory()->create(['catalog_id' => $catalog->id]));

    $missingCategories = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $missingCategories->addMedia(UploadedFile::fake()->image('photo.jpg'))->preservingOriginal()->toMediaCollection('images');

    Livewire::test(NeedsAttention::class)
        ->filterTable('issue', ['types' => ['missing_images', 'missing_categories']])
        ->assertCanSeeTableRecords([$missingImages, $missingCategories]);
});

it('restores the full needs-attention list once the issue filter is cleared', function () {
    $catalog = actingAsCatalogUser();
    $missingImages = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $missingImages->categories()->attach(Category::factory()->create(['catalog_id' => $catalog->id]));

    $missingCategories = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);
    $missingCategories->addMedia(UploadedFile::fake()->image('photo.jpg'))->preservingOriginal()->toMediaCollection('images');

    Livewire::test(NeedsAttention::class)
        ->filterTable('issue', ['types' => ['missing_images']])
        ->assertCanNotSeeTableRecords([$missingCategories])
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$missingImages, $missingCategories]);
});

it('resolves a row record url to the product edit page', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => null]);

    $test = Livewire::test(NeedsAttention::class);

    expect($test->instance()->getTable()->getRecordUrl($product))
        ->toBe(EditProduct::getUrl(['record' => $product]));
});
