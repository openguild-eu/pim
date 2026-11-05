<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Modules\Categories\Filament\Resources\Categories\CategoryResource;
use Modules\Categories\Filament\Resources\Categories\Pages\CreateCategory;
use Modules\Categories\Filament\Resources\Categories\Pages\EditCategory;
use Modules\Categories\Filament\Resources\Categories\Pages\ListCategories;
use Modules\Categories\Filament\Resources\Categories\Pages\ManageSubcategories;
use Modules\Categories\Models\Category;

it('does not resolve a category belonging to a different catalog on the edit page', function () {
    $otherCatalog = Catalog::factory()->create();
    $category = Category::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    expect(fn () => Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('does not list categories belonging to a different catalog', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreignCategory = Category::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    Livewire::test(ListCategories::class)
        ->assertCanNotSeeTableRecords([$foreignCategory]);
});

it('requires a name and a slug', function () {
    actingAsCatalogUser();

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => '', 'slug' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'slug' => 'required']);
});

it('requires the slug to be alpha-dash', function () {
    actingAsCatalogUser();

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Shoes', 'slug' => 'not a slug!'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'alpha_dash']);
});

it('requires the slug to be unique within the same catalog', function () {
    $catalog = actingAsCatalogUser();
    Category::factory()->create(['catalog_id' => $catalog->id, 'slug' => 'shoes']);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Other Shoes', 'slug' => 'shoes'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('allows the same slug in two different catalogs', function () {
    $otherCatalog = Catalog::factory()->create();
    Category::factory()->create(['catalog_id' => $otherCatalog->id, 'slug' => 'shoes']);

    actingAsCatalogUser();

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Shoes', 'slug' => 'shoes'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('categories', ['slug' => 'shoes']);
});

it('accepts a 255 character name and slug', function () {
    actingAsCatalogUser();

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => str_repeat('a', 255), 'slug' => str_repeat('a', 255)])
        ->call('create')
        ->assertHasNoFormErrors(['name', 'slug']);
});

it('rejects a name and slug over 255 characters', function () {
    actingAsCatalogUser();

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => str_repeat('a', 256), 'slug' => str_repeat('a', 256)])
        ->call('create')
        ->assertHasFormErrors(['name' => 'max', 'slug' => 'max']);
});

it('derives the slug from the name only on create', function () {
    actingAsCatalogUser();

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Running Shoes'])
        ->assertSchemaStateSet(['slug' => 'running-shoes']);
});

it('does not overwrite an existing slug when the name changes on edit', function () {
    $catalog = actingAsCatalogUser();
    $category = Category::factory()->create([
        'catalog_id' => $catalog->id,
        'name' => 'Shoes',
        'slug' => 'custom-slug',
    ]);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['name' => 'Running Shoes'])
        ->assertSchemaStateSet(['slug' => 'custom-slug']);
});

it('excludes the record itself and its descendants from the parent select', function () {
    $catalog = actingAsCatalogUser();
    $root = Category::factory()->create(['catalog_id' => $catalog->id]);
    $child = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $root->id]);
    $grandchild = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $child->id]);

    Livewire::test(EditCategory::class, ['record' => $root->getRouteKey()])
        ->fillForm(['parent_id' => $grandchild->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);
});

it('assigns position as max sibling position plus one', function () {
    $catalog = actingAsCatalogUser();
    $parent = Category::factory()->create(['catalog_id' => $catalog->id]);
    Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parent->id, 'position' => 5]);

    $sibling = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parent->id]);

    expect($sibling->position)->toBe(6);
});

it('scopes position independently per parent', function () {
    $catalog = actingAsCatalogUser();
    $parentA = Category::factory()->create(['catalog_id' => $catalog->id]);
    $parentB = Category::factory()->create(['catalog_id' => $catalog->id]);

    $childA = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parentA->id]);
    $childB = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parentB->id]);

    expect($childA->position)->toBe(0)
        ->and($childB->position)->toBe(0);
});

it('soft-deletes a category with no subcategories', function () {
    $catalog = actingAsCatalogUser();
    $category = Category::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($category))
        ->assertNotified();

    $this->assertSoftDeleted($category);
});

it('blocks deleting a category that still has live subcategories', function () {
    $catalog = actingAsCatalogUser();
    $parent = Category::factory()->create(['catalog_id' => $catalog->id]);
    Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parent->id]);

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($parent))
        ->assertNotified();

    $this->assertDatabaseHas('categories', ['id' => $parent->id, 'deleted_at' => null]);
});

