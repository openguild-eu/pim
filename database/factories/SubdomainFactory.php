<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subdomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subdomain>
 */
class SubdomainFactory extends Factory
{
    protected $model = Subdomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'subdomain' => str($name)->slug(),
            'name' => $name,
        ];
    }
}
