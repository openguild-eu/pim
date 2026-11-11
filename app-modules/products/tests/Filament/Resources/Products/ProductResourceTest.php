<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;
use Livewire\Livewire;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Attributes\Models\AttributeValue;
use Modules\Categories\Models\Category;
use Modules\Products\Enums\ProductStatus;
use Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use Modules\Products\Filament\Resources\Products\Pages\ListProducts;
use Modules\Products\Filament\Resources\Products\Pages\ManageProductImages;
use Modules\Products\Filament\Resources\Products\ProductResource;
use Modules\Products\Models\Product;

it('does not resolve a product belonging to a different catalog on the edit page', function () {
    $otherCatalog = Catalog::factory()->create();
    $product = Product::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    expect(fn () => Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('does not list products belonging to a different catalog', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreign = Product::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    Livewire::test(ListProducts::class)
        ->assertCanNotSeeTableRecords([$foreign]);
});

dataset('product field validation', [
    'sku is required' => ['sku', '', 'required'],
    'sku rejects more than 255 characters' => ['sku', str_repeat('a', 256), 'max'],
    'sku accepts exactly 255 characters' => ['sku', str_repeat('a', 255), null],
    'name is required' => ['name', '', 'required'],
    'name rejects more than 255 characters' => ['name', str_repeat('a', 256), 'max'],
    'name accepts exactly 255 characters' => ['name', str_repeat('a', 255), null],
    'slug is required' => ['slug', '', 'required'],
    'slug rejects more than 255 characters' => ['slug', str_repeat('a', 256), 'max'],
    'slug accepts exactly 255 characters' => ['slug', str_repeat('a', 255), null],
    'slug rejects characters that are not alpha-dash' => ['slug', 'not a slug!', 'alpha_dash'],
    'status is required' => ['status', null, 'required'],
    'status rejects a value outside the ProductStatus enum' => ['status', 'archived', Enum::class],
    'price rejects a negative value' => ['price', -1, 'min'],
    'price accepts null' => ['price', null, null],
]);

it('validates product form fields', function (string $field, mixed $value, ?string $expectedRule) {
    actingAsCatalogUser();

    $test = Livewire::test(CreateProduct::class)
        ->fillForm([
            'sku' => 'SKU-1',
            'name' => 'Boots',
            'slug' => 'boots',
            'status' => ProductStatus::Draft->value,
            'price' => 1999,
            $field => $value,
        ])
        ->call('create');

    if ($expectedRule === null) {
        $test->assertHasNoFormErrors();

        return;
    }

    $test->assertHasFormErrors([$field => $expectedRule]);
})->with('product field validation');

it('requires the sku to be unique within the same catalog', function () {
    $catalog = actingAsCatalogUser();
    Product::factory()->create(['catalog_id' => $catalog->id, 'sku' => 'SKU-1']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['sku' => 'SKU-1', 'name' => 'Other Boots', 'slug' => 'other-boots', 'status' => 'draft'])
        ->call('create')
        ->assertHasFormErrors(['sku']);
});

it('requires the slug to be unique within the same catalog', function () {
    $catalog = actingAsCatalogUser();
    Product::factory()->create(['catalog_id' => $catalog->id, 'slug' => 'boots']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['sku' => 'SKU-2', 'name' => 'Other Boots', 'slug' => 'boots', 'status' => 'draft'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('allows the same sku and slug in two different catalogs', function () {
    $otherCatalog = Catalog::factory()->create();
    Product::factory()->create(['catalog_id' => $otherCatalog->id, 'sku' => 'SKU-1', 'slug' => 'boots']);

    actingAsCatalogUser();

    Livewire::test(CreateProduct::class)
        ->fillForm(['sku' => 'SKU-1', 'name' => 'Boots', 'slug' => 'boots', 'status' => 'draft'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('products', ['sku' => 'SKU-1', 'slug' => 'boots']);
});

it('derives the slug from the name only on create', function () {
    actingAsCatalogUser();

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Running Shoes'])
        ->assertSchemaStateSet(['slug' => 'running-shoes']);
});

it('does not overwrite an existing slug when the name changes on edit', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Boots', 'slug' => 'custom-slug']);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'Big Boots'])
        ->assertSchemaStateSet(['slug' => 'custom-slug']);
});

it('renders the status column as a badge with the color resolved per record', function () {
    $catalog = actingAsCatalogUser();
    $draft = Product::factory()->create(['catalog_id' => $catalog->id, 'status' => ProductStatus::Draft]);
    $published = Product::factory()->create(['catalog_id' => $catalog->id, 'status' => ProductStatus::Published]);

    $test = Livewire::test(ListProducts::class);
    $column = $test->instance()->getTable()->getColumn('status');

    $column->record($test->instance()->getTableRecord((string) $draft->getKey()));
    expect($column->getColor($column->getState()))->toBe('gray');

    $column->record($test->instance()->getTableRecord((string) $published->getKey()));
    expect($column->getColor($column->getState()))->toBe('success');
});

it('does not write to a product in a different catalog when the edit form is submitted directly', function () {
    $otherCatalog = Catalog::factory()->create();
    $product = Product::factory()->create(['catalog_id' => $otherCatalog->id, 'name' => 'Original Name']);

    actingAsCatalogUser();

    expect(fn () => Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'Hacked Name'])
        ->call('save'))
        ->toThrow(ModelNotFoundException::class);

    expect($product->refresh()->name)->toBe('Original Name');
});

it('configures the attribute_set_id select as live so the attributes section reacts to changes', function () {
    actingAsCatalogUser();

    Livewire::test(CreateProduct::class)
        ->assertSchemaComponentExists(
            'attribute_set_id',
            checkComponentUsing: fn (Select $component): bool => $component->isLive(),
        );
});

it('keeps the Attributes sidebar fixed-width while the product details column grows', function () {
    actingAsCatalogUser();

    $test = Livewire::test(CreateProduct::class);

    $section = collect($test->instance()->getSchema('form')->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Section && $component->getHeading() === 'Attributes');

    expect($section)->not->toBeNull()
        ->and($section->canGrow())->toBeFalse();
});

it('persists category assignment on create', function () {
    $catalog = actingAsCatalogUser();
    $categoryA = Category::factory()->create(['catalog_id' => $catalog->id]);
    $categoryB = Category::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'sku' => 'SKU-1',
            'name' => 'Boots',
            'slug' => 'boots',
            'status' => 'draft',
            'categories' => [$categoryA->id, $categoryB->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('sku', 'SKU-1')->first();

    expect($product->categories()->pluck('categories.id')->sort()->values()->all())
        ->toBe([$categoryA->id, $categoryB->id]);
});

it('replaces category assignment on edit rather than merging it', function () {
    $catalog = actingAsCatalogUser();
    $categoryA = Category::factory()->create(['catalog_id' => $catalog->id]);
    $categoryB = Category::factory()->create(['catalog_id' => $catalog->id]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $product->categories()->attach($categoryA->id);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['categories' => [$categoryB->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->categories()->pluck('categories.id')->all())->toBe([$categoryB->id]);
});

it('displays assigned category names joined by a comma in the table', function () {
    $catalog = actingAsCatalogUser();
    $categoryA = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Alpha']);
    $categoryB = Category::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Beta']);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $product->categories()->attach([$categoryA->id, $categoryB->id]);

    // No ordering is specified by the blueprint for this column — assert
    // the set of names rendered, not a particular pivot retrieval order.
    $test = Livewire::test(ListProducts::class);
    $column = $test->instance()->getTable()->getColumn('categories');
    $column->record($test->instance()->getTableRecord((string) $product->getKey()));

    expect(explode(', ', $column->getState()))->toEqualCanonicalizing(['Alpha', 'Beta']);
});

it('filters by trashed products', function () {
    $catalog = actingAsCatalogUser();
    $live = Product::factory()->create(['catalog_id' => $catalog->id]);
    $trashed = Product::factory()->create(['catalog_id' => $catalog->id]);
    $trashed->delete();

    Livewire::test(ListProducts::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$trashed])
        ->assertCanNotSeeTableRecords([$live]);
});

it('filters by status', function () {
    $catalog = actingAsCatalogUser();
    $draft = Product::factory()->create(['catalog_id' => $catalog->id, 'status' => ProductStatus::Draft]);
    $published = Product::factory()->create(['catalog_id' => $catalog->id, 'status' => ProductStatus::Published]);

    Livewire::test(ListProducts::class)
        ->filterTable('status', 'published')
        ->assertCanSeeTableRecords([$published])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('can sort products by sku, name, and price', function () {
    $catalog = actingAsCatalogUser();
    Product::factory()->count(3)->create(['catalog_id' => $catalog->id]);

    $bySku = Product::query()->orderBy('sku')->get();
    $byName = Product::query()->orderBy('name')->get();
    $byPrice = Product::query()->orderBy('price')->get();

    Livewire::test(ListProducts::class)
        ->sortTable('sku')
        ->assertCanSeeTableRecords($bySku, inOrder: true)
        ->sortTable('name')
        ->assertCanSeeTableRecords($byName, inOrder: true)
        ->sortTable('price')
        ->assertCanSeeTableRecords($byPrice, inOrder: true);
});

it('finds a product by sku or slug via global search', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'sku' => 'UNIQUE-SKU-1', 'slug' => 'unique-slug-1']);

    $bySku = ProductResource::getGlobalSearchResults('UNIQUE-SKU-1');
    $bySlug = ProductResource::getGlobalSearchResults('unique-slug-1');

    expect($bySku->pluck('title'))->toContain($product->name)
        ->and($bySlug->pluck('title'))->toContain($product->name);
});

it('soft-deletes a product and hides it from the default list', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($product));

    $this->assertSoftDeleted($product);
    Livewire::test(ListProducts::class)->assertCanNotSeeTableRecords([$product]);
});

it('restoring a soft-deleted product makes it visible again', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $product->delete();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction(TestAction::make(RestoreAction::class));

    $this->assertNotSoftDeleted($product);
    Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$product]);
});

it('required attribute fields reflect that attribute set\'s own pivot is_required value', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material']);
    $setRequiring = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setNotRequiring = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setRequiring->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => true]);
    $setNotRequiring->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    Livewire::test(CreateProduct::class)
        ->fillForm(['attribute_set_id' => $setRequiring->id])
        ->assertFormFieldExists('attribute_values.material', fn (Field $field): bool => $field->isRequired())
        ->fillForm(['attribute_set_id' => $setNotRequiring->id])
        ->assertFormFieldExists('attribute_values.material', fn (Field $field): bool => ! $field->isRequired());
});