it('allows deleting a category whose only subcategories are already trashed', function () {
    $catalog = actingAsCatalogUser();
    $parent = Category::factory()->create(['catalog_id' => $catalog->id]);
    $child = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parent->id]);
    $child->delete();

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($parent));

    $this->assertSoftDeleted($parent);
});

it('blocks the entire bulk delete when any selected record has children', function () {
    $catalog = actingAsCatalogUser();
    $childless = Category::factory()->create(['catalog_id' => $catalog->id]);
    $parentWithChildren = Category::factory()->create(['catalog_id' => $catalog->id]);
    Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parentWithChildren->id]);

    Livewire::test(ListCategories::class)
        ->selectTableRecords([$childless, $parentWithChildren])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified();

    $this->assertDatabaseHas('categories', ['id' => $childless->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('categories', ['id' => $parentWithChildren->id, 'deleted_at' => null]);
});

it('makes a restored category reselectable as a parent after being trashed', function () {
    $catalog = actingAsCatalogUser();
    $other = Category::factory()->create(['catalog_id' => $catalog->id]);
    $category = Category::factory()->create(['catalog_id' => $catalog->id]);
    $category->delete();

    Livewire::test(EditCategory::class, ['record' => $other->getRouteKey()])
        ->fillForm(['parent_id' => $category->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    $category->restore();

    Livewire::test(EditCategory::class, ['record' => $other->getRouteKey()])
        ->fillForm(['parent_id' => $category->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('categories', ['id' => $other->id, 'parent_id' => $category->id]);
});

it('renders only direct children on the subcategories page', function () {
    $catalog = actingAsCatalogUser();
    $parent = Category::factory()->create(['catalog_id' => $catalog->id]);
    $child = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parent->id]);
    $grandchild = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $child->id]);
    $unrelatedRoot = Category::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ManageSubcategories::class, ['record' => $parent->getRouteKey()])
        ->assertCanSeeTableRecords([$child])
        ->assertCanNotSeeTableRecords([$grandchild, $unrelatedRoot]);
});

it('creates a subcategory with the page owner record as its parent', function () {
    $catalog = actingAsCatalogUser();
    $parent = Category::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ManageSubcategories::class, ['record' => $parent->getRouteKey()])
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Sneakers',
            'slug' => 'sneakers',
        ])
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('categories', ['name' => 'Sneakers', 'parent_id' => $parent->id]);
});

it('reorders only among direct children on the subcategories page', function () {
    $catalog = actingAsCatalogUser();
    $parent = Category::factory()->create(['catalog_id' => $catalog->id]);
    $first = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parent->id]);
    $second = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $parent->id]);
    $otherParent = Category::factory()->create(['catalog_id' => $catalog->id]);
    $unrelated = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $otherParent->id, 'position' => 0]);

    Livewire::test(ManageSubcategories::class, ['record' => $parent->getRouteKey()])
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    // Filament's reorderTable() assigns 1-based positions (index + 1) to
    // the records named in $order; it never touches rows outside that set.
    expect($second->refresh()->position)->toBe(1)
        ->and($first->refresh()->position)->toBe(2)
        ->and($unrelated->refresh()->position)->toBe(0);
});

it('filters trashed categories via the trashed filter', function () {
    $catalog = actingAsCatalogUser();
    $live = Category::factory()->create(['catalog_id' => $catalog->id]);
    $trashed = Category::factory()->create(['catalog_id' => $catalog->id]);
    $trashed->delete();

    Livewire::test(ListCategories::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$trashed])
        ->assertCanNotSeeTableRecords([$live]);
});

it('finds a deeply nested category via global search and links to its edit page', function () {
    $catalog = actingAsCatalogUser();
    $root = Category::factory()->create(['catalog_id' => $catalog->id]);
    $nested = Category::factory()->create(['catalog_id' => $catalog->id, 'parent_id' => $root->id, 'name' => 'Deeply Nested Category']);

    $results = CategoryResource::getGlobalSearchResults('Deeply Nested');

    expect($results->pluck('title'))->toContain('Deeply Nested Category')
        ->and($results->first(fn ($result) => $result->title === 'Deeply Nested Category')->url)
        ->toContain((string) $nested->getRouteKey());
});
