<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Catalog;
use App\Models\Subdomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Catalog>
 */
class CatalogFactory extends Factory
{
    protected $model = Catalog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subdomain_id' => Subdomain::factory(),
            'name' => fake()->words(2, true),
        ];
    }
}
