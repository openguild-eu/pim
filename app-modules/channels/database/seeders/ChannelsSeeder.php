<?php

declare(strict_types=1);

namespace Modules\Channels\Database\Seeders;

use App\Models\Catalog;
use Illuminate\Database\Seeder;
use Modules\Channels\Models\ApiKey;
use Modules\Channels\Models\Channel;
use Modules\Products\Enums\ProductStatus;
use Modules\Products\Models\Product;

class ChannelsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Catalog $catalog): void
    {
        $publishedProducts = Product::where('catalog_id', $catalog->id)
            ->where('status', ProductStatus::Published)
            ->get();

        $channels = [
            Channel::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Online Store', 'slug' => 'online-store', 'is_active' => true]),
            Channel::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Marketplace', 'slug' => 'marketplace', 'is_active' => true]),
            Channel::factory()->create(['catalog_id' => $catalog->id, 'name' => 'Wholesale', 'slug' => 'wholesale', 'is_active' => true]),
        ];

        foreach ($channels as $channel) {
            $products = $publishedProducts->random(min($publishedProducts->count(), fake()->numberBetween(5, 10)));

            $position = 0;

            foreach ($products as $product) {
                $unpublished = $position < 2;
                $position++;

                $channel->products()->attach($product, [
                    'is_published' => ! $unpublished,
                    'published_at' => $unpublished ? null : now()->subDays(fake()->numberBetween(1, 60)),
                ]);
            }

            ApiKey::factory()->create(['channel_id' => $channel->id]);
        }
    }
}
