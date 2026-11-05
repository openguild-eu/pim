<?php

declare(strict_types=1);

namespace Modules\Relations\Database\Seeders;

use App\Models\Catalog;
use Illuminate\Database\Seeder;
use Modules\Products\Models\Product;
use Modules\Relations\Models\ProductRelation;
use Modules\Relations\Models\ProductRelationType;

class RelationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Catalog $catalog): void
    {
        $products = Product::where('catalog_id', $catalog->id)->get();

        $sourceProducts = $products->random(min($products->count(), 8));

        foreach ($sourceProducts as $product) {
            foreach (ProductRelationType::cases() as $type) {
                $used = [];

                foreach (range(1, fake()->numberBetween(1, 2)) as $ignored) {
                    $candidates = $products
                        ->reject(fn (Product $candidate): bool => $candidate->id === $product->id || in_array($candidate->id, $used, true));

                    if ($candidates->isEmpty()) {
                        break;
                    }

                    $relatedProduct = $candidates->random();
                    $used[] = $relatedProduct->id;

                    ProductRelation::create([
                        'catalog_id' => $catalog->id,
                        'product_id' => $product->id,
                        'related_product_id' => $relatedProduct->id,
                        'type' => $type,
                    ]);
                }
            }
        }
    }
}
