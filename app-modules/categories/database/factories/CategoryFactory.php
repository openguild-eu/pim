<?php

declare(strict_types=1);

namespace Modules\Categories\Database\Factories;

use App\Models\Catalog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Categories\Models\Category;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'catalog_id' => Catalog::factory(),
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
