<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Modules\Attributes\Filament\Resources\AttributeSets\AttributeSetResource;
use Modules\Attributes\Filament\Resources\AttributeSets\Pages\CreateAttributeSet;
use Modules\Attributes\Filament\Resources\AttributeSets\Pages\EditAttributeSet;
use Modules\Attributes\Filament\Resources\AttributeSets\Pages\ListAttributeSets;
use Modules\Attributes\Filament\Resources\AttributeSets\RelationManagers\AttributesRelationManager;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;

it('does not resolve an attribute set belonging to a different catalog on the edit page', function () {
    $otherCatalog = Catalog::factory()->create();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    expect(fn () => Livewire::test(EditAttributeSet::class, ['record' => $attributeSet->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('does not list attribute sets belonging to a different catalog', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreign = AttributeSet::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    Livewire::test(ListAttributeSets::class)
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('requires a name and a slug', function () {
    actingAsCatalogUser();

    Livewire::test(CreateAttributeSet::class)
        ->fillForm(['name' => '', 'slug' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'slug' => 'required']);
});

it('requires the slug to be unique within the same catalog', function () {
    $catalog = actingAsCatalogUser();
    AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'slug' => 'apparel']);

    Livewire::test(CreateAttributeSet::class)
        ->fillForm(['name' => 'Other Apparel', 'slug' => 'apparel'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('derives the slug from the name only on create', function () {
    actingAsCatalogUser();

    Livewire::test(CreateAttributeSet::class)
        ->fillForm(['name' => 'Footwear'])
        ->assertSchemaStateSet(['slug' => 'footwear']);
});

it('does not overwrite an existing slug when the name changes on edit', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create([
        'catalog_id' => $catalog->id,
        'name' => 'Footwear',
        'slug' => 'custom-slug',
    ]);

    Livewire::test(EditAttributeSet::class, ['record' => $attributeSet->getRouteKey()])
        ->fillForm(['name' => 'Shoes'])
        ->assertSchemaStateSet(['slug' => 'custom-slug']);
});

it('finds an attribute set via global search', function () {
    $catalog = actingAsCatalogUser();
    AttributeSet::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Very Unique Set Name']);

    $results = AttributeSetResource::getGlobalSearchResults('Very Unique Set Name');

    expect($results->pluck('title'))->toContain('Very Unique Set Name');
});

it('renders only the attributes currently attached to the given attribute set', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attached = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $unattached = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attached->id, ['position' => 0, 'is_required' => false]);

    Livewire::test(AttributesRelationManager::class, [
        'ownerRecord' => $attributeSet,
        'pageClass' => EditAttributeSet::class,
    ])
        ->assertCanSeeTableRecords([$attached])
        ->assertCanNotSeeTableRecords([$unattached]);
});

it('attaches an attribute with the submitted is_required value', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(AttributesRelationManager::class, [
        'ownerRecord' => $attributeSet,
        'pageClass' => EditAttributeSet::class,
    ])
        ->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $attribute->id,
            'is_required' => true,
        ])
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('attribute_set_attributes', [
        'attribute_set_id' => $attributeSet->id,
        'attribute_id' => $attribute->id,
        'is_required' => true,
    ]);
});

it('assigns incrementing positions as attributes are attached', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $first = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $second = Attribute::factory()->create(['catalog_id' => $catalog->id]);

    $relationManager = Livewire::test(AttributesRelationManager::class, [
        'ownerRecord' => $attributeSet,
        'pageClass' => EditAttributeSet::class,
    ]);

    $relationManager->callAction(TestAction::make('attach')->table(), data: ['recordId' => $first->id, 'is_required' => false]);
    $relationManager->callAction(TestAction::make('attach')->table(), data: ['recordId' => $second->id, 'is_required' => false]);

    expect($attributeSet->attributes()->find($first->id)->pivot->position)->toBe(0)
        ->and($attributeSet->attributes()->find($second->id)->pivot->position)->toBe(1);
});

it('does not attach the same attribute to the same attribute set twice', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    expect(fn () => $attributeSet->attributes()->attach($attribute->id, ['position' => 1, 'is_required' => true]))
        ->toThrow(QueryException::class);

    expect($attributeSet->attributes()->count())->toBe(1);
});

it('reorders attachments for one attribute set without affecting another', function () {
    $catalog = actingAsCatalogUser();
    $setA = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $setB = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $first = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $second = Attribute::factory()->create(['catalog_id' => $catalog->id]);

    $setA->attributes()->attach($first->id, ['position' => 0, 'is_required' => false]);
    $setA->attributes()->attach($second->id, ['position' => 1, 'is_required' => false]);
    $setB->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    Livewire::test(AttributesRelationManager::class, [
        'ownerRecord' => $setA,
        'pageClass' => EditAttributeSet::class,
    ])->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($setA->attributes()->find($second->id)->pivot->position)->toBe(1)
        ->and($setA->attributes()->find($first->id)->pivot->position)->toBe(2)
        ->and($setB->attributes()->find($attribute->id)->pivot->position)->toBe(0);
});

it('updates only the pivot is_required value via the edit requirement action', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'label' => 'Color', 'code' => 'color']);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    Livewire::test(AttributesRelationManager::class, [
        'ownerRecord' => $attributeSet,
        'pageClass' => EditAttributeSet::class,
    ])
        ->callAction(TestAction::make('editRequirement')->table($attribute), data: ['is_required' => true]);

    expect($attributeSet->attributes()->find($attribute->id)->pivot->is_required)->toBe(1);

    $attribute->refresh();
    expect($attribute->label)->toBe('Color')
        ->and($attribute->code)->toBe('color');
});

it('detaching an attribute removes the pivot row but leaves the attribute record intact', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    Livewire::test(AttributesRelationManager::class, [
        'ownerRecord' => $attributeSet,
        'pageClass' => EditAttributeSet::class,
    ])->callAction(TestAction::make('detach')->table($attribute));

    $this->assertDatabaseMissing('attribute_set_attributes', [
        'attribute_set_id' => $attributeSet->id,
        'attribute_id' => $attribute->id,
    ]);
    $this->assertDatabaseHas('attributes', ['id' => $attribute->id]);
});

it('excludes an attribute from a different catalog from the attach record select', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreignAttribute = Attribute::factory()->create(['catalog_id' => $otherCatalog->id]);

    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(AttributesRelationManager::class, [
        'ownerRecord' => $attributeSet,
        'pageClass' => EditAttributeSet::class,
    ])->callAction(TestAction::make('attach')->table(), data: [
        'recordId' => $foreignAttribute->id,
        'is_required' => false,
    ])->assertHasActionErrors(['recordId']);

    $this->assertDatabaseMissing('attribute_set_attributes', [
        'attribute_set_id' => $attributeSet->id,
        'attribute_id' => $foreignAttribute->id,
    ]);
});
