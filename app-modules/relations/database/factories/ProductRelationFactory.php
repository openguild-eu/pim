<?php

declare(strict_types=1);

namespace Modules\Relations\Database\Factories;

use App\Models\Catalog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Products\Models\Product;
use Modules\Relations\Models\ProductRelation;
use Modules\Relations\Models\ProductRelationType;

/**
 * @extends Factory<ProductRelation>
 */
class ProductRelationFactory extends Factory
{
    protected $model = ProductRelation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_id' => Catalog::factory(),
            'product_id' => Product::factory(),
            'related_product_id' => Product::factory(),
            'type' => ProductRelationType::Related,
            'position' => 0,
        ];
    }
}
