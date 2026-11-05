<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\ImportAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Categories\Models\Category;
use Modules\Products\Filament\Imports\ProductImporter;
use Modules\Products\Filament\Resources\Products\Pages\ListProducts;
use Modules\Products\Models\Product;

/**
 * Importer::fillRecord() skips any column whose key is entirely absent
 * from the row — not just blank — including skipping its own
 * fillRecordUsing() closure (confirmed in
 * vendor/filament/actions/src/Imports/Importer.php: `if (!
 * array_key_exists($columnName, $this->data)) { continue; }`). Every
 * declared column must be present, even as '', to exercise the real
 * "mapped but blank cell" behavior instead of the "unmapped column"
 * skip path.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productImportRow(array $overrides = []): array
{
    return array_merge([
        'sku' => 'SKU-1',
        'name' => 'Running Shoes',
        'slug' => '',
        'status' => 'draft',
        'price' => '',
        'description' => '',
        'category_slugs' => '',
    ], $overrides);
}

it('updates an existing product in place when sku matches', function () {
    $catalog = actingAsCatalogUser();
    $existing = Product::factory()->create(['catalog_id' => $catalog->id, 'sku' => 'SKU-1', 'name' => 'Old Name']);

    $record = ProductImporter::test()->import(productImportRow([
        'name' => 'New Name',
        'status' => 'published',
        'price' => '1999',
        'description' => 'Updated',
    ]))->assertImported()->getRecord();

    expect($record->is($existing))->toBeTrue()
        ->and(Product::query()->count())->toBe(1);

    $existing->refresh();
    expect($existing->name)->toBe('New Name')
        ->and($existing->status->value)->toBe('published')
        ->and($existing->price)->toBe(1999)
        ->and($existing->description)->toBe('Updated');
});

it('creates a new product scoped to the current catalog when sku does not match', function () {
    $catalog = actingAsCatalogUser();

    ProductImporter::test()->import(productImportRow([
        'sku' => 'NEW-SKU',
        'name' => 'Brand New',
    ]))->assertImported();

    $this->assertDatabaseHas('products', ['sku' => 'NEW-SKU', 'catalog_id' => $catalog->id]);
    expect(Product::query()->count())->toBe(1);
});

it('never updates or creates a product in a different catalog, even with a matching sku', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreignProduct = Product::factory()->create(['catalog_id' => $otherCatalog->id, 'sku' => 'SHARED-SKU', 'name' => 'Foreign']);

    $catalog = actingAsCatalogUser();

    ProductImporter::test()->import(productImportRow([
        'sku' => 'SHARED-SKU',
        'name' => 'Local',
    ]))->assertImported();

    $foreignProduct->refresh();
    expect($foreignProduct->name)->toBe('Foreign');
    $this->assertDatabaseHas('products', ['sku' => 'SHARED-SKU', 'catalog_id' => $catalog->id, 'name' => 'Local']);
});

it('derives the slug from the name when the slug cell is blank', function () {
    actingAsCatalogUser();

    $record = ProductImporter::test()->import(productImportRow())
        ->assertImported()->getRecord();

    expect($record->slug)->toBe('running-shoes');
});

it('uses the mapped slug verbatim when the slug cell is not blank', function () {
    actingAsCatalogUser();

    $record = ProductImporter::test()->import(productImportRow(['slug' => 'custom-slug-value']))
        ->assertImported()->getRecord();

    expect($record->slug)->toBe('custom-slug-value');
});

it('attaches only the categories matching a known slug and skips an unknown one', function () {
    $catalog = actingAsCatalogUser();
    $known = Category::factory()->create(['catalog_id' => $catalog->id, 'slug' => 'shoes']);

    $record = ProductImporter::test()->import(productImportRow(['category_slugs' => 'shoes,unknown-slug']))
        ->assertImported()->assertHasNoRowFailure()->getRecord();

    expect($record->categories->pluck('id')->all())->toBe([$known->id]);
});

it('attaches no categories when the category_slugs cell is blank', function () {
    actingAsCatalogUser();

    $record = ProductImporter::test()->import(productImportRow())
        ->assertImported()->getRecord();

    expect($record->categories)->toBeEmpty();
});

it('treats a category slug belonging to a different catalog as unknown', function () {
    $otherCatalog = Catalog::factory()->create();
    Category::factory()->create(['catalog_id' => $otherCatalog->id, 'slug' => 'shoes']);

    actingAsCatalogUser();

    $record = ProductImporter::test()->import(productImportRow(['category_slugs' => 'shoes']))
        ->assertImported()->getRecord();

    expect($record->categories)->toBeEmpty();
});

it('stores the price cell as raw integer cents, not a dollar conversion', function () {
    actingAsCatalogUser();

    $record = ProductImporter::test()->import(productImportRow(['price' => '1999']))
        ->assertImported()->getRecord();

    expect((int) $record->price)->toBe(1999);
});

it('rounds a decimal price cell as if it were already cents, proving no dollars-to-cents conversion happens', function () {
    actingAsCatalogUser();

    $record = ProductImporter::test()->import(productImportRow(['price' => '19.99']))
        ->assertImported()->getRecord();

    expect((int) $record->price)->toBe(20);
});

it('leaves price null when the price cell is blank', function () {
    actingAsCatalogUser();

    $record = ProductImporter::test()->import(productImportRow())
        ->assertImported()->getRecord();

    expect($record->price)->toBeNull();
});

it('always leaves attribute_set_id null, regardless of other column values', function () {
    actingAsCatalogUser();

    $record = ProductImporter::test()->import(productImportRow(['price' => '999']))
        ->assertImported()->getRecord();

    expect($record->attribute_set_id)->toBeNull();
});

it('imports a sku or name that is exactly at the 255 character max:255 boundary', function (string $field) {
    actingAsCatalogUser();

    $value = str_repeat('a', 255);

    $record = ProductImporter::test()->import(productImportRow([$field => $value]))
        ->assertImported()->getRecord();

    expect($record->{$field})->toBe($value);
})->with(['sku', 'name']);

it('fails validation for an invalid sku, name, or status', function (array $overrides, string $field, string $rule) {
    actingAsCatalogUser();

    ProductImporter::test()->import(productImportRow($overrides))
        ->assertHasErrors([$field => $rule]);
})->with([
    'sku missing' => [['sku' => ''], 'sku', 'required'],
    'sku exceeds the 255 character max:255 boundary' => [['sku' => str_repeat('a', 256)], 'sku', 'max'],
    'name missing' => [['name' => ''], 'name', 'required'],
    'name exceeds the 255 character max:255 boundary' => [['name' => str_repeat('a', 256)], 'name', 'max'],
    'status missing' => [['status' => ''], 'status', 'required'],
    'status outside draft/published' => [['status' => 'archived'], 'status', 'in'],
]);

/**
 * CSV content whose header row exactly matches every declared import
 * column name, so Filament's default header-matching auto-maps every
 * column to itself once uploaded — overridden selectively below to
 * exercise required-mapping behavior.
 */
