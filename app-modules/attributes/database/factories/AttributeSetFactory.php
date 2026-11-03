<?php

declare(strict_types=1);

namespace Modules\Attributes\Database\Factories;

use App\Models\Catalog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Attributes\Models\AttributeSet;

/**
 * @extends Factory<AttributeSet>
 */
class AttributeSetFactory extends Factory
{
    protected $model = AttributeSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'catalog_id' => Catalog::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