it('switching attribute sets away and back preserves the original set\'s saved values and keeps both sets\' data', function () {
    $catalog = actingAsCatalogUser();
    $colorAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);
    $materialAttribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'material']);
    $setA = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setB = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setA->attributes()->attach($colorAttribute->id, ['position' => 0, 'is_required' => false]);
    $setB->attributes()->attach($materialAttribute->id, ['position' => 0, 'is_required' => false]);

    // Create under set A, filling A's attribute.
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'sku' => 'SKU-1',
            'name' => 'Boots',
            'slug' => 'boots',
            'status' => 'draft',
            'attribute_set_id' => $setA->id,
            'attribute_values' => ['color' => 'red'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('sku', 'SKU-1')->first();

    // Switch to set B and fill B's attribute.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'attribute_set_id' => $setB->id,
            'attribute_values' => ['material' => 'cotton'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // Both sets' AttributeValue rows must now exist, independently.
    $colorValue = AttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $colorAttribute->id)->first();
    $materialValue = AttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $materialAttribute->id)->first();

    expect($colorValue?->value_text)->toBe('red')
        ->and($materialValue?->value_text)->toBe('cotton');

    // Switching back to A re-hydrates A's original, untouched value.
    // mutateFormDataBeforeFill() hydrates every still-present
    // AttributeValue row regardless of which set is now selected (per the
    // "old rows are never touched" decision) — the Attributes section's
    // own visibility then hides whichever fields aren't in the current
    // set, so only `color`'s value, not the full state shape, is the
    // thing under test here.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['attribute_set_id' => $setA->id])
        ->assertSchemaStateSet(function (array $state): void {
            expect($state['attribute_values']['color'])->toBe('red');
        });
});