function productImportCsv(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'products.csv',
        "sku,name,slug,status,price,description,category_slugs\nSKU-1,Running Shoes,,draft,,,\n",
    );
}

it('fails with a form error when the sku column is left unmapped, and dispatches nothing', function () {
    actingAsCatalogUser();
    $imports = ImportAction::fake();

    Livewire::test(ListProducts::class)
        ->mountAction(TestAction::make('import')->table())
        ->fillForm(['file' => productImportCsv()])
        ->fillForm(['columnMap' => [
            'sku' => null,
            'name' => 'name',
            'slug' => 'slug',
            'status' => 'status',
            'price' => 'price',
            'description' => 'description',
            'category_slugs' => 'category_slugs',
        ]])
        ->callMountedAction()
        ->assertHasFormErrors(['columnMap.sku' => 'required']);

    $imports->assertNothingDispatched();
});

it('fails with a form error when the name column is left unmapped, and dispatches nothing', function () {
    actingAsCatalogUser();
    $imports = ImportAction::fake();

    Livewire::test(ListProducts::class)
        ->mountAction(TestAction::make('import')->table())
        ->fillForm(['file' => productImportCsv()])
        ->fillForm(['columnMap' => [
            'sku' => 'sku',
            'name' => null,
            'slug' => 'slug',
            'status' => 'status',
            'price' => 'price',
            'description' => 'description',
            'category_slugs' => 'category_slugs',
        ]])
        ->callMountedAction()
        ->assertHasFormErrors(['columnMap.name' => 'required']);

    $imports->assertNothingDispatched();
});

it('succeeds when the optional slug and category_slugs columns are left unmapped', function () {
    actingAsCatalogUser();
    $imports = ImportAction::fake();

    Livewire::test(ListProducts::class)
        ->mountAction(TestAction::make('import')->table())
        ->fillForm(['file' => productImportCsv()])
        ->fillForm(['columnMap' => [
            'sku' => 'sku',
            'name' => 'name',
            'slug' => null,
            'status' => 'status',
            'price' => 'price',
            'description' => 'description',
            'category_slugs' => null,
        ]])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $imports->assertDispatched(ProductImporter::class);
});
