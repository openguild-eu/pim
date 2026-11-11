<?php

declare(strict_types=1);

use App\Filament\Widgets\ChannelDistributionChart;
use Livewire\Livewire;
use Modules\Channels\Models\Channel;
use Modules\Products\Models\Product;

it('builds published/unpublished counts per channel, using ReflectionMethod since getData() is protected', function () {
    $catalog = actingAsCatalogUser();
    $channel = Channel::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Webshop']);
    $published = Product::factory()->create(['catalog_id' => $catalog->id]);
    $unpublished = Product::factory()->create(['catalog_id' => $catalog->id]);

    $channel->products()->attach($published->id, ['is_published' => true]);
    $channel->products()->attach($unpublished->id, ['is_published' => false]);

    $widget = Livewire::test(ChannelDistributionChart::class)->instance();
    $data = (new ReflectionMethod($widget, 'getData'))->invoke($widget);

    expect($data['labels'])->toBe(['Webshop']);
    expect($data['datasets'][0]['data'])->toBe([1]); // published
    expect($data['datasets'][1]['data'])->toBe([1]); // unpublished
});