it('silently skips an attribute_values code that does not belong to the current attribute set and still saves the product', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id, 'attribute_set_id' => $attributeSet->id]);

    // "nonexistent_code" belongs to no Attribute on this (or any)
    // AttributeSet — persistAttributeValues()'s `if (! $attribute)
    // continue;` guard must skip it rather than erroring, while the
    // product's own fillable columns still save correctly.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'name' => 'Renamed Product',
            'attribute_values' => ['nonexistent_code' => 'tampered'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Renamed Product']);
    $this->assertDatabaseMissing('attribute_values', ['product_id' => $product->id, 'value_text' => 'tampered']);
});

it('persists an uploaded image and synchronously generates the thumb conversion', function () {
    Storage::fake('public');
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ManageProductImages::class, ['record' => $product->getRouteKey()])
        ->fillForm(['images' => [UploadedFile::fake()->image('photo.jpg')]])
        ->call('save')
        ->assertHasNoFormErrors();

    $media = $product->refresh()->getFirstMedia('images');

    expect($media)->not->toBeNull()
        ->and($media->hasGeneratedConversion('thumb'))->toBeTrue();
});

it('retrieves uploaded images in upload order with the first as primary', function () {
    Storage::fake('public');
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ManageProductImages::class, ['record' => $product->getRouteKey()])
        ->fillForm(['images' => [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    // FileUpload stores uploads under a generated file name, so identity
    // can't be asserted by original name — only that three distinct media
    // rows exist in strictly ascending order_column order (upload order).
    $media = $product->refresh()->getMedia('images');

    expect($media)->toHaveCount(3)
        ->and($media->pluck('order_column')->all())->toBe([1, 2, 3]);
});

it('changes the primary image when media is reordered via the Images page form field and saved', function () {
    Storage::fake('public');
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $mediaA = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->preservingOriginal()->toMediaCollection('images');
    $mediaB = $product->addMedia(UploadedFile::fake()->image('b.jpg'))->preservingOriginal()->toMediaCollection('images');
    $mediaC = $product->addMedia(UploadedFile::fake()->image('c.jpg'))->preservingOriginal()->toMediaCollection('images');

    $test = Livewire::test(ManageProductImages::class, ['record' => $product->getRouteKey()]);

    // The `images` field hydrates from the record's media relationship on
    // mount, in ascending order_column order (a, b, c). Drive the reorder
    // through the actual SpatieMediaLibraryFileUpload field's exposed
    // `reorderUploadedFiles()` method — the same method the field's
    // `->reorderable()` drag-and-drop calls client-side — rather than
    // mutating `order_column` directly.
    $imagesField = $test->instance()->getSchema('form')->getFlatComponents(withHidden: true)['images'];

    $test->call('callSchemaComponentMethod', $imagesField->getKey(), 'reorderUploadedFiles', [[
        $mediaC->uuid, $mediaB->uuid, $mediaA->uuid,
    ]])
        ->call('save')
        ->assertHasNoFormErrors();

    // Assert the actual persisted order_column values, not just the
    // in-memory form/UI state.
    expect($mediaC->refresh()->order_column)->toBe(1)
        ->and($mediaB->refresh()->order_column)->toBe(2)
        ->and($mediaA->refresh()->order_column)->toBe(3);

    $product->refresh();

    expect($product->getMedia('images')->first()->is($mediaC))->toBeTrue()
        ->and($product->getFirstMedia('images')->file_name)->toBe('c.jpg');
});

it('renders the leading images table column with the first-in-order media thumbnail after a 3-image upload', function () {
    Storage::fake('public');
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ManageProductImages::class, ['record' => $product->getRouteKey()])
        ->fillForm(['images' => [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();
    $firstMedia = $product->getMedia('images')->first();

    $test = Livewire::test(ListProducts::class);
    $column = $test->instance()->getTable()->getColumn('images');
    $column->record($test->instance()->getTableRecord((string) $product->getKey()));

    $state = $column->getState();

    // The column resolves a signed/temporary URL rather than a bare path
    // (per the default disk-based visibility rules in Filament's
    // ImageColumn), so compare by the underlying thumb-conversion file
    // name rather than the full URL string.
    expect($state[0])->toBe($firstMedia->uuid)
        ->and($column->getImageUrl($state[0]))->toContain(pathinfo($firstMedia->getPath('thumb'), PATHINFO_BASENAME));
});

it('does not attach media to a product in a different catalog when the images page form is submitted directly', function () {
    Storage::fake('public');
    $otherCatalog = Catalog::factory()->create();
    $product = Product::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    expect(fn () => Livewire::test(ManageProductImages::class, ['record' => $product->getRouteKey()])
        ->fillForm(['images' => [UploadedFile::fake()->image('intrusion.jpg')]])
        ->call('save'))
        ->toThrow(ModelNotFoundException::class);

    expect($product->refresh()->getMedia('images'))->toHaveCount(0);
});

it('configures the images field as a reorderable, multiple, image-only SpatieMediaLibraryFileUpload bound to the images collection', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ManageProductImages::class, ['record' => $product->getRouteKey()])
        ->assertSchemaComponentExists(
            'images',
            checkComponentUsing: fn (SpatieMediaLibraryFileUpload $component): bool => $component->getCollection() === 'images'
                && $component->isMultiple()
                && $component->isReorderable()
                && in_array('image/*', $component->getAcceptedFileTypes() ?? [], true),
        );
});

it('configures the leading images table column as a SpatieMediaLibraryImageColumn bound to the images collection with the thumb conversion limited to one image', function () {
    actingAsCatalogUser();

    Livewire::test(ListProducts::class)
        ->assertTableColumnExists(
            'images',
            fn (SpatieMediaLibraryImageColumn $column): bool => $column->getCollection() === 'images'
                && $column->getConversion() === 'thumb'
                && $column->getLimit() === 1,
        );
});

it('shows an Images sub-navigation tab on the product Edit page, alongside Related Products', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    $test = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);

    $test->assertOk();

    $subNavigationLabels = collect(
        ProductResource::getRecordSubNavigation($test->instance())
    )->map(fn ($item) => $item->getLabel())->all();

    expect($subNavigationLabels)->toContain('Images', 'Related Products');
});
