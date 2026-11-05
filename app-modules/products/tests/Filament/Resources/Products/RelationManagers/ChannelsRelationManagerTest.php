<?php

declare(strict_types=1);

use App\Models\Catalog;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Channels\Models\Channel;
use Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use Modules\Products\Filament\Resources\Products\RelationManagers\ChannelsRelationManager;
use Modules\Products\Models\Product;

it('excludes a channel from a different catalog from the attach record select search results', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreignChannel = Channel::factory()->create([
        'catalog_id' => $otherCatalog->id,
        'name' => 'Foreign Marketplace',
    ]);

    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $ownChannel = Channel::factory()->create([
        'catalog_id' => $catalog->id,
        'name' => 'Own Marketplace',
    ]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('attach')
        ->assertFormFieldExists('recordId', function (Select $field) use ($ownChannel, $foreignChannel): bool {
            $options = $field->getSearchResults('Marketplace');

            return array_key_exists($ownChannel->id, $options)
                && ! array_key_exists($foreignChannel->id, $options);
        });
});

it('excludes a channel from a different catalog from the attach record select', function () {
    $otherCatalog = Catalog::factory()->create();
    $foreignChannel = Channel::factory()->create(['catalog_id' => $otherCatalog->id]);

    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->callAction(TestAction::make('attach')->table(), data: [
        'recordId' => $foreignChannel->id,
        'is_published' => false,
        'published_at' => null,
    ])->assertHasActionErrors(['recordId']);

    $this->assertDatabaseMissing('channel_product', [
        'channel_id' => $foreignChannel->id,
        'product_id' => $product->id,
    ]);
});

it('sets published_at to a fresh timestamp when attaching with is_published on', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->callAction(TestAction::make('attach')->table(), data: [
        'recordId' => $channel->id,
        'is_published' => true,
        'published_at' => now(),
    ])->assertHasNoFormErrors();

    $this->assertDatabaseHas('channel_product', [
        'channel_id' => $channel->id,
        'product_id' => $product->id,
        'is_published' => true,
        'published_at' => '2026-01-01 10:00:00',
    ]);

    Carbon::setTestNow();
});

it('leaves published_at null when attaching with is_published left off', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->callAction(TestAction::make('attach')->table(), data: [
        'recordId' => $channel->id,
        'is_published' => false,
        'published_at' => null,
    ])->assertHasNoFormErrors();

    $this->assertDatabaseHas('channel_product', [
        'channel_id' => $channel->id,
        'product_id' => $product->id,
        'is_published' => false,
        'published_at' => null,
    ]);
});

it('toggling is_published off via the inline toggle clears published_at', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);
    $product->channels()->attach($channel->id, ['is_published' => true, 'published_at' => now()]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->call('updateTableColumnState', 'is_published', $channel->getKey(), false);

    $this->assertDatabaseHas('channel_product', [
        'channel_id' => $channel->id,
        'product_id' => $product->id,
        'is_published' => false,
        'published_at' => null,
    ]);
});

it('toggling is_published back on via the inline toggle sets a new later published_at', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);
    $product->channels()->attach($channel->id, ['is_published' => true, 'published_at' => now()]);

    Carbon::setTestNow('2026-01-01 12:00:00');

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->call('updateTableColumnState', 'is_published', $channel->getKey(), false)
        ->call('updateTableColumnState', 'is_published', $channel->getKey(), true);

    $pivot = DB::table('channel_product')
        ->where('channel_id', $channel->id)
        ->where('product_id', $product->id)
        ->first();

    expect(\Carbon\Carbon::parse($pivot->published_at)->equalTo('2026-01-01 12:00:00'))->toBeTrue();

    Carbon::setTestNow();
});

it('detaching a channel removes only that pivot row', function () {
    $catalog = actingAsCatalogUser();
    $product = Product::factory()->create(['catalog_id' => $catalog->id]);
    $otherProduct = Product::factory()->create(['catalog_id' => $catalog->id]);
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id]);
    $product->channels()->attach($channel->id, ['is_published' => true, 'published_at' => now()]);
    $otherProduct->channels()->attach($channel->id, ['is_published' => true, 'published_at' => now()]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->callAction(TestAction::make('detach')->table($channel));

    $this->assertDatabaseMissing('channel_product', ['channel_id' => $channel->id, 'product_id' => $product->id]);
    $this->assertDatabaseHas('channel_product', ['channel_id' => $channel->id, 'product_id' => $otherProduct->id]);
    $this->assertDatabaseHas('channels', ['id' => $channel->id]);
});
