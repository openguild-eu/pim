<?php

declare(strict_types=1);

namespace Modules\Products\Database\Factories;

use App\Models\Catalog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Products\Enums\ProductStatus;
use Modules\Products\Models\Product;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'catalog_id' => Catalog::factory(),
            'attribute_set_id' => null,
            'sku' => fake()->unique()->bothify('SKU-####??'),
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => ProductStatus::Draft,
            'description' => fake()->paragraph(),
            'price' => fake()->numberBetween(100, 100000),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Published]);
    }
}
