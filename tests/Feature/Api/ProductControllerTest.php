<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Attributes\Models\AttributeSet;
use Modules\Categories\Models\Category;
use Modules\Products\Enums\ProductStatus;
use Modules\Products\Models\Product;

it('excludes a product that was never attached to this channel', function () {
    ['channel' => $channel, 'apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey, 'catalog' => $catalog] = makeChannelWithApiKey();
    $visible = Product::factory()->published()->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach($visible->id, ['is_published' => true, 'published_at' => now()]);
    $unattached = Product::factory()->published()->create(['catalog_id' => $catalog->id]);

    $response = $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($visible->id)
        ->and($ids)->not->toContain($unattached->id);
});

it('excludes a product with is_published false on the channel pivot', function () {
    ['channel' => $channel, 'apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey, 'catalog' => $catalog] = makeChannelWithApiKey();
    $visible = Product::factory()->published()->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach($visible->id, ['is_published' => true, 'published_at' => now()]);
    $unpublished = Product::factory()->published()->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach($unpublished->id, ['is_published' => false]);

    $response = $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($visible->id)
        ->and($ids)->not->toContain($unpublished->id);
});

it('excludes a draft product even when its channel pivot is published', function () {
    ['channel' => $channel, 'apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey, 'catalog' => $catalog] = makeChannelWithApiKey();
    $visible = Product::factory()->published()->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach($visible->id, ['is_published' => true, 'published_at' => now()]);
    $draft = Product::factory()->create(['catalog_id' => $catalog->id, 'status' => ProductStatus::Draft]);
    $channel->products()->attach($draft->id, ['is_published' => true, 'published_at' => now()]);

    $response = $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($visible->id)
        ->and($ids)->not->toContain($draft->id);
});

it('excludes a soft-deleted product even when its channel pivot is published', function () {
    ['channel' => $channel, 'apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey, 'catalog' => $catalog] = makeChannelWithApiKey();
    $visible = Product::factory()->published()->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach($visible->id, ['is_published' => true, 'published_at' => now()]);
    $trashed = Product::factory()->published()->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach($trashed->id, ['is_published' => true, 'published_at' => now()]);
    $trashed->delete();

    $response = $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($visible->id)
        ->and($ids)->not->toContain($trashed->id);
});

it('paginates results at 50 per page', function () {
    ['channel' => $channel, 'apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey, 'catalog' => $catalog] = makeChannelWithApiKey();
    $products = Product::factory()->published()->count(55)->create(['catalog_id' => $catalog->id]);
    $channel->products()->attach(
        $products->mapWithKeys(fn (Product $product): array => [
            $product->id => ['is_published' => true, 'published_at' => now()],
        ])->all()
    );

    $response = $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])->assertOk();

    expect($response->json('data'))->toHaveCount(50);
    expect($response->json('meta.total'))->toBe(55);
});

it('returns 404 from show for a product invisible to this channel', function () {
    ['channel' => $channel, 'apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey, 'catalog' => $catalog] = makeChannelWithApiKey();
    $draft = Product::factory()->create(['catalog_id' => $catalog->id, 'status' => ProductStatus::Draft]);
    $channel->products()->attach($draft->id, ['is_published' => true, 'published_at' => now()]);

    $this->getJson($apiUrl.'/products/'.$draft->id, ['X-Api-Key' => $plainTextKey])
        ->assertNotFound();
});

it('returns the full resource shape for a visible product', function () {
    Storage::fake('public');
    ['channel' => $channel, 'apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey, 'catalog' => $catalog] = makeChannelWithApiKey();
    $attributeSet = AttributeSet::factory()->create(['catalog_id' => $catalog->id]);
    $category = Category::factory()->create(['catalog_id' => $catalog->id]);
    $product = Product::factory()->published()->create([
        'catalog_id' => $catalog->id,
        'attribute_set_id' => $attributeSet->id,
        'price' => 1999,
    ]);
    $product->categories()->attach($category->id);
    $product->addMedia(UploadedFile::fake()->image('photo.jpg'))->preservingOriginal()->toMediaCollection('images');
    $channel->products()->attach($product->id, ['is_published' => true, 'published_at' => now()]);

    $response = $this->getJson($apiUrl.'/products/'.$product->id, ['X-Api-Key' => $plainTextKey])->assertOk();

    $response->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.sku', $product->sku)
        ->assertJsonPath('data.price', 19.99)
        ->assertJsonPath('data.attribute_set', $attributeSet->name)
        ->assertJsonPath('data.categories.0', $category->name)
        ->assertJsonPath('data.channel.is_published', true)
        ->assertJsonCount(1, 'data.images');

    expect($response->json('data.images.0.url'))->not->toBeNull()
        ->and($response->json('data.images.0.thumb_url'))->not->toBeNull();
});
