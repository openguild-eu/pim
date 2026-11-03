<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Modules\Attributes\Enums\AttributeType;
use Modules\Attributes\Filament\Resources\Attributes\Pages\CreateAttribute;
use Modules\Attributes\Filament\Resources\Attributes\Pages\EditAttribute;
use Modules\Attributes\Filament\Resources\Attributes\Pages\ListAttributes;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Attributes\Models\AttributeValue;
use Modules\Products\Models\Product;

it('does not resolve an attribute belonging to a different catalog on the edit page', function () {
    $otherCatalog = Catalog::factory()->create();
    $attribute = Attribute::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    expect(fn () => Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('does not list attributes belonging to a different catalog', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreign = Attribute::factory()->create(['catalog_id' => $otherCatalog->id]);

    actingAsCatalogUser();

    Livewire::test(ListAttributes::class)
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('requires code, label, and type', function () {
    actingAsCatalogUser();

    Livewire::test(CreateAttribute::class)
        ->fillForm(['code' => '', 'label' => '', 'type' => null])
        ->call('create')
        ->assertHasFormErrors(['code' => 'required', 'label' => 'required', 'type' => 'required']);
});

it('requires the code to be alpha-dash', function () {
    actingAsCatalogUser();

    Livewire::test(CreateAttribute::class)
        ->fillForm(['code' => 'not a code!', 'label' => 'Color', 'type' => 'text'])
        ->call('create')
        ->assertHasFormErrors(['code' => 'alpha_dash']);
});

it('requires the code to be unique within the same catalog', function () {
    $catalog = actingAsCatalogUser();
    Attribute::factory()->create(['catalog_id' => $catalog->id, 'code' => 'color']);

    Livewire::test(CreateAttribute::class)
        ->fillForm(['code' => 'color', 'label' => 'Another Color', 'type' => 'text'])
        ->call('create')
        ->assertHasFormErrors(['code']);
});

it('allows the same code in two different catalogs', function () {
    $otherCatalog = Catalog::factory()->create();
    Attribute::factory()->create(['catalog_id' => $otherCatalog->id, 'code' => 'color']);

    actingAsCatalogUser();

    Livewire::test(CreateAttribute::class)
        ->fillForm(['code' => 'color', 'label' => 'Color', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('attributes', ['code' => 'color']);
});

it('does not show or require options for a text attribute', function () {
    actingAsCatalogUser();

    Livewire::test(CreateAttribute::class)
        ->fillForm(['code' => 'material', 'label' => 'Material', 'type' => 'text'])
        ->assertFormFieldDoesNotExist('options')
        ->call('create')
        ->assertHasNoFormErrors();
});

it('requires at least one option for a select attribute', function () {
    actingAsCatalogUser();

    Livewire::test(CreateAttribute::class)
        ->fillForm(['code' => 'color', 'label' => 'Color', 'type' => 'select', 'options' => []])
        ->call('create')
        ->assertHasFormErrors(['options']);
});

it('persists submitted select options as the options json array', function () {
    actingAsCatalogUser();

    Livewire::test(CreateAttribute::class)
        ->fillForm([
            'code' => 'color',
            'label' => 'Color',
            'type' => 'select',
            'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'blue', 'label' => 'Blue'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $attribute = Attribute::query()->where('code', 'color')->first();

    expect($attribute->options)->toBe([
        ['value' => 'red', 'label' => 'Red'],
        ['value' => 'blue', 'label' => 'Blue'],
    ]);
});

it('discards stale select options when the type is switched back to text before saving', function () {
    actingAsCatalogUser();

    Livewire::test(CreateAttribute::class)
        ->fillForm([
            'code' => 'color',
            'label' => 'Color',
            'type' => 'select',
            'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'blue', 'label' => 'Blue'],
            ],
        ])
        ->fillForm(['type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();

    $attribute = Attribute::query()->where('code', 'color')->first();

    expect($attribute->options)->toBeNull();
});

it('hydrates the options repeater when editing an existing select attribute', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create([
        'catalog_id' => $catalog->id,
        'type' => AttributeType::Select,
        'options' => [['value' => 'red', 'label' => 'Red']],
    ]);

    // Repeater state is keyed by a generated UUID per row, not a
    // sequential index, so compare values() rather than the raw array.
    Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
        ->assertSchemaStateSet(function (array $state): array {
            expect(array_values($state['options']))->toBe([['value' => 'red', 'label' => 'Red']]);

            return [];
        });
});

it('disables the type field once a value is stored for the attribute', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id, 'type' => AttributeType::Text]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    AttributeValue::create([
        'catalog_id' => $catalog->id,
        'product_id' => $product->id,
        'attribute_id' => $attribute->id,
        'value_text' => 'red',
    ]);

    Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
        ->assertFormFieldExists('type', function (Select $field): bool {
            return $field->isDisabled();
        });
});

it('leaves the type field enabled when no value is stored yet', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
        ->assertFormFieldExists('type', function (Select $field): bool {
            return ! $field->isDisabled();
        });
});

it('deletes an attribute that is not attached to any attribute set', function () {
    $catalog = actingAsCatalogUser();
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ListAttributes::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($attribute));

    $this->assertDatabaseMissing('attributes', ['id' => $attribute->id]);
});

it('refuses to delete an attribute still attached to an attribute set', function () {
    $catalog = actingAsCatalogUser();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $attribute = Attribute::factory()->create(['catalog_id' => $catalog->id]);
    $attributeSet->attributes()->attach($attribute->id, ['position' => 0, 'is_required' => false]);

    // No custom delete-blocking guard exists for Attribute (per the locked
    // design) — the pivot's restrictOnDelete() FK is the only enforcement,
    // so the table's own Delete action surfaces the raw QueryException.
    expect(fn () => Livewire::test(ListAttributes::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($attribute)))
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('attributes', ['id' => $attribute->id]);
});
