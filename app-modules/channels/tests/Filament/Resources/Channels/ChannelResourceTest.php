<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Modules\Channels\Filament\Resources\Channels\Pages\CreateChannel;
use Modules\Channels\Filament\Resources\Channels\Pages\EditChannel;
use Modules\Channels\Filament\Resources\Channels\Pages\ListChannels;
use Modules\Channels\Models\Channel;
use Modules\Products\Models\Product;

it('requires a name and a slug', function () {
    actingAsCatalogUser();

    Livewire::test(CreateChannel::class)
        ->fillForm(['name' => '', 'slug' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'slug' => 'required']);
});

it('requires the slug to be unique within the same catalog', function () {
    $catalog = actingAsCatalogUser();
    Channel::factory()->create(['catalog_id' => $catalog->id, 'slug' => 'webshop']);

    Livewire::test(CreateChannel::class)
        ->fillForm(['name' => 'Other Webshop', 'slug' => 'webshop'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('allows the same slug in two different catalogs', function () {
    $otherCatalog = Catalog::factory()->create();
    Channel::factory()->create(['catalog_id' => $otherCatalog->id, 'slug' => 'webshop']);

    actingAsCatalogUser();

    Livewire::test(CreateChannel::class)
        ->fillForm(['name' => 'Webshop', 'slug' => 'webshop'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('channels', ['slug' => 'webshop']);
});

it('requires the slug to be alpha-dash', function () {
    actingAsCatalogUser();

    Livewire::test(CreateChannel::class)
        ->fillForm(['name' => 'Webshop', 'slug' => 'not a slug!'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'alpha_dash']);
});

it('accepts a 255 character name and slug', function () {
    actingAsCatalogUser();

    Livewire::test(CreateChannel::class)
        ->fillForm(['name' => str_repeat('a', 255), 'slug' => str_repeat('a', 255)])
        ->call('create')
        ->assertHasNoFormErrors(['name', 'slug']);
});

it('rejects a name and slug over 255 characters', function () {
    actingAsCatalogUser();

    Livewire::test(CreateChannel::class)
        ->fillForm(['name' => str_repeat('a', 256), 'slug' => str_repeat('a', 256)])
        ->call('create')
        ->assertHasFormErrors(['name' => 'max', 'slug' => 'max']);
});

it('does not overwrite an existing slug when the name changes on edit', function () {
    $catalog = actingAsCatalogUser();
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Webshop', 'slug' => 'custom-slug']);

    Livewire::test(EditChannel::class, ['record' => $channel->getRouteKey()])
        ->fillForm(['name' => 'Online Shop'])
        ->assertSchemaStateSet(['slug' => 'custom-slug']);
});

it('reflects products_count accurately after attaching and detaching products', function () {
    $catalog = actingAsCatalogUser();
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach($product->id, ['is_published' => false]);

    Livewire::test(ListChannels::class)
        ->assertTableColumnStateSet('products_count', '1', $channel);

    $channel->products()->detach($product->id);

    Livewire::test(ListChannels::class)
        ->assertTableColumnStateSet('products_count', '0', $channel);
});

it('hard-deletes a channel with zero products attached', function () {
    $catalog = actingAsCatalogUser();
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ListChannels::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($channel));

    $this->assertDatabaseMissing('channels', ['id' => $channel->id]);
});

it('allows deleting a channel with products still attached, leaving the products untouched', function () {
    $catalog = actingAsCatalogUser();
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach($product->id, ['is_published' => true]);

    Livewire::test(ListChannels::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($channel));

    $this->assertDatabaseMissing('channels', ['id' => $channel->id]);
    $this->assertDatabaseMissing('channel_product', ['channel_id' => $channel->id, 'product_id' => $product->id]);
    $this->assertDatabaseHas('products', ['id' => $product->id]);
});
